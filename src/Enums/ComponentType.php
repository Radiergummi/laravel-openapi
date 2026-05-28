<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Enums;

/**
 * The standard component types under `#/components/…` in an OpenAPI 3.1 document.
 *
 * @see https://spec.openapis.org/oas/v3.1.0#components-object
 */
enum ComponentType: string
{
    case Schemas = 'schemas';

    case Responses = 'responses';

    case Parameters = 'parameters';

    case Examples = 'examples';

    case RequestBodies = 'requestBodies';

    case Headers = 'headers';

    case SecuritySchemes = 'securitySchemes';

    case Links = 'links';

    case Callbacks = 'callbacks';

    case PathItems = 'pathItems';
}
