<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Routing;

use Symfony\Component\TypeInfo\Type;

final readonly class UriParameterDescriptor
{
    /**
     * @param string            $name            Parameter name as it appears in the URI template (e.g. `project`).
     * @param Type              $type            Fully resolved symfony/type-info Type tree.
     * @param bool              $optional        True when the parameter type allows null (i.e. `?Foo` or `Foo|null`).
     * @param null|string       $whereConstraint Raw regex from a `#[Where*]` attribute or `$route->wheres[]`.
     * @param null|WhereKind    $whereKind       Semantic classification of the constraint; null when no constraint
     *                                           exists.
     * @param null|class-string $modelClass      For `UrlRoutable` bindings — the fully-qualified model class name.
     * @param null|string       $routeKeyName    For model bindings — the route key (e.g. `uuid`, `id`, `_id`).
     * @param null|list<string> $enumCases       For `BackedEnum` types — the string/int case values as strings.
     */
    public function __construct(
        public string $name,
        public Type $type,
        public bool $optional,
        public ?string $whereConstraint,
        public ?WhereKind $whereKind,
        public ?string $modelClass,
        public ?string $routeKeyName,
        public ?array $enumCases,
    ) {}
}
