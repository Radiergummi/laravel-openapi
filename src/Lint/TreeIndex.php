<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;

use function is_string;
use function preg_match;
use function property_exists;
use function strtoupper;

/**
 * Provides precomputed cross-reference lookups built from the domain tree.
 */
final readonly class TreeIndex
{
    /**
     * @param array<string, OperationNode>       $operationsByOperationId operationId → node
     * @param array<string, OperationNode>       $operationsByRouteKey    "GET /api/v0/foo" → node
     * @param array<string, ComponentSchemaNode> $componentsByName        schema name → node
     * @param array<string, true>                $referencedComponents    "type/name" → referenced
     * @param list<string>                       $registeredScopes        All registered security scope IDs
     * @param list<string>                       $knownRuleIds            All known rule IDs
     */
    public function __construct(
        public array $operationsByOperationId,
        public array $operationsByRouteKey,
        public array $componentsByName,
        public array $referencedComponents,
        public array $registeredScopes,
        public array $knownRuleIds,
    ) {}

    /**
     * Create an empty index, useful for tests that don't need cross-references.
     */
    public static function empty(): self
    {
        return new self(
            operationsByOperationId: [],
            operationsByRouteKey: [],
            componentsByName: [],
            referencedComponents: [],
            registeredScopes: [],
            knownRuleIds: [],
        );
    }

    /**
     * Build the index from the domain tree and raw spec.
     *
     * @param list<string> $knownRuleIds
     * @param list<string> $registeredScopes
     */
    public static function build(
        ApiNode $api,
        OA\OpenApi $rawSpec,
        array $knownRuleIds,
        array $registeredScopes,
    ): self {
        $operationsByOperationId = [];
        $operationsByRouteKey = [];

        foreach ($api->operations as $operation) {
            if ($operation->operationId !== null) {
                $operationsByOperationId[$operation->operationId] = $operation;
            }

            $routeKey = strtoupper($operation->method) . ' ' . $operation->pathUri;
            $operationsByRouteKey[$routeKey] = $operation;
        }

        // Also index webhook operations
        foreach ($api->webhooks as $webhook) {
            $op = $webhook->operation;

            if ($op->operationId !== null) {
                $operationsByOperationId[$op->operationId] = $op;
            }
        }

        $componentsByName = [];

        foreach ($api->components as $component) {
            $componentsByName[$component->name] = $component;
        }

        $referencedComponents = self::collectReferencedComponents($rawSpec);

        return new self(
            operationsByOperationId: $operationsByOperationId,
            operationsByRouteKey: $operationsByRouteKey,
            componentsByName: $componentsByName,
            referencedComponents: $referencedComponents,
            registeredScopes: $registeredScopes,
            knownRuleIds: $knownRuleIds,
        );
    }

    /**
     * Recursively scan the raw spec for all $ref strings pointing to local components.
     *
     * @return array<string, true> Keys are "type/name" (e.g. "schemas/User")
     */
    private static function collectReferencedComponents(OA\OpenApi $spec): array
    {
        $keys = [];

        AnnotationWalker::walk($spec, static function (OA\AbstractAnnotation $annotation) use (&$keys): void {
            if (!property_exists($annotation, 'ref')) {
                return;
            }

            $ref = $annotation->ref;

            if (
                $ref === Generator::UNDEFINED
                || $ref === null
                || !is_string($ref)
            ) {
                return;
            }

            if (preg_match('~^#/components/([^/]+)/(.+)$~', $ref, $matches)) {
                $keys[$matches[1] . '/' . $matches[2]] = true;
            }
        });

        return $keys;
    }
}
