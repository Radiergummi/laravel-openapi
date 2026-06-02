<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix;

use Illuminate\Http\JsonResponse;
use Radiergummi\OpenApi\Attributes\Link;

/**
 * Dedicated fixture for the `openapi:lint --fix` / `--check` command feature test. The duplicate
 * `#[Link]` name triggers `link.duplicate-name` (detected by reflection on the method, so it fires
 * end-to-end regardless of generation). The test snapshots and restores this file around `--fix`.
 */
class FixCommandLinkController
{
    #[Link(name: 'self', operationId: 'reports.show')]
    #[Link(name: 'self', operationId: 'reports.show')]
    public function index(): JsonResponse
    {
        return response()->json();
    }
}
