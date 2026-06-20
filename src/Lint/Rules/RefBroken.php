<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use OpenApi\Annotations as OA;
use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Enums\ComponentType;
use Radiergummi\OpenApi\Lint\AnnotationWalker;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\Visitors\ApiRule as ApiRuleVisitor;
use Radiergummi\OpenApi\Support\Generator\ComponentReference;

use function is_string;
use function property_exists;
use function Radiergummi\OpenApi\is_undefined;
use function sprintf;

/**
 * Reports `$ref` references that point to non-existent components.
 */
final class RefBroken implements Rule, ApiRuleVisitor
{
    public string $id = 'ref.broken';
    public Severity $severity = Severity::Broken;
    public string $description = "A \$ref points to a component that doesn't exist in the spec.";

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

            if (is_undefined($ref) || !is_string($ref)) {
                return;
            }

            if (!$this->refExists($ref, $componentIndex)) {
                $findings[] = new Finding(
                    ruleId: $this->id,
                    severity: $this->severity,
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
     * @param array<string, list<string>> $defined
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
     * @param array<string, array<string, true>> $componentIndex
     */
    private function refExists(string $ref, array $componentIndex): bool
    {
        $parsed = ComponentReference::parse($ref);

        if ($parsed === null) {
            // Not a local component ref (may be external).
            return true;
        }

        ['type' => $type, 'name' => $name] = $parsed;

        // pathItems live inline on operations, not in the components index.
        if ($type === ComponentType::PathItems->value) {
            return true;
        }

        if (!isset($componentIndex[$type])) {
            return false;
        }

        return isset($componentIndex[$type][$name]);
    }



}
