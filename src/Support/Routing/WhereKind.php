<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Routing;

enum WhereKind
{
    /** UUID pattern — string with UUID regex, maps to `format: uuid` in OpenAPI. */
    case Uuid;

    /** Numeric pattern — integer / numeric string, maps to `type: integer` in OpenAPI. */
    case Number;

    /** Enumerated string values (`WhereIn`) — maps to `enum: [...]` in OpenAPI. */
    case In;

    /** Arbitrary regex that does not map to a standard kind. */
    case Custom;
}
