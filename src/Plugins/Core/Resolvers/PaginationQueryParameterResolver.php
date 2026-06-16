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
 * Emits pagination query parameters for actions that call a `paginate()`-family method
 * ({@see PaginatorCallReader}). Offset paginators emit `page`/`per_page`; cursor emits `cursor`.
 * A hand-declared `#[QueryParam]` wins via `OperationBuilder`'s `(name, in)` dedup.
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

    private function cursorParameter(): OA\Parameter
    {
        return new OA\Parameter([
            'name' => 'cursor',
            'in' => 'query',
            'required' => false,
            'schema' => new OA\Schema(['type' => 'string']),
        ]);
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
}
