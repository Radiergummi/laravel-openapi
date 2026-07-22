<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Resolvers;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Override;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Return_;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Contracts\Attributes\PrimaryResponseAuthoringAttribute;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponse;
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
 * Infers the primary response from `$this->itemResponse(…)` / `$this->listResponse(…)`
 * base-controller calls (InvoiceNinja's `BaseController` convention).
 *
 * Reads the controller's `$entity_transformer` property default to identify the transformer.
 * Degrades when the method reassigns `$entity_transformer`, the transformer has no
 * documentable fields, or a {@see PrimaryResponseAuthoringAttribute} is present.
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
    #[Override]
    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?PrimaryResponse
    {
        $method = $descriptor->method;
        $controller = $descriptor->controller;

        if ($method === null || $controller === null || !$this->returnTypeAllowsBodyScan($method)) {
            return null;
        }

        // An explicit authoring attribute always wins; #[FractalResponse] in particular is
        // consumed by FractalResponseResolver earlier in the chain.
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

        return PrimaryResponse::of(new OA\Response([
            'response' => '200',
            'description' => 'OK',
            'content' => [MediaType::Json->schema($envelope)],
        ]));
    }

    // region Call-shape matching

    /**
     * Whether the declared return type leaves room for a body scan: untyped, a builtin, or an
     * HTTP response class. Union/intersection types are refused rather than arbitrated.
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
     * The first unconditional `return $this->itemResponse(…)` / `$this->listResponse(…)` call.
     * Only a return whose expression is the direct call matches; chaining or wrapping is not matched.
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
     * Whether any statement (including conditional contexts) assigns to `$this->entity_transformer`.
     * If so, the property default is no longer authoritative.
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
     * The declared `$entity_transformer` default when it names a concrete `TransformerAbstract` subclass.
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
     * Whether the transformer has any documentable fields: `#[TransformerField]` attributes
     * or a readable `transform()` literal.
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
