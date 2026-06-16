<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Registry;

use LogicException;

/**
 * Thrown when `addX()` is called on a sealed {@see OpenApiRegistry}.
 *
 * This is a programming error (out-of-band registration), not a recoverable condition.
 *
 * @internal
 */
final class RegistrySealedException extends LogicException {}
