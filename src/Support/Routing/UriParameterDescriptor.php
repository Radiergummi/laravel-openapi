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
     * @param string                 $name            Parameter name as it appears in the URI template.
     * @param Type                   $type            Resolved symfony/type-info Type tree.
     * @param bool                   $optional        True when the type allows null (`?Foo` or `Foo|null`).
     * @param null|string            $whereConstraint Raw regex from a `#[Where*]` attribute or `$route->wheres[]`.
     * @param null|WhereKind         $whereKind       Semantic classification of the constraint.
     * @param null|list<string>      $enumCases       For `BackedEnum` types: case values as strings.
     * @param null|RouteModelBinding $modelBinding    Route-model-binding details; null when not a model binding.
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
