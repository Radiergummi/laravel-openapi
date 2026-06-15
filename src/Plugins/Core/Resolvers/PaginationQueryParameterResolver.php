<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Resolvers;

use OpenApi\Annotations as OA;
use Override;
use Radiergummi\OpenApi\Contracts\Registry\QueryParameterResolver;
use Radiergummi\OpenApi\Enums\PaginatorKind;
use Radiergummi\OpenApi\Plugins\Core\Support\PaginatorCallReader;
use Radiergummi\OpenApi\Routing\ActionDescriptor;

/**
 * Emits the conventional pagination query parameters for an action that calls a `paginate()`-family
 * method (Tier-1 body scan via {@see PaginatorCallReader}, issue #31).
 *
 * Offset paginators (`paginate()`, `simplePaginate()`) advertise `page` and `per_page`; a cursor
 * paginator (`cursorPaginate()`) advertises `cursor`. `per_page` is the common `?per_page=` idiom
 * rather than a framework default, documented for the offset case. All three are optional.
 *
 * Registered after {@see CoreQueryParameterResolver}; `OperationBuilder` dedups by `(name, in)` and
 * keeps an explicit `#[QueryParam]` over resolver output, so a hand-declared `page` wins.
 */
final readonly class PaginationQueryParameterResolver implements QueryParameterResolver
{
    public function __construct(private PaginatorCallReader $reader) {}

    /**
     * @return list<OA\Parameter>
     */
    #[Override]
    public function resolveQueryParameters(ActionDescriptor $descriptor): array
    {
        $method = $descriptor->method;

        if ($method === null) {
            return [];
        }

        $kind = $this->reader->detect($method);

        if ($kind === null) {
            return [];
        }

        return match ($kind) {
            PaginatorKind::Cursor => [$this->cursorParameter()],
            PaginatorKind::LengthAware, PaginatorKind::Simple => $this->offsetParameters(),
        };
    }

    /**
     * @return list<OA\Parameter>
     */
    private function offsetParameters(): array
    {
        return [
            $this->integerParameter('page'),
            $this->integerParameter('per_page'),
        ];
    }

    private function integerParameter(string $name): OA\Parameter
    {
        return new OA\Parameter([
            'name' => $name,
            'in' => 'query',
            'required' => false,
            'schema' => new OA\Schema(['type' => 'integer', 'minimum' => 1]),
        ]);
    }

    private function cursorParameter(): OA\Parameter
    {
        return new OA\Parameter([
            'name' => 'cursor',
            'in' => 'query',
            'required' => false,
            'schema' => new OA\Schema(['type' => 'string']),
        ]);
    }
}
