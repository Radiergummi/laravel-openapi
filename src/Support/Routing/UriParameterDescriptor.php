<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Routing;

use Symfony\Component\TypeInfo\Type;

/**
 * @internal
 */
final readonly class UriParameterDescriptor
{
    /**
     * @param string                 $name            Parameter name as it appears in the URI template (e.g. `project`).
     * @param Type                   $type            Fully resolved symfony/type-info Type tree.
     * @param bool                   $optional        True when the parameter type allows null (i.e. `?Foo` or
     *                                                `Foo|null`).
     * @param null|string            $whereConstraint Raw regex from a `#[Where*]` attribute or `$route->wheres[]`.
     * @param null|WhereKind         $whereKind       Semantic classification of the constraint; null when no constraint
     *                                                exists.
     * @param null|list<string>      $enumCases       For `BackedEnum` types — the string/int case values as strings.
     * @param null|RouteModelBinding $modelBinding    How a route-model-bound parameter resolves (model, binding key,
     *                                                key type/format); null when the parameter is not a model binding.
     */
    public function __construct(
        public string $name,
        public Type $type,
        public bool $optional,
        public ?string $whereConstraint,
        public ?WhereKind $whereKind,
        public ?array $enumCases,
        public ?RouteModelBinding $modelBinding,
    ) {}
}
