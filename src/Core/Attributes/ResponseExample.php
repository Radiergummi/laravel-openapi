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
 * Attaches a named example to a response with the matching `$status` (auto-derived primary or
 * declared via {@see Response}). Repeatable. Skipped silently when the matching response has no
 * content schema.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class ResponseExample extends BaseExample
{
    public function __construct(
        public int $status,
        string $name,
        mixed $value = null,
        ?string $summary = null,
        ?string $description = null,
        ?string $file = null,
    ) {
        parent::__construct(
            name: $name,
            value: $value,
            summary: $summary,
            description: $description,
            file: $file,
        );
    }
}
