<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Enums;

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

    case TextPlain = 'text/plain';

    case TextHtml = 'text/html';

    case Yaml = 'application/yaml';

    case OctetStream = 'application/octet-stream';

    public function schema(?OA\Schema $withSchema = null): OA\MediaType
    {
        $properties = ['mediaType' => $this->value];

        if ($withSchema !== null) {
            $properties['schema'] = $withSchema;
        }

        return new OA\MediaType($properties);
    }
}
