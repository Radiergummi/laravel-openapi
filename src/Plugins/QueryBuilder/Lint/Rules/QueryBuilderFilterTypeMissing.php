<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\QueryBuilder\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Lint\Rules\Rule;

/**
 * Stub — implementation lands in Task 7.
 */
final readonly class QueryBuilderFilterTypeMissing implements Rule
{
    #[Override]
    public function id(): string
    {
        return 'querybuilder.filter-type-missing';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'A #[AllowedFilter] attribute declares no `type:` argument — the parameter schema falls back to string.';
    }
}
