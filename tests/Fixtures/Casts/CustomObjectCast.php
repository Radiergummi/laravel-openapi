<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A custom cast whose value shape is unknowable at Tier 0 — stands in for an app's own
 * `CastsAttributes` implementation. The generator must not recognise it as a framework cast;
 * its column should defer to the `@property` tag (#252).
 *
 * @implements CastsAttributes<mixed, mixed>
 */
final class CustomObjectCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value;
    }
}
