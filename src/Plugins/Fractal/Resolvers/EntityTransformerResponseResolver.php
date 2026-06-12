<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Resolvers;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Return_;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Contracts\Attributes\PrimaryResponseAuthoringAttribute;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\Support\FractalEnvelopeFactory;
use Radiergummi\OpenApi\Plugins\Fractal\Support\SchemaFromTransformer;
use Radiergummi\OpenApi\Plugins\Fractal\Support\TransformerTransformReader;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\MethodBody\ConditionalContextPolicy;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\StatementNodeFinder;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;

use function class_exists;
use function is_a;
use function is_string;
use function sprintf;

/**
 * Infers the primary response from the `$this->itemResponse(…)` / `$this->listResponse(…)`
 * base-controller convention (InvoiceNinja's `BaseController`) — a Tier-1 bounded scan
 * (epic #5, issue #13).
 *
 * The bounded case: within the first {@see self::STATEMENT_LIMIT} top-level statements, a
 * top-level `return $this->itemResponse(…)` or `return $this->listResponse(…)` appears (the
 * first one wins), and the controller's `$entity_transformer` property *default* (Tier-0,
 * {@see \ReflectionProperty::getDefaultValue()}) names a concrete `TransformerAbstract`
 * subclass. The transformer's schema comes from {@see SchemaFromTransformer} (attributes +
 * the `transform()` literal); `itemResponse` wraps it in the single `{data}` envelope,
 * `listResponse` in the collection envelope — `DataArraySerializer`, Fractal's default. The
 * method names are a literal whitelist by design; there is no configurable convention knob.
 *
 * Degradation contract: a method that reassigns `$entity_transformer` anywhere in the scanned
 * statements refuses with a note — the property default is no longer the honest answer. A
 * reassignment inside a *called* helper is invisible to the bounded scan (following calls is
 * Tier-2 dataflow), so the property default is documented — a deliberate boundary, not an
 * oversight. A matched call whose transformer cannot be resolved (no usable default, or no
 * documentable fields) is noted; a method without the call shape is skipped silently — most apps never use
 * this convention. An action carrying a {@see PrimaryResponseAuthoringAttribute}
 * (`#[FractalResponse]`, `#[ResponseResource]`) is never scanned: explicit authoring always
 * wins. The return-type guard keeps the scan away from actions whose signature already carries
 * schema information, so the Tier-0 resolvers stay authoritative regardless of chain order.
 */
