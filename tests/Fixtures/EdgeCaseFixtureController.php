<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Attributes\Security;

/**
 * Fixture controller for edge-case feature tests — one action per scenario,
 * each consumed by exactly one test file.
 */
final class EdgeCaseFixtureController extends Controller
{
    /**
     * Union of two Data subclasses — emitted as a `oneOf` of `$ref`s.
     *
     * @see \Radiergummi\OpenApi\Tests\Feature\UnionReturnTypeTest
     */
    public function unionReturnAction(): ScalarOnlyData|AddressFixtureData
    {
        return random_int(0, 1) === 0
            ? ScalarOnlyData::from(['name' => 'x', 'count' => 0])
            : AddressFixtureData::from(['street' => 'x', 'city' => 'y']);
    }

    /**
     * Mixed union — a Data class alongside a non-Data return type. Only the
     * Data members contribute to the response schema; the rest are ignored.
     *
     * @see \Radiergummi\OpenApi\Tests\Feature\UnionReturnTypeTest
     */
    public function mixedUnionReturnAction(): ScalarOnlyData|RedirectResponse
    {
        return random_int(0, 1) === 0
            ? ScalarOnlyData::from(['name' => 'x', 'count' => 0])
            : new RedirectResponse('/');
    }

    /**
     * Explicit per-operation scheme override via `#[Security(scheme: ...)]`.
     *
     * @see \Radiergummi\OpenApi\Tests\Feature\MultiSchemeSecurityTest
     */
    #[Security([], scheme: 'bearer')]
    public function bearerOnlyAction(): array
    {
        return [];
    }

    /**
     * Stacked `#[Security]` attributes — each instance contributes one
     * OR-alternative to the operation's `security` list.
     *
     * @see \Radiergummi\OpenApi\Tests\Feature\StackedSecurityTest
     */
    #[Security(['admin'], scheme: 'bearer')]
    #[Security(['admin'], scheme: 'apiKey')]
    public function stackedSecurityAction(): array
    {
        return [];
    }
}
