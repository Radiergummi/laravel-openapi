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
     * An `OperationNode` carrying `$descriptor`. Document-shaped fields are empty
     * by default — most lint rules resolve everything from the descriptor's
     * reflection rather than from the produced operation. Optional overrides let
     * tests vary the HTTP method, the document-side `pathUri` / `operationId`,
     * or the `raw` swagger-php operation when a rule actually inspects them.
     */
    public static function forDescriptor(
        ActionDescriptor $descriptor,
        string $method = 'GET',
        ?string $pathUri = null,
        ?string $operationId = null,
        ?OA\Operation $raw = null,
    ): OperationNode {
        return new OperationNode(
            pathUri: $pathUri ?? $descriptor->route->uri(),
            method: $method,
            operationId: $operationId,
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
            raw: $raw ?? self::rawForMethod($method),
            webhook: false,
        );
    }

    /**
     * A minimal valid `LintContext`. The plugin lint rules never read it, but
     * `checkOperation()` requires a non-null instance. `$payloadClasses` lets
     * tests opt in to the Data-plugin payload-class detection path.
     *
     * @param list<class-string> $payloadClasses
     */
    public static function emptyContext(array $payloadClasses = []): LintContext
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
            payloadClasses: $payloadClasses,
        );
    }

    private static function rawForMethod(string $method): OA\Operation
    {
        $context = ['_context' => new Context()];

        return match (strtoupper($method)) {
            'POST' => new OA\Post($context),
            'PUT' => new OA\Put($context),
            'DELETE' => new OA\Delete($context),
            'PATCH' => new OA\Patch($context),
            'HEAD' => new OA\Head($context),
            'OPTIONS' => new OA\Options($context),
            default => new OA\Get($context),
        };
    }
}
