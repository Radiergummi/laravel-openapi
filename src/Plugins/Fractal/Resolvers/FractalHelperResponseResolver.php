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
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Contracts\Attributes\PrimaryResponseAuthoringAttribute;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponse;
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
 * Infers the primary response from Fractal invocation styles via bounded body scan.
 *
 * Four call shapes are recognised within the first {@see self::STATEMENT_LIMIT} statements:
 * `fractal()->item(…)`/`->collection(…)`, `Fractal::create()->item(…)`, the
 * `spatie/laravel-fractal` `$this->fractal->item(…)->transformWith(new T()|T::class)` builder,
 * and an injected `Manager` fed a `new Item(…)`/`new Collection(…)`. In the chained shapes the
 * transformer may come from the `item()`/`collection()` second argument or, failing that, a
 * separate `->transformWith(…)` link. Classes are matched by FQCN string so the plugin never
 * depends on the packages being installed. Actions carrying a
 * {@see PrimaryResponseAuthoringAttribute} are skipped. The bare two-argument
 * `fractal($data, new T())` is refused: item vs collection is not statically knowable.
 */
#[Scoped]
final readonly class FractalHelperResponseResolver implements PrimaryResponseResolver
{
    public const int STATEMENT_LIMIT = 10;

    private const string HELPER_FUNCTION = 'fractal';

    /** The `$this->fractal` property name accepted as a `spatie/laravel-fractal` builder root. */
    private const string FRACTAL_PROPERTY = 'fractal';

    /** FQCN strings accepted as the `Fractal::create()` entrypoint. */
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

    private const string TRANSFORM_WITH_METHOD = 'transformWith';

    /** `new` serializer FQCN → modelled enum case. An unrecognised serializer refuses. */
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
    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?PrimaryResponse
    {
        $method = $descriptor->method;

        if ($method === null || !$this->returnTypeAllowsBodyScan($method)) {
            return null;
        }

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

        return PrimaryResponse::of(new OA\Response([
            'response' => '200',
            'description' => 'OK',
            'content' => [$mediaType->schema($envelope)],
        ]));
    }

    // region Call-shape matching

    /**
     * Whether the declared return type leaves room for a body scan: untyped, a builtin, or an
     * HTTP response class. Any other named type is owned by signature resolvers; union and
     * intersection types are refused.
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
     * The first recognised Fractal call shape within the scanned statements, or null.
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

    /** @param list<Node\Stmt> $statements */
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
     * Matches a chained `fractal()->item(…)` / `Fractal::create()->collection(…)` /
     * `$this->fractal->item(…)->transformWith(…)` shape. Only the `fractal()` helper, the
     * Fractalistic facade, or the `$this->fractal` builder property qualify as the root,
     * preventing false positives on unrelated services with the same method names. The
     * transformer is read from the `item()`/`collection()` second argument, falling back to a
     * separate `->transformWith(…)` link when that argument is absent.
     */
    private function matchChainedShape(Expr $expression): ?FractalCallShape
    {
        $serializer = Serializer::DataArray;
        $transformerFromChain = null;

        $node = $expression;

        // The fluent chain nests outermost-first, so `->transformWith(…)` (written after
        // `item()`/`collection()`) is visited before the `item`/`collection` link: capture here,
        // use below, in a single pass.
        while ($node instanceof MethodCall && $node->name instanceof Node\Identifier) {
            $methodName = $node->name->toString();

            if ($methodName === self::SERIALIZE_WITH_METHOD) {
                $serializer = $this->serializerFrom($node->args);
            }

            if ($methodName === self::TRANSFORM_WITH_METHOD) {
                $transformerFromChain = $this->transformerArgument($node->args, 0);
            }

            if ($methodName === self::ITEM_METHOD || $methodName === self::COLLECTION_METHOD) {
                if (!$this->isFractalEntrypoint($node->var)) {
                    return null;
                }

                $transformerClass = $this->transformerArgument($node->args, 1) ?? $transformerFromChain;

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
     * The serializer from a `->serializeWith(new …Serializer())` call, or null if unrecognised.
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

    /**
     * Whether the expression is the `fractal()` helper call, the `Fractal::create()` facade call,
     * or the `$this->fractal` builder property.
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

        if ($receiver instanceof PropertyFetch) {
            return $receiver->var instanceof Variable
                && $receiver->var->name === 'this'
                && $receiver->name instanceof Node\Identifier
                && $receiver->name->toString() === self::FRACTAL_PROPERTY;
        }

        return false;
    }

    private function isFacadeClass(string $class): bool
    {
        return in_array($class, self::FACADE_CLASSES, true);
    }

    /**
     * The class at the given argument index when it is a literal `new T(…)` or `T::class`.
     * Variables, method calls, and properties are not statically knowable.
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
     * Matches the injected-Manager shape: exactly one `new Item(…)` / `new Collection(…)`.
     * Two or more are ambiguous and refused.
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

    // endregion

    // region Guards & logging

    private function isResourceClass(Node $class): bool
    {
        return $class instanceof Name
            && ($class->toString() === self::RESOURCE_ITEM_CLASS
                || $class->toString() === self::RESOURCE_COLLECTION_CLASS);
    }

    /**
     * Whether the transformer has documentable fields.
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
