<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Support;

use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;

/**
 * Builds minimal lint-tree fixtures for exercising `OperationRule` lint rules
 * in isolation. Shared across the plugin-suite lint-rule tests.
 */
final class OperationNodeFactory
{
    /**
     * A minimal `OperationNode` carrying `$descriptor`. Every document-shaped
     * field is empty: the plugin lint rules resolve everything from the
     * descriptor's reflection, not from the produced operation.
     */
    public static function forDescriptor(ActionDescriptor $descriptor): OperationNode
    {
        return new OperationNode(
            pathUri: $descriptor->route->uri(),
            method: 'GET',
            operationId: null,
            summary: null,
            description: null,
            deprecated: false,
            parameters: [],
            queryParameters: [],
            requestBody: null,
            responses: [],
            security: [],
            tags: [],
            descriptor: $descriptor,
            raw: new OA\Get(['_context' => new Context()]),
            webhook: false,
        );
    }

    /**
     * A minimal valid `LintContext`. The plugin lint rules never read it, but
     * `checkOperation()` requires a non-null instance.
     */
    public static function emptyContext(): LintContext
    {
        $spec = new OA\OpenApi(['openapi' => '3.1.0']);

        return new LintContext(
            api: new ApiNode(
                operations: [],
                components: [],
                webhooks: [],
                declaredTags: [],
                tagDescriptions: [],
                raw: $spec,
            ),
            index: TreeIndex::empty(),
            rawSpec: $spec,
            actionDescriptors: [],
            suppressions: [],
        );
    }
}
