<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\AnnotationWalker;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\Visitors\ApiRule as ApiRuleVisitor;

use function sprintf;

/**
 * Reports components that are defined but never referenced via `$ref`.
 * Checks all component types against the precomputed index of referenced components.
 */
final class ComponentOrphaned implements Rule, ApiRuleVisitor
{
    public string $id = 'component.orphaned';
    public Severity $severity = Severity::Inconsistent;
    public string $description = 'Component schema is registered but never referenced.';

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
                    ruleId: $this->id,
                    severity: $this->severity,
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



}
