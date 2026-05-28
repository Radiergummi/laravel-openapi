<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan\Data;

use Radiergummi\OpenApi\Attributes\QueryParam;

#[QueryParam(name: 'okOptional', default: 25)]
#[QueryParam(name: 'okRequired', required: true)]
#[QueryParam(name: 'okRequiredFalse', required: false, default: 10)]
#[QueryParam(name: 'okRequiredNullDefault', required: true, default: null)]
#[QueryParam(name: 'badRequiredWithDefault', required: true, default: 25)]
#[QueryParam(name: 'badRequiredWithStringDefault', required: true, default: 'foo')]
final class QueryParamRequiredWithDefaultFixture {}
