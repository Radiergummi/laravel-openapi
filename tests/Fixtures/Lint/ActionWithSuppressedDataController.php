<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Illuminate\Http\JsonResponse;

/**
 * Fixture controller whose method accepts an Action (not a Data class directly). The Action's
 * constructor carries {@see SuppressedFixtureData} — used to verify that SuppressionCollector
 * follows Action indirection to reach #[IgnoreLint] directives on the Data class.
 */
final class ActionWithSuppressedDataController
{
    public function create(ActionWithSuppressedData $action): JsonResponse
    {
        return response()->json();
    }
}
