<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint;

final class ArrayFindingsCollector implements FindingsCollector
{
    /** @var list<Finding> */
    private array $findings = [];

    public function emit(Finding $finding): void
    {
        $this->findings[] = $finding;
    }

    /** @return list<Finding> */
    public function all(): array
    {
        return $this->findings;
    }
}
