<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Routing\Controller;

class RouteBoundFixtureController extends Controller
{
    public function callback(RouteBoundFormRequest $request): array
    {
        return [];
    }
}
