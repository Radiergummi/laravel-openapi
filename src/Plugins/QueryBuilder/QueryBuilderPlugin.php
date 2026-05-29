<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\QueryBuilder;

use Radiergummi\OpenApi\Contracts\Registry\Plugin;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Lint\Rules\QueryBuilderFilterTypeMissing;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Lint\Rules\QueryBuilderParamsUndeclared;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;

/**
 * Teaches the OpenAPI core to document `spatie/laravel-query-builder`
 * filter/sort/include query parameters.
 */
final class QueryBuilderPlugin implements Plugin
{
    public function register(OpenApiRegistry $registry): void
    {
        $registry->addQueryParameterResolver(QueryBuilderParameterResolver::class);
        $registry->addRule(QueryBuilderParamsUndeclared::class);
        $registry->addRule(QueryBuilderFilterTypeMissing::class);
    }
}
