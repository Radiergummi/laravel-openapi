<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Auth;

use Illuminate\Routing\Controller;

/**
 * Minimal fixture controller used to verify controller-class-name tag derivation: the
 * `Controller` suffix is stripped and the remainder pluralised, so `AuthFixtureController`
 * produces the tag `AuthFixtures`.
 */
class AuthFixtureController extends Controller
{
    public function index(): array
    {
        return [];
    }
}
