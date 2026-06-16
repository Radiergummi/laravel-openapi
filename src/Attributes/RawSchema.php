<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use Attribute;

/**
 * Replaces a class's inferred component schema body with a literal JSON Schema. Placed on a
 * payload class the generator would otherwise introspect (a Spatie Data class, an API Resource,
 * or a FormRequest), it short-circuits that inference entirely: the array given here becomes the
 * component body verbatim, and any field-level attributes on the class are ignored.
 *
 * ```php
 * #[OpenApi\RawSchema([
 *     'type' => 'object',
 *     'required' => ['kind'],
 *     'properties' => [
 *         'kind' => ['type' => 'string', 'enum' => ['a', 'b']],
 *     ],
 * ])]
 * final class WidgetData extends Data { … }
 * ```
 *
 * Keywords are bounded to what swagger-php can serialise (see
 * {@see \Radiergummi\OpenApi\Support\Generator\ExplicitClassSchema::ACCEPTED_KEYWORDS}). Unsupported keywords
 * (`if`/`then`/`else`, `dependentRequired`/`dependentSchemas`,
 * `dependencies`) are dropped at build time and flagged by the `schema.raw-keyword-unsupported`
 * lint rule.
 *
 * For document-level, operation-targeted overrides use `openapi.overrides` instead; `#[RawSchema]`
 * is co-located with the class and travels with it through refactors.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RawSchema
{
    /**
     * @param array<string, mixed> $schema A literal JSON Schema definition.
     */
    public function __construct(
        public array $schema,
    ) {}
}
