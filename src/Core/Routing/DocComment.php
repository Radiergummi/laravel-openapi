<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Routing;

/**
 * A parsed documentation comment.
 *
 * @bundle Radiergummi\OpenApi\Core\Routing
 */
final readonly class DocComment
{
    public function __construct(
        public ?string $summary = null,
        public ?string $description = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->summary === null && $this->description === null;
    }
}
