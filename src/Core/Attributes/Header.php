<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Attributes;

use Attribute;

/**
 * Documents an HTTP request header. Repeatable; method-level wins on `name` collision.
 * Constructor mirrors {@see ResponseHeader} minus `status`; `required` defaults to `false`
 * here vs. `null` on response headers (request headers are inherently a boolean question).
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class Header
{
    /**
     * @param non-empty-string      $name
     * @param null|non-empty-string $description
     * @param OpenApiPrimitiveType  $type
     * @param null|non-empty-string $format
     */
    public function __construct(
        public string $name,
        public ?string $description = null,
        public string $type = 'string',
        public ?string $format = null,
        public mixed $example = null,
        public bool $required = false,
        public ?bool $deprecated = null,
    ) {}
}
