<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix;

use Radiergummi\OpenApi\Attributes\QueryParam;

class DuplicateQueryParamFixtureController
{
    #[QueryParam(name: 'q')]
    #[QueryParam(name: 'q')]
    public function index(): void {}
}
