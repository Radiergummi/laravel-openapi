<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Attributes\Security;
use Radiergummi\OpenApi\Tests\Unit\Support\Routing\ReturnTypeExtractorFixture;
use Random\RandomException;

/**
 * Fixture controller for edge-case feature tests — one action per scenario, each consumed by
 * exactly one test file.
 */
final class EdgeCaseFixtureController extends Controller
{
    /**
     * Union of two Data subclasses — emitted as a `oneOf` of `$ref`s.
     *
     * @throws RandomException
     *
     * @see ReturnTypeExtractorFixture
     */
    public function unionReturnAction(): ScalarOnlyData|AddressFixtureData
    {
        return random_int(0, 1) === 0
            ? ScalarOnlyData::from(['name' => 'x', 'count' => 0])
            : AddressFixtureData::from(['street' => 'x', 'city' => 'y']);
    }

    /**
     * Mixed union — a Data class alongside a non-Data return type. Only the Data members contribute
     * to the response schema; the rest are ignored.
     *
     * @throws RandomException
     */
    public function mixedUnionReturnAction(): ScalarOnlyData|RedirectResponse
    {
        return random_int(0, 1) === 0
            ? ScalarOnlyData::from(['name' => 'x', 'count' => 0])
            : new RedirectResponse('/');
    }

    /**
     * Explicit per-operation scheme override via `#[Security(scheme: ...)]`.
     */
    #[Security([], scheme: 'bearer')]
    public function bearerOnlyAction(): array
    {
        return [];
    }

    /**
     * Stacked `#[Security]` attributes — each instance contributes one OR-alternative to the
     * operation's `security` list.
     */
    #[Security(['admin'], scheme: 'bearer')]
    #[Security(['admin'], scheme: 'apiKey')]
    public function stackedSecurityAction(): array
    {
        return [];
    }
}
