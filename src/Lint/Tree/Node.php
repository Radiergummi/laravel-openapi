<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Tree;

interface Node
{
    /**
     * Structural path for diagnostics (e.g., "#/paths/~1api~1v0~1foo/get").
     *
     * @param string $append When non-empty, appended as a `/`-separated child segment
     *                       (e.g., `pointer('required')` → `"…/required"`).
     */
    public function pointer(string $append = ''): string;

    /** Parent node, or null for the root ApiNode */
    public function parent(): ?Node;
}