#[Scoped]
final readonly class EntityTransformerResponseResolver implements PrimaryResponseResolver
{
    public const int STATEMENT_LIMIT = 10;

    private const string ITEM_RESPONSE_METHOD = 'itemResponse';

    private const string LIST_RESPONSE_METHOD = 'listResponse';

    private const string ENTITY_TRANSFORMER_PROPERTY = 'entity_transformer';

    private StatementNodeFinder $statementNodeFinder;

    public function __construct(
        private MethodBodyScanner $scanner,
        private TransformerTransformReader $transformReader,
        private SchemaFromTransformer $schemaFromTransformer,
        private FractalEnvelopeFactory $envelopeFactory,
        private LoggerInterface $logger,
    ) {
        $this->statementNodeFinder = new StatementNodeFinder();
    }

    /**
     * @throws ReflectionException
     */
    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?OA\Response
    {
        $method = $descriptor->method;
        $controller = $descriptor->controller;

        if ($method === null || $controller === null || !$this->returnTypeAllowsBodyScan($method)) {
            return null;
        }

        // An explicit authoring attribute always wins (epic #5); #[FractalResponse] in
        // particular is consumed by FractalResponseResolver earlier in the chain.
        if ($descriptor->declaresAttributeImplementing(PrimaryResponseAuthoringAttribute::class)) {
            return null;
        }

        $statements = $this->scanner->firstStatements($method, self::STATEMENT_LIMIT);

        if ($statements === []) {
            return null;
        }

        $call = $this->findEnvelopeCall($statements);

        if ($call === null) {
            return null;
        }

        if ($this->reassignsEntityTransformer($statements)) {
            $this->note(
                $method,
                'reassigns $entity_transformer in the method body, so the property default is not the honest transformer',
            );

            return null;
        }

        $transformerClass = $this->entityTransformerDefault($controller);

        if ($transformerClass === null) {
            $this->note($method, 'but $entity_transformer has no concrete transformer class default');

            return null;
        }

        if (!$this->hasDocumentableFields($transformerClass)) {
            // Covers both an unreadable transform() body and a readable-but-empty literal.
            $this->note(
                $method,
                sprintf(
                    'but transformer %s declares no #[TransformerField] and yields no documentable fields',
                    $transformerClass,
                ),
            );

            return null;
        }

        $ref = $this->schemaFromTransformer->buildRef($transformerClass);

        $envelope = $call->name instanceof Identifier && $call->name->toString() === self::LIST_RESPONSE_METHOD
            ? $this->envelopeFactory->collection($ref)
            : $this->envelopeFactory->single($ref);

        return new OA\Response([
            'response' => '200',
            'description' => 'OK',
            'content' => [MediaType::Json->schema($envelope)],
        ]);
    }

    // region Call-shape matching

    /**
     * Whether the declared return type leaves room for a body scan: untyped, a builtin, or an
     * HTTP response class. Any other named type is Tier-0 territory the signature resolvers
     * own; union and intersection types are refused rather than arbitrated.
     */
    private function returnTypeAllowsBodyScan(ReflectionMethod $method): bool
    {
        $returnType = $method->getReturnType();

        if ($returnType === null) {
            return true;
        }

        if (!$returnType instanceof ReflectionNamedType) {
            return false;
        }

        return $returnType->isBuiltin()
            || is_a($returnType->getName(), HttpFoundationResponse::class, true);
    }

    /**
     * The first top-level `return $this->itemResponse(…)` / `$this->listResponse(…)` call.
     * Only a return whose expression *is* the call matches — wrapping or chaining means the
     * response is no longer the helper's envelope.
     *
     * @param list<Node\Stmt> $statements
     */
    private function findEnvelopeCall(array $statements): ?MethodCall
    {
        foreach ($statements as $statement) {
            if (!$statement instanceof Return_) {
                continue;
            }

            $expression = $statement->expr;

            if (
                $expression instanceof MethodCall
                && !$expression->isFirstClassCallable()
                && $expression->name instanceof Identifier
                && ($expression->name->toString() === self::ITEM_RESPONSE_METHOD
                    || $expression->name->toString() === self::LIST_RESPONSE_METHOD)
                && $expression->var instanceof Variable
                && $expression->var->name === 'this'
            ) {
                return $expression;
            }
        }

        return null;
    }

    // endregion

    // region Transformer resolution

    /**
     * Whether any scanned statement — including conditional contexts — assigns to
     * `$this->entity_transformer`: once the method switches transformers at runtime, the
     * property default must not be documented.
     *
     * @param list<Node\Stmt> $statements
     */
    private function reassignsEntityTransformer(array $statements): bool
    {
        $assignment = $this->statementNodeFinder->findFirst(
            $statements,
            ConditionalContextPolicy::IncludeConditionalContexts,
            static fn(Node $node): bool
                => $node instanceof Assign
                && $node->var instanceof PropertyFetch
                && $node->var->var instanceof Variable
                && $node->var->var->name === 'this'
                && $node->var->name instanceof Identifier
                && $node->var->name->toString() === self::ENTITY_TRANSFORMER_PROPERTY,
        );

        return $assignment !== null;
    }

    private function note(ReflectionMethod $method, string $reason): void
    {
        $this->logger->notice(
            sprintf(
                'itemResponse()/listResponse() call in %s::%s %s; no response inferred. '
                . 'Annotate the action with #[FractalResponse] to document it.',
                $method->getDeclaringClass()->getName(),
                $method->getName(),
                $reason,
            ),
        );
    }

    // endregion

    // region Guards & logging

    /**
     * The controller's `$entity_transformer` property default, when it names a concrete
     * `TransformerAbstract` subclass (Tier-0 — the declared default, never a runtime value).
     *
     * @param ReflectionClass<object> $controller
     *
     * @return null|class-string
     *
     * @throws ReflectionException
     */
    private function entityTransformerDefault(ReflectionClass $controller): ?string
    {
        if (!$controller->hasProperty(self::ENTITY_TRANSFORMER_PROPERTY)) {
            return null;
        }

        $default = $controller->getProperty(self::ENTITY_TRANSFORMER_PROPERTY)->getDefaultValue();

        if (!is_string($default) || !class_exists($default)) {
            return null;
        }

        return $this->transformReader->isTransformerSubclass($default) ? $default : null;
    }

    /**
     * Whether the transformer yields any documentable fields — declared `#[TransformerField]`
     * attributes or a readable `transform()` literal. Binding an envelope around a genuinely
     * empty item schema would document nothing while claiming authority over the response.
     *
     * @param class-string $transformerClass
     *
     * @throws ReflectionException
     */
    private function hasDocumentableFields(string $transformerClass): bool
    {
        if (new ReflectionClass($transformerClass)->getAttributes(TransformerField::class) !== []) {
            return true;
        }

        $inferred = $this->transformReader->read($transformerClass);

        return $inferred !== null && $inferred !== [];
    }

    // endregion
}
