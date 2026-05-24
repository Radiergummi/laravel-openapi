<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Lint\AnnotationWalker;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\FindingLocation;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\ApiRule as ApiRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;

use function sprintf;

/**
 * Reports components that are defined but never referenced via `$ref`.
 *
 * Orphaned components increase spec size and cognitive load without adding value. This rule checks
 * all component types from the raw spec against the precomputed index of referenced components.
 */
final class ComponentOrphaned implements Rule, ApiRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkApi(ApiNode $api, LintContext $context): iterable
    {
        $referencedKeys = $context->index->referencedComponents;
        $definedComponents = AnnotationWalker::collectDefinedComponents($context->rawSpec);

        foreach ($definedComponents as $type => $names) {
            foreach ($names as $name) {
                $refKey = "{$type}/{$name}";

                if (isset($referencedKeys[$refKey])) {
                    continue;
                }

                yield new Finding(
                    ruleId: $this->id(),
                    level: $this->level(),
                    message: sprintf(
                        'Component #/components/%s/%s is defined but never referenced',
                        $type,
                        $name,
                    ),
                    location: new FindingLocation(jsonPointer: "#/components/{$type}/{$name}"),
                    fixHint: 'Remove the unused component or add a $ref to it.',
                    context: ['component' => $name],
                );
            }
        }
    }

    #[Override]
    public function id(): string
    {
        return 'component.orphaned';
    }

    #[Override]
    public function level(): int
    {
        return 3;
    }

    #[Override]
    public function description(): string
    {
        return 'Component schema is registered but never referenced.';
    }
}
