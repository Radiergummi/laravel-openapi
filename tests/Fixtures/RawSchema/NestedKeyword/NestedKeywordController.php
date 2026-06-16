<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\RawSchema\NestedKeyword;

use Illuminate\Routing\Controller;

class NestedKeywordController extends Controller
{
    public function additionalPropertiesSchema(AdditionalPropertiesSchemaData $payload): array
    {
        return [];
    }

    public function patternProperties(PatternPropertiesData $payload): array
    {
        return [];
    }

    public function propertyNames(PropertyNamesData $payload): array
    {
        return [];
    }

    public function contains(ContainsData $payload): array
    {
        return [];
    }

    public function discriminator(DiscriminatorData $payload): array
    {
        return [];
    }

    public function additionalPropertiesItemsLess(AdditionalPropertiesItemsLessData $payload): array
    {
        return [];
    }

    public function additionalPropertiesBool(AdditionalPropertiesBoolData $payload): array
    {
        return [];
    }
}
