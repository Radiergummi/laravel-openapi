<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Attributes;

use Attribute;

/**
 * Attaches a named example payload to a specific response status.
 *
 * Use one attribute per example. The `$status` selects which response receives
 * the example: it must match the status code of either the auto-derived
 * primary response or one of the explicit {@see Response} entries.
 *
 * Examples are skipped silently when the matching response has no content
 * schema — an example without a backing schema is not meaningful.
 *
 * ```php
 * #[OpenApi\ResponseExample(
 *     status: 200,
 *     name: 'first-page',
 *     value: ['data' => [['id' => 'abc', 'type' => 'project']]],
 * )]
 * #[OpenApi\ResponseExample(
 *     status: 422,
 *     name: 'missing-name',
 *     value: ['errors' => [['source' => ['pointer' => '/data/attributes/name']]]],
 * )]
 * ```
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
