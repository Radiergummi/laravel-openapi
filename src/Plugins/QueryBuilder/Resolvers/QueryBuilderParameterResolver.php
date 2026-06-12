<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\QueryBuilder\Resolvers;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Contracts\Registry\QueryParameterResolver;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedInclude;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedSort;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Support\QueryBuilderChainReader;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Support\QueryBuilderChainScan;
use Radiergummi\OpenApi\Routing\ActionDescriptor;

use function implode;
use function sprintf;

/**
 * Turns `spatie/laravel-query-builder` usage into OpenAPI query parameters, from two sources:
 * the plugin's `#[AllowedFilter]` / `#[AllowedSort]` / `#[AllowedInclude]` attributes, and the
 * literal `QueryBuilder::for(...)` fluent chain in the method body (the Tier-1 bounded scan in
 * {@see QueryBuilderChainReader}).
 *
 * Precedence is **per kind**: any attribute of a kind present means that kind comes from
 * attributes only, and the chain fills only the attribute-less kinds. The body scan runs only
 * when at least one kind has no attribute. Both sources funnel into the same parameter shapes
 * (`filter[...]`, `sort`, `include`), so chain and attributes converge on identical output —
 * chain-derived filters default to a string schema, since the chain carries no type information.
 *
 * A detected Spatie chain that yields nothing readable (a builder mutated across statements, a
 * dynamic allow-list, a chain inside a conditional) degrades to no chain parameters with a
 * generation-log notice pointing at the attributes as the escape hatch.
 */
#[Scoped]
final readonly class QueryBuilderParameterResolver implements QueryParameterResolver
{
    public function __construct(
        private QueryBuilderChainReader $chainReader,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return list<OA\Parameter>
     */
    public function resolveQueryParameters(ActionDescriptor $descriptor): array
    {
        $reflector = $descriptor->actionReflector;

        if ($reflector === null) {
            return [];
        }

        $filterAttributes = $reflector->getAttributes(AllowedFilter::class);
        $sortAttributes = $reflector->getAttributes(AllowedSort::class);
        $includeAttributes = $reflector->getAttributes(AllowedInclude::class);

        $scan = $this->scanChain(
            $descriptor,
            everyKindAttributed: $filterAttributes !== []
            && $sortAttributes !== []
            && $includeAttributes !== [],
        );

        $parameters = [];

        if ($filterAttributes !== []) {
            foreach ($filterAttributes as $attribute) {
                $parameters[] = $this->filterParameter($attribute->newInstance());
            }
        } else {
            foreach ($scan->filters as $name) {
                $parameters[] = $this->chainFilterParameter($name);
            }
        }

        $sortFields = $sortAttributes !== []
            ? $sortAttributes[0]->newInstance()->fields
            : $scan->sorts;

        if ($sortFields !== []) {
            $parameters[] = $this->listParameter(
                name: 'sort',
                values: $sortFields,
                description: 'Comma-separated sort fields. Prefix a field with `-` for descending order.',
            );
        }

        $includeNames = $includeAttributes !== []
            ? $includeAttributes[0]->newInstance()->names
            : $scan->includes;

        if ($includeNames !== []) {
            $parameters[] = $this->listParameter(
                name: 'include',
                values: $includeNames,
                description: 'Comma-separated related resources to include.',
            );
        }

        return $parameters;
    }

    // region Chain scan

    /**
     * Runs the bounded body scan when at least one kind has no attribute (the Tier-0-miss
     * discipline: fully attributed actions never pay for parsing), and phrases the degrade
     * notices off the scan's evidence. One notice per action: a detected-but-unreadable Spatie
     * chain (empty scan despite a builder root *and* `allowed*` calls), or — when the scan did
     * yield names — the matched calls that had to drop non-literal elements.
     */
    private function scanChain(ActionDescriptor $descriptor, bool $everyKindAttributed): QueryBuilderChainScan
    {
        $method = $descriptor->method;

        if ($method === null || $everyKindAttributed) {
            return new QueryBuilderChainScan();
        }

        $scan = $this->chainReader->read($method);
        $actionName = sprintf('%s::%s', $method->getDeclaringClass()->getName(), $method->getName());

        if ($scan->isEmpty() && $scan->builderDetected && $scan->allowedCallDetected) {
            $this->logger->notice(
                sprintf(
                    'The QueryBuilder chain in %s could not be read statically; no query parameters '
                    . 'inferred. Only a single-expression QueryBuilder::for(...) chain with literal '
                    . 'allow-lists is readable — annotate the action with #[AllowedFilter] / '
                    . '#[AllowedSort] / #[AllowedInclude] to document the parameters.',
                    $actionName,
                ),
            );
        } elseif ($scan->unreadableCalls !== []) {
            $this->logger->notice(
                sprintf(
                    'The QueryBuilder chain in %s: %s element(s) that are not statically readable '
                    . 'were dropped; the remaining literal names are documented. Annotate the action '
                    . 'with #[AllowedFilter] / #[AllowedSort] / #[AllowedInclude] to document the rest.',
                    $actionName,
                    implode(', ', $scan->unreadableCalls),
                ),
            );
        }

        return $scan;
    }

    // endregion

    // region Parameter shapes

    private function filterParameter(AllowedFilter $filter): OA\Parameter
    {
        return new OA\Parameter([
            'name' => sprintf('filter[%s]', $filter->name),
            'in' => 'query',
            'required' => false,
            'schema' => $filter->descriptor()->toSchema(),
        ]);
    }

    /**
     * A chain-derived filter defaults to a string schema: the allow-list carries the wire name
     * only, never a type. `#[AllowedFilter]` is the escape hatch for typed filters.
     */
    private function chainFilterParameter(string $name): OA\Parameter
    {
        return new OA\Parameter([
            'name' => sprintf('filter[%s]', $name),
            'in' => 'query',
            'required' => false,
            'schema' => new OA\Schema(['type' => 'string']),
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

    // endregion
}
