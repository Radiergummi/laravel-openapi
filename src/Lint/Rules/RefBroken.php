<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Enums\ComponentType;
use Radiergummi\OpenApi\Lint\AnnotationWalker;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\Visitors\ApiRule as ApiRuleVisitor;

use function is_string;
use function preg_match;
use function property_exists;
use function sprintf;

/**
 * Reports broken `$ref` references that point to non-existent components.
 *
 * Walks all annotations recursively and checks every `$ref` string against the available component
 * maps (schemas, responses, parameters, etc.).
 */
final class RefBroken implements Rule, ApiRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkApi(ApiNode $api, LintContext $context): iterable
    {
        $componentIndex = $this->buildComponentIndex(
            AnnotationWalker::collectDefinedComponents($context->rawSpec),
        );
        $findings = [];

        AnnotationWalker::walk($context->rawSpec, function (OA\AbstractAnnotation $annotation) use (
            $componentIndex,
            &$findings,
        ): void {
            if (!property_exists($annotation, 'ref')) {
                return;
            }

            $ref = $annotation->ref;

            if ($ref === Generator::UNDEFINED || !is_string($ref)) {
                return;
            }

            if (!$this->refExists($ref, $componentIndex)) {
                $findings[] = new Finding(
                    ruleId: $this->id(),
                    level: $this->level(),
                    message: sprintf('Broken $ref "%s": referenced component does not exist', $ref),
                    location: new FindingLocation(jsonPointer: $ref),
                    fixHint: 'Ensure the referenced component is defined under #/components/.',
                    context: ['ref' => $ref],
                );
            }
        });

        return $findings;
    }

    /**
     * Convert the shared defined-components map into a set for O(1) existence checks.
     *
     * @param array<string, list<string>> $defined Output of AnnotationWalker::collectDefinedComponents
     *
     * @return array<string, array<string, true>>
     */
    private function buildComponentIndex(array $defined): array
    {
        return array_map(
            static fn(array $names): array => array_fill_keys($names, true),
            $defined,
        );
    }

    /**
     * Check whether a `$ref` string points to an existing component.
     *
     * @param array<string, array<string, true>> $componentIndex
     */
    private function refExists(string $ref, array $componentIndex): bool
    {
        if (!preg_match('~^#/components/([^/]+)/(.+)$~', $ref, $matches)) {
            // Not a local component ref (could be external) – skip
            return true;
        }

        [, $type, $name] = $matches;

        // pathItems are not indexed as components (they live inline on
        // operations as callbacks), so skip ref validation for them rather
        // than report a false positive.
        if ($type === ComponentType::PathItems->value) {
            return true;
        }

        if (!isset($componentIndex[$type])) {
            return false;
        }

        return isset($componentIndex[$type][$name]);
    }

    #[Override]
    public function id(): string
    {
        return 'ref.broken';
    }

    #[Override]
    public function level(): int
    {
        return 0;
    }

    #[Override]
    public function description(): string
    {
        return "A \$ref points to a component that doesn't exist in the spec.";
    }
}
