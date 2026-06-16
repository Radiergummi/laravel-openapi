<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Attributes;

/**
 * Sentinel for nullable-mixed attribute parameters (`example`, `enum`): distinguishes
 * "not passed" from "explicitly null" (deliberate suppression).
 *
 * @internal
 */
enum FieldDefault
{
    case Unset;
}
