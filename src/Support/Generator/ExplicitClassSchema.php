<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Attributes\RawSchema;
use ReflectionClass;

use function array_filter;
use function array_keys;
use function array_values;
use function implode;
use function in_array;
use function sprintf;
use function str_starts_with;

use const ARRAY_FILTER_USE_KEY;

/**
 * Reads a class-level `#[RawSchema]` attribute and converts it into an `OA\Schema`, bounded
 * to the keyword set swagger-php can serialise. Unsupported keywords are dropped with a warning.
 *
 * @internal
 */
#[Scoped]
final readonly class ExplicitClassSchema
{
    /**
     * Keywords swagger-php's `OA\Schema` models. `#[RawSchema]` may also use `x-*` extensions;
     * anything else is dropped and flagged by the `schema.raw-keyword-unsupported` lint rule.
     * Absent: `if`/`then`/`else`, `dependentRequired`/`dependentSchemas` (not modelled by
     * `OA\Schema`), and `dependencies` (draft-07; split into `dependent*` in JSON Schema 2020-12).
     *
     * @var list<string>
     */
    public const array ACCEPTED_KEYWORDS = [
        'type',
        'enum',
        'const',
        'not',
        'allOf',
        'anyOf',
        'oneOf',
        'properties',
        'required',
        'additionalProperties',
        'patternProperties',
        'propertyNames',
        'contains',
        'items',
        'minimum',
        'maximum',
        'exclusiveMinimum',
        'exclusiveMaximum',
        'multipleOf',
        'minLength',
        'maxLength',
        'pattern',
        'minItems',
        'maxItems',
        'minProperties',
        'maxProperties',
        'uniqueItems',
        'default',
        'title',
        'description',
        'format',
        'example',
        'deprecated',
        'readOnly',
        'writeOnly',
        'nullable',
        'discriminator',
    ];

    public function __construct(
        private LoggerInterface $logger,
    ) {}

    /**
     * Returns the `#[RawSchema]` instance declared on the class, or null when absent.
     *
     * @template T of object
     *
     * @param ReflectionClass<T> $class
     */
    public function read(ReflectionClass $class): ?RawSchema
    {
        $attributes = $class->getAttributes(RawSchema::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    /**
     * @template T of object
     *
     * @param ReflectionClass<T> $class
     */
    public function toSchema(RawSchema $attribute, ReflectionClass $class): OA\Schema
    {
        $unsupported = self::unsupportedKeywords($attribute->schema);

        if ($unsupported !== []) {
            $this->logger->warning(
                sprintf(
                    'ExplicitClassSchema: dropping unsupported keyword(s) [%s] from #[RawSchema] on %s '
                    . '— swagger-php cannot serialise them.',
                    implode(', ', $unsupported),
                    $class->getName(),
                ),
                ['class' => $class->getName(), 'keywords' => $unsupported],
            );
        }

        $definition = array_filter(
            $attribute->schema,
            static fn(string $key): bool => self::isAccepted($key),
            ARRAY_FILTER_USE_KEY,
        );

        return SchemaFromArrayDefinition::build($definition);
    }

    /**
     * Returns unsupported keywords from a `#[RawSchema]` definition. Pure so the lint rule can reuse it.
     *
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    public static function unsupportedKeywords(array $schema): array
    {
        return array_values(
            array_filter(
                array_keys($schema),
                static fn(string $key): bool => !self::isAccepted($key),
            ),
        );
    }

    private static function isAccepted(string $keyword): bool
    {
        return in_array($keyword, self::ACCEPTED_KEYWORDS, true) || str_starts_with($keyword, 'x-');
    }
}
