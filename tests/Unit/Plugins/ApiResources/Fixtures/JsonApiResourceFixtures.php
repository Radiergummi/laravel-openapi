<?php

declare(strict_types=1);

/**
 * Fixtures for the first-party JSON:API resource reader.
 *
 * Loaded through a `class_exists()` guard by the test that uses them: `JsonApiResource` only
 * exists from Laravel 13 on, and a class extending a missing parent is a fatal error on 12.
 */

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\ApiResources\Fixtures;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

class JsonApiArticleResource extends JsonApiResource
{
    public const string FIELD_TITLE = 'title';

    public const string FIELD_BODY = 'body';

    public function toAttributes(Request $request): array
    {
        // Constant keys exercise self:: resolution in the literal evaluator.
        return [
            self::FIELD_TITLE => $this->resource->title,
            self::FIELD_BODY => $this->resource->body,
        ];
    }

    public function toRelationships(Request $request): array
    {
        return [
            'author' => $this->whenLoaded('author'),
        ];
    }
}

class JsonApiMinimalResource extends JsonApiResource
{
    public function toAttributes(Request $request): array
    {
        return ['name' => $this->resource->name];
    }
}

class JsonApiDynamicResource extends JsonApiResource
{
    public function toAttributes(Request $request): array
    {
        $fields = [];

        foreach ($this->resource->columns as $column) {
            $fields[$column] = $this->resource->{$column};
        }

        return $fields;
    }
}
