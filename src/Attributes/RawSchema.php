<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use Attribute;

/**
 * Replaces a class's inferred schema with a literal JSON Schema array, short-circuiting inference
 * entirely. Field-level attributes on the class are ignored. Keywords unsupported by swagger-php
 * are dropped at build time and flagged by the `schema.raw-keyword-unsupported` lint rule. For
 * operation-targeted overrides use `openapi.overrides` instead.
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
