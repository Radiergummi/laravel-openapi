<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Auth;

use Illuminate\Routing\Controller;

/**
 * Minimal fixture controller living in an Auth namespace segment.
 *
 * Used to verify that deriveTag() treats 'Auth' as a domain segment rather than structural noise,
 * producing the tag 'Auth' instead of 'General'.
 */
class AuthFixtureController extends Controller
{
    public function index(): array
    {
        return [];
    }
}
