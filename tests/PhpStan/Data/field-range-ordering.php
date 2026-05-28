<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan\Data;

use Radiergummi\OpenApi\Attributes\QueryParam;
use Radiergummi\OpenApi\Attributes\RequestField;
use Radiergummi\OpenApi\Attributes\ResponseField;

#[QueryParam(name: 'okMinMax', minimum: 1, maximum: 10)]
#[QueryParam(name: 'okEqual', minimum: 5, maximum: 5)]
#[QueryParam(name: 'badMinMax', minimum: 100, maximum: 10)]
#[QueryParam(name: 'badMinMaxFloat', minimum: 2.5, maximum: 1.5)]
#[QueryParam(name: 'badLength', minLength: 50, maxLength: 5)]
#[QueryParam(name: 'badItems', minItems: 9, maxItems: 3)]
#[QueryParam(name: 'multipleBad', minimum: 10, maximum: 1, minLength: 5, maxLength: 1)]
final class FieldRangeOrderingFixture
{
    public function __construct(
        #[RequestField(minLength: 100, maxLength: 10)]
        public string $shortName,
        #[RequestField(minItems: 5, maxItems: 2)]
        public array $tags,
        #[RequestField(minimum: 1, maximum: 10)]
        public int $valid,
    ) {}
}

final class ResponseFieldRangeOrderingFixture
{
    #[ResponseField(minimum: 10, maximum: 1)]
    public int $count;
}
