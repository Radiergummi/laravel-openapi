<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Routing;

/**
 * A parsed documentation comment.
 */
final class DocComment
{
    public bool $empty {
        get => $this->summary === null && $this->description === null;
    }

    public function __construct(
        public readonly ?string $summary = null,
        public readonly ?string $description = null,
    ) {}
}
