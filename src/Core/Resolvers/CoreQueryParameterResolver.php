<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Resolvers;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Attributes\QueryParam;
use Radiergummi\OpenApi\Contracts\Registry\QueryParameterResolver;
use Radiergummi\OpenApi\Routing\ActionDescriptor;

/**
 * Turns Core `#[QueryParam]` attributes on a controller action (and its enclosing class) into
 * `OA\Parameter` entries.
 *
 * Class-level entries are emitted first, so a controller can declare common query parameters once;
 * method-level entries are appended afterward. When the same `name` appears at both levels, the
 * method-level entry replaces the class-level one.
 */
final readonly class CoreQueryParameterResolver implements QueryParameterResolver
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

        /** @var array<string, QueryParam> $merged */
        $merged = [];

        if ($descriptor->controller !== null) {
            foreach ($descriptor->controller->getAttributes(QueryParam::class) as $attribute) {
                $instance = $attribute->newInstance();
                $merged[$instance->name] = $instance;
            }
        }

        foreach ($reflector->getAttributes(QueryParam::class) as $attribute) {
            $instance = $attribute->newInstance();
            $merged[$instance->name] = $instance;
        }

        return array_values(
            array_map(
                fn(QueryParam $attribute): OA\Parameter => $this->parameter($attribute),
                $merged,
            ),
        );
    }

    private function parameter(QueryParam $attribute): OA\Parameter
    {
        $properties = [
            'name' => $attribute->name,
            'in' => 'query',
            'required' => $attribute->required,
            'schema' => $attribute->descriptor()->toSchema(),
        ];

        if ($attribute->deprecated) {
            $properties['deprecated'] = true;
        }

        return new OA\Parameter($properties);
    }
}
