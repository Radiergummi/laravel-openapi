<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

interface Rule
{
    public function id(): string;

    public function level(): int;

    /**
     * A one-line human description of what the rule checks.
     * Used by `openapi:lint --list` and the generated docs catalog.
     */
    public function description(): string;
}
