<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Attributes\Security;

/**
 * Fixture controller for edge-case feature tests — one action per scenario,
 * each consumed by exactly one test file.
 */
final class EdgeCaseFixtureController extends Controller
{
    /**
     * Union of two Data subclasses. Exercises the silent-degradation path:
     * neither `DataResponseResolver` nor `ReturnTypeExtractor` handle a
     * union return type today, so the operation falls back to a bare 200.
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
     * Explicit per-operation scheme override via `#[Security(scheme: ...)]`.
     *
     * @see \Radiergummi\OpenApi\Tests\Feature\MultiSchemeSecurityTest
     */
    #[Security([], scheme: 'bearer')]
    public function bearerOnlyAction(): array
    {
        return [];
    }
}
