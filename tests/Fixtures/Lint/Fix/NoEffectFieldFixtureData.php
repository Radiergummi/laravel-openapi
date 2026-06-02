<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix;

use Radiergummi\OpenApi\Attributes\RequestField;
use Spatie\LaravelData\Data;

final class NoEffectFieldFixtureData extends Data
{
    public function __construct(
        #[RequestField]
        public string $noEffect,
    ) {}
}
