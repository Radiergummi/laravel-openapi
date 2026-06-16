<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\RawSchema;

use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Tests\Fixtures\PropertyFixtureData;

class RawSchemaController extends Controller
{
    public function data(RawSchemaData $payload): array
    {
        return [];
    }

    public function resource(): RawSchemaResource
    {
        return new RawSchemaResource(null);
    }

    public function formRequest(RawSchemaFormRequest $request): array
    {
        return [];
    }

    public function withFieldAttribute(RawSchemaWithFieldAttributeData $payload): array
    {
        return [];
    }

    public function unsupported(RawSchemaUnsupportedKeywordData $payload): array
    {
        return [];
    }

    public function noPayload(): array
    {
        return [];
    }

    public function fieldAttributeOnly(PropertyFixtureData $payload): array
    {
        return [];
    }
}
