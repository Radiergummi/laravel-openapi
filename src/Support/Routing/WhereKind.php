<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Routing;

/**
 * @internal
 */
enum WhereKind
{
    /** Maps to `format: uuid`. */
    case Uuid;

    /** Maps to `type: integer`. */
    case Number;

    /** `WhereIn`; maps to `enum: [...]`. */
    case In;

    /** Arbitrary regex with no standard mapping. */
    case Custom;
}
