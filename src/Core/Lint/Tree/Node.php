<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Tree;

interface Node
{
    /**
     * Structural path for diagnostics (e.g., "#/paths/~1api~1v0~1foo/get").
     *
     * @param string $append When non-empty, appended as a `/`-separated child segment
     *                       (e.g. `pointer('required')` → `"…/required"`).
     */
    public function pointer(string $append = ''): string;

    /** Parent node, or null for the root ApiNode */
    public function parent(): ?Node;
}
