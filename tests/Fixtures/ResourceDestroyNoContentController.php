<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * A resourceful `destroy` returning `response()->noContent()`: a body-less 204 the convention agrees
 * with. Guards that the content-bearing suppression does not disturb a genuinely empty 204.
 */
class ResourceDestroyNoContentController extends Controller
{
    public function destroy(string $widget): SymfonyResponse
    {
        return response()->noContent();
    }
}
