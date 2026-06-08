<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Registry;

use LogicException;

/**
 * Thrown when something tries to register with an {@see OpenApiRegistry} after it has been sealed.
 *
 * Sealing happens once, at the end of the service provider's registry factory closure. Reaching
 * this exception means a caller resolved the already-built registry and called an `addX()` method
 * out-of-band — a programming error, not a recoverable condition, so it is an unchecked
 * {@see LogicException} (see `exceptions.uncheckedExceptionClasses` in `phpstan.neon`) and is not
 * part of the `Plugin::register()` throws contract.
 *
 * @internal
 */
final class RegistrySealedException extends LogicException {}
