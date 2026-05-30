<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Support\SpecTime;

use Illuminate\Routing\Route;

/**
 * {@see Route} subclass used during FormRequest introspection. Its only purpose is to return
 * {@see AnyValue} from {@see Route::parameter()} regardless of which name is asked for, so a
 * `rules()` body that calls `$this->route('foo')->bar` resolves without throwing.
 *
 * @internal
 */
final class SpecTimeRoute extends Route
{
    public function __construct()
    {
        parent::__construct(['GET'], '/__spec_time__', static fn(): null => null);
    }

    /**
     * @param string             $name
     * @param null|object|string $default
     */
    public function parameter($name, $default = null): AnyValue
    {
        return AnyValue::instance();
    }

    /**
     * @param string $name
     */
    public function hasParameter($name): bool
    {
        return true;
    }
}
