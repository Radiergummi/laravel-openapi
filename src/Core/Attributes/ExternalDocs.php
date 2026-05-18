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
 * Attaches an external documentation link to an operation.
 *
 * Renders in Scalar / Swagger UI as a clickable "Learn more" link beside the
 * operation. Use it to point at the deeper internal docs that don't belong in
 * the spec text itself (Notion ADRs, integration runbooks, knowledge base).
 *
 * Method-level wins over class-level. Only one entry per operation is supported.
 *
 * ```php
 * #[OpenApi\ExternalDocs(
 *     url: 'https://www.notion.so/matchory/Search-RFC',
 *     description: 'Search pipeline RFC',
 * )]
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class ExternalDocs
{
    public function __construct(
        public string $url,
        public ?string $description = null,
    ) {}
}
