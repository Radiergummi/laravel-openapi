<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Attributes\CookieParam;

/**
 * Test fixture — exercises `#[CookieParam]` across the surface mirrored from `#[QueryParam]`.
 *
 * Routes are wired up in {@see \Radiergummi\OpenApi\Tests\Feature\CookieParamTest}'s `beforeEach`.
 */
#[CookieParam('tracking', description: 'Anonymous tracking identifier.')]
class CookieParamFixtureController extends Controller
{
    /** A single optional, string-typed cookie with no extra surface. */
    #[CookieParam('session')]
    public function simpleAction(): array
    {
        return [];
    }

    /** Required, integer-typed cookie. */
    #[CookieParam('uid', type: 'integer', required: true)]
    public function requiredAction(): array
    {
        return [];
    }

    /** Cookie constrained to a backed-enum's cases. */
    #[CookieParam('theme', enum: StatusFixtureEnum::class)]
    public function enumAction(): array
    {
        return [];
    }

    /** Cookie carrying a human description and an example. */
    #[CookieParam('locale', description: 'Preferred UI locale.', example: 'en-US')]
    public function describedAction(): array
    {
        return [];
    }

    /** Two cookies on one action, declaration order preserved. */
    #[CookieParam('first')]
    #[CookieParam('second', required: true)]
    public function multipleAction(): array
    {
        return [];
    }

    /** Method-level entry overrides the class-level `tracking` cookie on name collision. */
    #[CookieParam('tracking', required: true)]
    public function overrideAction(): array
    {
        return [];
    }
}
