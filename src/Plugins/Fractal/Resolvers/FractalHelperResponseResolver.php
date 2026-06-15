<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Resolvers;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Override;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Contracts\Attributes\PrimaryResponseAuthoringAttribute;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\Support\FractalEnvelopeFactory;
use Radiergummi\OpenApi\Plugins\Fractal\Support\SchemaFromTransformer;
use Radiergummi\OpenApi\Plugins\Fractal\Support\Serializer;
use Radiergummi\OpenApi\Plugins\Fractal\Support\TransformerTransformReader;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;

use function class_exists;
use function count;
use function in_array;
use function is_a;
use function sprintf;

/**
 * Infers the primary response from the dominant Fractal invocation styles — a Tier-1 bounded scan
 * (epic #5, issue #263), the deferred binding half of #13.
 *
 * Three call shapes are recognised within the first {@see self::STATEMENT_LIMIT} top-level
 * statements:
 *
 * - the `fractal()` helper chained into `->item(…)` / `->collection(…)`,
 * - the `Spatie\Fractalistic\Fractal` facade chained the same way (`Fractal::create()->item(…)`),
 * - an injected `League\Fractal\Manager` fed a `new Item(…)` / `new Collection(…)` resource.
 *
 * The transformer comes from the resource/chain-link argument when it is a literal `new T()` or
 * `T::class` naming a concrete `TransformerAbstract` subclass; `item` / `Item` binds the single
 * envelope, `collection` / `Collection` the collection envelope. A trailing `->serializeWith(new
 * …Serializer())` maps onto the modelled {@see Serializer} cases. All Fractal classes are matched
 * by FQCN string, so the plugin never depends on the packages being installed.
 *
 * Degradation contract (mirrors {@see EntityTransformerResponseResolver}): the bare two-argument
 * helper `fractal($data, new T())` is refused — item vs collection is not statically knowable from
 * the first argument. A variable/dynamic transformer, an unrecognised serializer, or a transformer
 * with no documentable fields all refuse with a generation-log note. An action carrying a
 * {@see PrimaryResponseAuthoringAttribute} (`#[FractalResponse]`) is never scanned, and the
 * return-type guard keeps the scan away from signatures the Tier-0 resolvers already own.
 */
