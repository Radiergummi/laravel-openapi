<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\ConstructorMiddleware;

use Illuminate\Routing\Controller;

/**
 * BookStack-style base controller: the constructor applying middleware lives on the parent,
 * concrete controllers inherit it. `ReflectionClass::getConstructor()` on a child reflects this
 * declaring class, so the scanner reads this file.
 */
abstract class ConstructorMiddlewareBaseController extends Controller
{
    public function __construct(public readonly UnresolvableSigningKey $signingKey)
    {
        $this->middleware('auth:api');
    }
}
