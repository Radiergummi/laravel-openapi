<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\QueryBuilder;

use Override;
use Radiergummi\OpenApi\Contracts\Registry\Plugin;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Lint\Rules\QueryBuilderFilterDuplicate;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Lint\Rules\QueryBuilderFilterTypeMissing;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Lint\Rules\QueryBuilderParamsUndeclared;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Resolvers\QueryBuilderParameterResolver;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;

/**
 * Teaches the OpenAPI core to document `spatie/laravel-query-builder`
 * filter/sort/include query parameters.
 */
final class QueryBuilderPlugin implements Plugin
{
    #[Override]
    public function register(OpenApiRegistry $registry): void
    {
        $registry->addQueryParameterResolver(QueryBuilderParameterResolver::class);
        $registry->addRule(QueryBuilderParamsUndeclared::class);
        $registry->addRule(QueryBuilderFilterTypeMissing::class);
        $registry->addRule(QueryBuilderFilterDuplicate::class);
    }
}