#[Scoped]
final readonly class FractalHelperResponseResolver implements PrimaryResponseResolver
{
    public const int STATEMENT_LIMIT = 10;

    private const string HELPER_FUNCTION = 'fractal';

    /**
     * The static entrypoint classes recognised for `Fractal::create()`: the Fractalistic base
     * class, the laravel-fractal subclass, and laravel-fractal's facade alias. Matched by FQCN
     * string so neither package need be installed.
     */
    private const array FACADE_CLASSES = [
        'Spatie\\Fractalistic\\Fractal',
        'Spatie\\Fractal\\Fractal',
        'Spatie\\Fractal\\Facades\\Fractal',
    ];

    private const string FACADE_FACTORY_METHOD = 'create';

    private const string ITEM_METHOD = 'item';

    private const string COLLECTION_METHOD = 'collection';

    private const string RESOURCE_ITEM_CLASS = 'League\\Fractal\\Resource\\Item';

    private const string RESOURCE_COLLECTION_CLASS = 'League\\Fractal\\Resource\\Collection';

    private const string SERIALIZE_WITH_METHOD = 'serializeWith';

    /**
     * `new` serializer FQCN → modelled enum case. An unrecognised serializer refuses.
     */
    private const array SERIALIZER_CLASSES = [
        'League\\Fractal\\Serializer\\DataArraySerializer' => Serializer::DataArray,
        'League\\Fractal\\Serializer\\ArraySerializer' => Serializer::ArraySerializer,
        'Spatie\\Fractalistic\\ArraySerializer' => Serializer::ArraySerializer,
        'League\\Fractal\\Serializer\\JsonApiSerializer' => Serializer::JsonApi,
    ];

    public function __construct(
        private MethodBodyScanner $scanner,
        private TransformerTransformReader $transformReader,
        private SchemaFromTransformer $schemaFromTransformer,
        private FractalEnvelopeFactory $envelopeFactory,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws ReflectionException
     */
    #[Override]
    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?OA\Response
    {
        $method = $descriptor->method;

        if ($method === null || !$this->returnTypeAllowsBodyScan($method)) {
            return null;
        }

        // An explicit authoring attribute always wins; #[FractalResponse] is consumed by
        // FractalResponseResolver earlier in the chain.
        if ($descriptor->declaresAttributeImplementing(PrimaryResponseAuthoringAttribute::class)) {
            return null;
        }

        $statements = $this->scanner->firstStatements($method, self::STATEMENT_LIMIT);

        if ($statements === []) {
            return null;
        }

        $match = $this->matchCallShape($statements);

        if ($match === null) {
            return null;
        }

        if (!$this->transformReader->isTransformerSubclass($match->transformerClass)) {
            return null;
        }

        if (!$this->hasDocumentableFields($match->transformerClass)) {
            $this->note(
                $method,
                sprintf(
                    'but transformer %s declares no #[TransformerField] and yields no documentable fields',
                    $match->transformerClass,
                ),
            );

            return null;
        }

        $serializer = $match->serializer;

        if ($serializer === null) {
            $this->note($method, 'but the serializer it is configured with is not one of the modelled cases');

            return null;
        }

        $ref = $this->schemaFromTransformer->buildRef($match->transformerClass);
        $envelope = $match->collection
            ? $this->envelopeFactory->collection($ref, $serializer)
            : $this->envelopeFactory->single($ref, $serializer);
        $mediaType = $serializer === Serializer::JsonApi ? MediaType::JsonApi : MediaType::Json;

        return new OA\Response([
            'response' => '200',
            'description' => 'OK',
            'content' => [$mediaType->schema($envelope)],
        ]);
    }

    // region Call-shape matching

    /**
     * The first recognised Fractal call shape within the scanned statements, or null. The helper
     * and facade shapes resolve from the first top-level `return` expression; the injected-Manager
     * shape resolves from a `new Item(…)` / `new Collection(…)` resource anywhere in the
     * statements (a single one, or it is ambiguous and refused).
     *
     * @param list<Node\Stmt> $statements
     */
    private function matchCallShape(array $statements): ?FractalCallShape
    {
        $returnExpression = $this->firstReturnExpression($statements);

        if ($returnExpression !== null) {
            $chained = $this->matchChainedShape($returnExpression);

            if ($chained !== null) {
                return $chained;
            }
        }

        return $this->matchManagerResourceShape($statements);
    }

    /**
     * The expression of the first top-level `return` statement.
     *
     * @param list<Node\Stmt> $statements
     */
    private function firstReturnExpression(array $statements): ?Expr
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Return_) {
                return $statement->expr;
            }
        }

        return null;
    }

    /**
     * Walks a method-call chain from its outer terminal links inward, collecting the serializer
     * named by `->serializeWith(…)`, until it reaches an `->item(…)` / `->collection(…)` link
     * whose chain root is the `fractal()` helper or the Fractalistic facade. Any other root — an
     * unrelated service or query builder exposing the same method names — does not match.
     */
    private function matchChainedShape(Expr $expression): ?FractalCallShape
    {
        $serializer = Serializer::DataArray;

        $node = $expression;

        while ($node instanceof MethodCall && $node->name instanceof Node\Identifier) {
            $methodName = $node->name->toString();

            if ($methodName === self::SERIALIZE_WITH_METHOD) {
                $serializer = $this->serializerFrom($node->args);
            }

            if ($methodName === self::ITEM_METHOD || $methodName === self::COLLECTION_METHOD) {
                if (!$this->isFractalEntrypoint($node->var)) {
                    return null;
                }

                $transformerClass = $this->transformerArgument($node->args, 1);

                if ($transformerClass === null) {
                    return null;
                }

                return new FractalCallShape(
                    $transformerClass,
                    collection: $methodName === self::COLLECTION_METHOD,
                    serializer: $serializer,
                );
            }

            $node = $node->var;
        }

        return null;
    }

    /**
     * Whether the receiver of an `->item(…)` / `->collection(…)` link is a Fractal entrypoint:
     * the `fractal()` helper call or the `Fractal::create()` / facade static call. Asserting the
     * concrete root is what keeps the same method names on unrelated receivers from matching.
     */
    private function isFractalEntrypoint(Expr $receiver): bool
    {
        if ($receiver instanceof FuncCall) {
            return $receiver->name instanceof Name
                && $receiver->name->toString() === self::HELPER_FUNCTION;
        }

        if ($receiver instanceof StaticCall) {
            return $receiver->class instanceof Name
                && $this->isFacadeClass($receiver->class->toString())
                && $receiver->name instanceof Node\Identifier
                && $receiver->name->toString() === self::FACADE_FACTORY_METHOD;
        }

        return false;
    }

    private function isFacadeClass(string $class): bool
    {
        return in_array($class, self::FACADE_CLASSES, true);
    }

    /**
     * The single `new Item(…)` / `new Collection(…)` resource construction across the scanned
     * statements (the injected-Manager shape). Two or more resource constructions are ambiguous
     * and refused.
     *
     * @param list<Node\Stmt> $statements
     */
    private function matchManagerResourceShape(array $statements): ?FractalCallShape
    {
        $resources = new NodeFinder()->find(
            $statements,
            fn(Node $node): bool => $node instanceof New_ && $this->isResourceClass($node->class),
        );

        if (count($resources) !== 1) {
            return null;
        }

        /** @var New_ $resource */
        $resource = $resources[0];

        /** @var Name $resourceClass */
        $resourceClass = $resource->class;
        $transformerClass = $this->transformerArgument($resource->args, 1);

        if ($transformerClass === null) {
            return null;
        }

        return new FractalCallShape(
            $transformerClass,
            collection: $resourceClass->toString() === self::RESOURCE_COLLECTION_CLASS,
            serializer: Serializer::DataArray,
        );
    }

    private function isResourceClass(Node $class): bool
    {
        return $class instanceof Name
            && ($class->toString() === self::RESOURCE_ITEM_CLASS
                || $class->toString() === self::RESOURCE_COLLECTION_CLASS);
    }

    /**
     * The class named by the argument at the given index, when it is a literal `new T(…)` or
     * `T::class`. Anything else (a variable, a method call, a property) is not statically knowable.
     *
     * @param array<int, Arg|Node\VariadicPlaceholder> $args
     *
     * @return null|class-string
     */
    private function transformerArgument(array $args, int $index): ?string
    {
        $argument = $args[$index] ?? null;

        if (!$argument instanceof Arg) {
            return null;
        }

        $value = $argument->value;

        $name = match (true) {
            $value instanceof New_ && $value->class instanceof Name => $value->class->toString(),
            $value instanceof ClassConstFetch
                && $value->class instanceof Name
                && $value->name instanceof Node\Identifier
                && $value->name->toString() === 'class' => $value->class->toString(),
            default => null,
        };

        return $name !== null && class_exists($name) ? $name : null;
    }

    /**
     * The modelled serializer named by a `->serializeWith(new …Serializer())` argument, or null
     * when the argument names a serializer outside the three modelled cases — which refuses
     * rather than documenting the wrong envelope.
     *
     * @param array<int, Arg|Node\VariadicPlaceholder> $args
     */
    private function serializerFrom(array $args): ?Serializer
    {
        $argument = $args[0] ?? null;

        if (!$argument instanceof Arg || !$argument->value instanceof New_) {
            return null;
        }

        $class = $argument->value->class;

        if (!$class instanceof Name) {
            return null;
        }

        return self::SERIALIZER_CLASSES[$class->toString()] ?? null;
    }

    // endregion

    // region Guards & logging

    /**
     * Whether the declared return type leaves room for a body scan: untyped, a builtin, or an
     * HTTP response class. Any other named type is Tier-0 territory the signature resolvers own;
     * union and intersection types are refused rather than arbitrated.
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
     * Whether the transformer yields any documentable fields — declared `#[TransformerField]`
     * attributes or a readable `transform()` literal. Binding an envelope around a genuinely empty
     * item schema would document nothing while claiming authority over the response.
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

    private function note(ReflectionMethod $method, string $reason): void
    {
        $this->logger->notice(
            sprintf(
                'fractal() helper / facade / Manager response in %s::%s %s; no response inferred. '
                . 'Annotate the action with #[FractalResponse] to document it.',
                $method->getDeclaringClass()->getName(),
                $method->getName(),
                $reason,
            ),
        );
    }

    // endregion
}
