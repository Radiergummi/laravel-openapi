<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

it('exposes the assembled spec, action descriptors, and parsed suppressions', function (): void {
    $spec = new OA\OpenApi([]);
    $descriptors = [];
    $suppressions = [];

    $api = new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec);
    $index = TreeIndex::empty();

    $ctx = new LintContext(
        api: $api,
        index: $index,
        rawSpec: $spec,
        actionDescriptors: $descriptors,
        suppressions: $suppressions,
    );

    expect($ctx->rawSpec)->toBe($spec)
        ->and($ctx->api)->toBe($api)
        ->and($ctx->index)->toBe($index)
        ->and($ctx->actionDescriptors)->toBe($descriptors)
        ->and($ctx->suppressions)->toBe($suppressions);
});
