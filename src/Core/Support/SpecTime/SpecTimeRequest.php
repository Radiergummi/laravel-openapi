<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Support\SpecTime;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Instantiates a {@see FormRequest} subclass with a permissive route + user context for spec-time
 * introspection. Lets `rules()` bodies that read `$this->route('foo')->bar` or `$this->user()`
 * run to completion — both resolve to {@see AnyValue}, whose magic-method paths terminate any
 * chained access without throwing.
 *
 * The schema generator only reads the rules array's structure (keys, types, required, file
 * detection); stubbed values inside `Rule::in([...])` etc. are opaque placeholders, not semantic
 * constraints, so the spec is preserved.
 *
 * @internal Used by {@see \Radiergummi\OpenApi\Core\Support\SchemaFromFormRequest}; not part of
 *           the public extension surface.
 */
final class SpecTimeRequest
{
    /**
     * @template T of FormRequest
     *
     * @param class-string<T> $formRequestClass
     *
     * @return T
     */
    public static function wire(string $formRequestClass): FormRequest
    {
        $instance = new $formRequestClass();
        $instance->setRouteResolver(static fn(): SpecTimeRoute => new SpecTimeRoute());
        $instance->setUserResolver(static fn(): AnyValue => AnyValue::instance());

        return $instance;
    }
}
