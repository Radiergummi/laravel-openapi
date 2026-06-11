<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Support;

use Closure;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Contracts\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerInclude;
use Radiergummi\OpenApi\Plugins\Fractal\Resolvers\TransformerRefSchemaResolver;
use Radiergummi\OpenApi\Support\Extraction\FieldReferenceProperty;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use ReflectionClass;
use ReflectionException;

use function class_exists;
use function implode;
use function sprintf;

/**
 * Builds the `OA\Schema` (type: object) for a Fractal transformer and registers it as a
 * component. Two sources compose per field: class-level `#[TransformerField]` /
 * `#[TransformerInclude]` attributes (always authoritative for the fields they name) and the
 * Tier-1 `transform()` literal read by {@see TransformerTransformReader} — inferred fields the
 * attributes do not cover follow them in literal order. A dynamic `transform()` body degrades
 * to the attribute-declared shape with a generation-log note when that leaves the schema empty.
 *
 * Nested transformer-shaped field types (classes carrying `#[TransformerField]`)
 * recurse through {@see build()} directly; other class-string types resolve via
 * the injected resolver factory — a `Closure` returning the registered
 * resolvers minus this plugin's own {@see TransformerRefSchemaResolver}. The
 * list is lazy by design: the eager equivalent forms a cross-plugin
 * construction cycle with `SchemaFromResource`, because each plugin's own
 * `RefSchemaResolver` references the other plugin's `SchemaFrom*` builder
 * (filtering out only the same-plugin resolver is not enough). Invoking the
 * factory at use time lets the container finish constructing both sides first.
 */
final readonly class SchemaFromTransformer
{
    /**
     * @param Closure(): list<RefSchemaResolver> $refSchemaResolvers Lazy factory returning the registered ref
     *                                                               resolvers, minus this plugin's own.
     */
    public function __construct(
        private ComponentSchemaRegistry $registry,
        private Closure $refSchemaResolvers,
        private TransformerTransformReader $transformReader,
        private LoggerInterface $logger,
    ) {}

    /**
     * Registers the transformer and returns its qualified `$ref` string.
     *
     * @param class-string $transformerClass
     *
     * @throws ReflectionException
     */
    public function buildRef(string $transformerClass): string
    {
        return $this->registry->qualifyKey($this->build($transformerClass));
    }

    /**
     * Registers the transformer as a component schema and returns its key.
     *
     * @param class-string $transformerClass
     *
     * @throws ReflectionException
     */
    public function build(string $transformerClass): string
    {
        return $this->registry->buildOnce(
            $transformerClass,
            fn(): OA\Schema => $this->buildSchema($transformerClass),
        );
    }

    /**
     * @param class-string $transformerClass
     *
     * @throws ReflectionException
     */
    private function buildSchema(string $transformerClass): OA\Schema
    {
        $reflection = new ReflectionClass($transformerClass);

        /** @var list<OA\Property> $properties */
        $properties = [];

        /** @var list<string> $required */
        $required = [];

        /** @var array<string, true> $seenNames */
        $seenNames = [];

        foreach ($reflection->getAttributes(TransformerField::class) as $attribute) {
            $field = $attribute->newInstance();
            $seenNames[$field->name] = true;
            $properties[] = $this->buildFieldProperty($field);

            if (!$field->conditional) {
                $required[] = $field->name;
            }
        }

        foreach ($reflection->getAttributes(TransformerInclude::class) as $attribute) {
            $include = $attribute->newInstance();
            $seenNames[$include->name] = true;
            $properties[] = $this->buildIncludeProperty($include);

            if ($include->default) {
                $required[] = $include->name;
            }
        }

        $inferred = $this->transformReader->read($transformerClass);

        if ($inferred !== null) {
            /** @var list<string> $unconstrainedKeys */
            $unconstrainedKeys = [];

            foreach ($inferred as $field) {
                // A #[TransformerField] / #[TransformerInclude] wins per field.
                if (isset($seenNames[$field->name])) {
                    continue;
                }

                $seenNames[$field->name] = true;
                $properties[] = $field->property;
                $required[] = $field->name;

                if ($field->unconstrained) {
                    $unconstrainedKeys[] = $field->name;
                }
            }

            if ($unconstrainedKeys !== []) {
                $this->logger->notice(sprintf(
                    'transform() of %s has keys whose values could not be statically typed (%s); '
                    . 'they are documented as unconstrained properties. '
                    . 'Declare a #[TransformerField] for each to document its type.',
                    $transformerClass,
                    implode(', ', $unconstrainedKeys),
                ));
            }
        } elseif ($seenNames === [] && $this->transformReader->declaresTransform($transformerClass)) {
            $this->logger->notice(sprintf(
                'transform() of %s is not a single statically-readable return-array literal and '
                . 'no #[TransformerField] attributes are declared; the response schema stays empty.',
                $transformerClass,
            ));
        }

        $props = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $props['required'] = $required;
        }

        return new OA\Schema($props);
    }

    /**
     * @throws ReflectionException
     */
    private function buildFieldProperty(TransformerField $field): OA\Property
    {
        $type = $field->type;

        if ($type !== null && class_exists($type)) {
            return FieldReferenceProperty::build(
                $field->name,
                $field->descriptor()->description,
                $this->resolveClassRef($type),
            );
        }

        $property = new OA\Property(['property' => $field->name]);
        $field->descriptor()->applyTo($property);

        return $property;
    }

    /**
     * @param class-string $class
     *
     * @throws ReflectionException
     */
    private function resolveClassRef(string $class): ?string
    {
        if (
            new ReflectionClass($class)->getAttributes(TransformerField::class) !== []
            || ($this->transformReader->isTransformerSubclass($class) && $this->transformReader->read($class) !== null)
        ) {
            return $this->buildRef($class);
        }

        foreach (($this->refSchemaResolvers)() as $resolver) {
            $ref = $resolver->resolveRef($class);

            if ($ref !== null) {
                return $ref;
            }
        }

        return null;
    }

    /**
     * @throws ReflectionException
     */
    private function buildIncludeProperty(TransformerInclude $include): OA\Property
    {
        $ref = $include->transformer !== null && class_exists($include->transformer)
            ? $this->resolveClassRef($include->transformer)
            : null;

        return FieldReferenceProperty::build($include->name, null, $ref);
    }
}
