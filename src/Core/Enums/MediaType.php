<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Enums;

use OpenApi\Annotations as OA;

/**
 * Canonical media-type strings used across the OpenAPI extraction pipeline.
 *
 * Centralized so that the value can't drift between extractors and the operation builder.
 */
enum MediaType: string
{
    case Json = 'application/json';

    case JsonApi = 'application/vnd.api+json';

    case MultipartFormData = 'multipart/form-data';

    case FormUrlEncoded = 'application/x-www-form-urlencoded';

    case EventStream = 'text/event-stream';

    public function schema(?OA\Schema $withSchema = null): OA\MediaType
    {
        $properties = ['mediaType' => $this->value];

        if ($withSchema !== null) {
            $properties['schema'] = $withSchema;
        }

        return new OA\MediaType($properties);
    }
}
