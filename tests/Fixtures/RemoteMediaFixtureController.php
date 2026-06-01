<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Routing\Controller;

class RemoteMediaFixtureController extends Controller
{
    public function store(RemoteMediaFixtureRequest $request): array
    {
        return [];
    }
}
