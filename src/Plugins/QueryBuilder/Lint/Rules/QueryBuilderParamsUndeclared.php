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
 * Stub — implementation lands in Task 6.
 */
final readonly class QueryBuilderParamsUndeclared implements Rule
{
    #[Override]
    public function id(): string
    {
        return 'querybuilder.params-undeclared';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'A controller using QueryBuilder declares no #[AllowedFilter]/#[AllowedSort]/#[AllowedInclude] attributes.';
    }
}
