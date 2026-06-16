<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support\SpecTime;

use Illuminate\Routing\Route;
use Override;

/**
 * Stub {@see Route} used during FormRequest introspection. Returns {@see AnyValue} from
 * `parameter()` for any name, so a `rules()` body that calls `$this->route('foo')->bar` resolves.
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
    #[Override]
    public function parameter(mixed $name, mixed $default = null): AnyValue
    {
        return AnyValue::instance();
    }

    /**
     * @param string $name
     */
    #[Override]
    public function hasParameter(mixed $name): bool
    {
        return true;
    }
}
