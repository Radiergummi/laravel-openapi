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
 * Reads a class-level `#[RawSchema]` attribute and turns its literal definition into an
 * `OA\Schema`, bounded to the keyword set swagger-php can serialise.
 *
 * The plugin extractors (Spatie Data, API Resource, FormRequest) each call {@see read()} at the
 * top of their `buildSchema()` and, when it returns an attribute, {@see toSchema()} to produce the
 * component body — short-circuiting all convention inference. Keeping that logic here means the
 * three plugins share one keyword boundary and one degrade-and-log behaviour without `Support/`
 * touching any plugin type.
 *
 * @internal
 */
#[Scoped]
final readonly class ExplicitClassSchema
{
    /**
     * Keywords swagger-php's `OA\Schema` models and serialises. A `#[RawSchema]` may use these
     * plus any `x-*` extension key. Everything else is dropped at build time (and flagged by the
     * `schema.raw-keyword-unsupported` lint rule); this constant is the single source of truth for
     * both paths.
     *
     * Notably absent: `if`/`then`/`else`, `dependentRequired`/`dependentSchemas` (the #140 wall —
     * `OA\Schema` does not model them), and `dependencies` (the draft-07 spelling; OpenAPI 3.1 uses
     * JSON-Schema 2020-12, where it is split into the two `dependent*` keywords — accepting only the
     * deprecated form would be dialect-inconsistent).
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
     * Builds the literal component schema, dropping (and logging) any keyword swagger-php cannot
     * serialise so a non-linted run still produces a valid document.
     *
     * @template T of object
     *
     * @param ReflectionClass<T> $class The declaring class, for the log context.
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
     * Returns the keywords in a `#[RawSchema]` definition that swagger-php cannot serialise.
     * Pure (no logging) so the lint rule can reuse it.
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
