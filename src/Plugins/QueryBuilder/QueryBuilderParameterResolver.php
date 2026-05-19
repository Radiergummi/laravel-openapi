<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\QueryBuilder;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Generator\NullableSchema;
use Radiergummi\OpenApi\Core\Registry\QueryParameterResolver;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedInclude;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedSort;

use function sprintf;

/**
 * Turns the QueryBuilder plugin's `#[AllowedFilter]`, `#[AllowedSort]`, and
 * `#[AllowedInclude]` attributes into OpenAPI query parameters.
 */
final readonly class QueryBuilderParameterResolver implements QueryParameterResolver
{
    /**
     * @return list<OA\Parameter>
     */
    public function resolveQueryParameters(ActionDescriptor $descriptor): array
    {
        $reflector = $descriptor->actionReflector;

        if ($reflector === null) {
            return [];
        }

        $parameters = [];

        foreach ($reflector->getAttributes(AllowedFilter::class) as $attribute) {
            $parameters[] = $this->filterParameter($attribute->newInstance());
        }

        $sortAttributes = $reflector->getAttributes(AllowedSort::class);

        if ($sortAttributes !== []) {
            $parameters[] = $this->listParameter(
                name: 'sort',
                values: $sortAttributes[0]->newInstance()->fields,
                description: 'Comma-separated sort fields. Prefix a field with `-` for descending order.',
            );
        }

        $includeAttributes = $reflector->getAttributes(AllowedInclude::class);

        if ($includeAttributes !== []) {
            $parameters[] = $this->listParameter(
                name: 'include',
                values: $includeAttributes[0]->newInstance()->names,
                description: 'Comma-separated related resources to include.',
            );
        }

        return $parameters;
    }

    private function filterParameter(AllowedFilter $filter): OA\Parameter
    {
        $descriptor = $filter->descriptor();
        $schema = new OA\Schema(['type' => 'string', ...$descriptor->toOpenApi()]);

        // Descriptor::toOpenApi() omits `nullable`; the OAS 3.1 `type: [..., 'null']`
        // shape is applied here so a `nullable: true` filter widens its wire schema.
        if ($descriptor->nullable === true) {
            NullableSchema::applyTo($schema);
        }

        return new OA\Parameter([
            'name' => sprintf('filter[%s]', $filter->name),
            'in' => 'query',
            'required' => false,
            'schema' => $schema,
        ]);
    }

    /**
     * @param list<string> $values
     */
    private function listParameter(string $name, array $values, string $description): OA\Parameter
    {
        $itemProps = ['type' => 'string'];

        if ($values !== []) {
            $itemProps['enum'] = $values;
        }

        return new OA\Parameter([
            'name' => $name,
            'in' => 'query',
            'required' => false,
            'description' => $description,
            'style' => 'form',
            'explode' => false,
            'schema' => new OA\Schema([
                'type' => 'array',
                'items' => new OA\Items($itemProps),
            ]),
        ]);
    }
}
