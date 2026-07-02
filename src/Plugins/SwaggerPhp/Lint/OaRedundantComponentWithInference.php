<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint;

use OpenApi\Annotations as OA;
use Override;
use Radiergummi\OpenApi\Contracts\Lint\NeedsInferenceDocument;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Enums\ComponentType;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\Fix\FixableRule;
use Radiergummi\OpenApi\Lint\Fix\Fixer;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\Visitors\ApiRule;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix\RedundantOaAnnotationFixer;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\AuthoredAnnotationShape;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\ComponentSubsumption;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\ScannerInferenceRefResolver;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\SchemaEquivalence;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use Radiergummi\OpenApi\Support\Generator\ComponentReference;
use ReflectionClass;
use ReflectionException;

use function array_keys;
use function class_exists;
use function is_string;
use function sprintf;

/**
 * Flags a hand-authored reusable `@OA\Response` / `@OA\Parameter` **component definition** the
 * generator now reproduces inline via inference. Component-pool counterpart of
 * {@see OaRedundantOperationWithInference}.
 *
 * Vanilla inference never emits reusable `#/components/responses|parameters/*` definitions: it
 * inlines responses and parameters into operations. The redundancy oracle is therefore usage-site,
 * threaded through the `$ref`: a component is removable when (a) at least one operation `$ref`s it
 * and (b) every such operation's inference-only counterpart already produces an equivalent inline
 * response/parameter. A component still `$ref`-ed by another surviving annotation (a schema, an
 * unrelated operation, or another component/alias) is kept to avoid dangling the reference.
 *
 * @internal
 */
final class OaRedundantComponentWithInference implements Rule, ApiRule, FixableRule, NeedsInferenceDocument
{
    public string $id = 'migration.oa-redundant-component-with-inference';
    public Severity $severity = Severity::Improvable;
    public string $description = 'A hand-authored reusable @OA\Response / @OA\Parameter component definition the generator already reproduces via inference.';

    public function __construct(
        private readonly AuthoredAnnotationScanner $scanner,
    ) {}

    /**
     * @return iterable<Finding>
     *
     * @throws ReflectionException
     */
    #[Override]
    public function checkApi(ApiNode $api, LintContext $context): iterable
    {
        $comparator = new ComponentSubsumption(
            new SchemaEquivalence(new ScannerInferenceRefResolver($this->scanner, $context->inference)),
        );

        foreach ($this->scanner->responseComponentDefinitions() as $name => $component) {
            $finding = $this->evaluateResponse($name, $component, $comparator, $context);

            if ($finding !== null) {
                yield $finding;
            }
        }

        foreach ($this->scanner->parameterComponentDefinitions() as $name => $component) {
            $finding = $this->evaluateParameter($name, $component, $comparator, $context);

            if ($finding !== null) {
                yield $finding;
            }
        }
    }

    /**
     * @throws ReflectionException
     */
    private function evaluateResponse(
        string $name,
        OA\Response $component,
        ComponentSubsumption $comparator,
        LintContext $context,
    ): ?Finding {
        $pointer = ComponentReference::pointer($name, ComponentType::Responses);
        $referencing = $this->scanner->operationsReferencing($pointer);

        if ($referencing === []) {
            return null;
        }

        foreach ($referencing as $operation) {
            $inferred = $this->inferredFor($operation, $context);
            $status = $comparator->referencingStatus($operation, $pointer);

            if ($inferred === null || $status === null || !$comparator->responseSubsumed($inferred, $component, $status)) {
                return null;
            }
        }

        return $this->buildFinding($name, $pointer, array_keys($referencing), ComponentType::Responses, $component);
    }

    /**
     * @throws ReflectionException
     */
    private function evaluateParameter(
        string $name,
        OA\Parameter $component,
        ComponentSubsumption $comparator,
        LintContext $context,
    ): ?Finding {
        $pointer = ComponentReference::pointer($name, ComponentType::Parameters);
        $referencing = $this->scanner->operationsReferencing($pointer);

        if ($referencing === []) {
            return null;
        }

        foreach ($referencing as $operation) {
            $inferred = $this->inferredFor($operation, $context);

            if ($inferred === null || !$comparator->parameterSubsumed($inferred, $component)) {
                return null;
            }
        }

        return $this->buildFinding($name, $pointer, array_keys($referencing), ComponentType::Parameters, $component);
    }

    private function inferredFor(OA\Operation $operation, LintContext $context): ?OA\Operation
    {
        $method = is_string($operation->method) ? $operation->method : null;
        $path = is_string($operation->path) ? $operation->path : null;

        if ($method === null || $path === null) {
            return null;
        }

        return $context->inference->operationForRoute($method, $path);
    }

    /**
     * @param list<string> $referencingKeys "declaringClass::method" keys of the collapsing consumers
     *
     * @throws ReflectionException
     */
    private function buildFinding(
        string $name,
        string $pointer,
        array $referencingKeys,
        ComponentType $type,
        OA\Response|OA\Parameter $component,
    ): ?Finding {
        // Keep a component another surviving annotation still points at, to avoid a dangling $ref.
        if ($this->scanner->isComponentReferencedByOtherAuthored($pointer, $name, $referencingKeys)) {
            return null;
        }

        $class = $this->scanner->componentClassFor($name);

        if ($class === null || !class_exists($class)) {
            return null;
        }

        $shape = AuthoredAnnotationShape::detect(new ReflectionClass($class));

        if ($shape === null) {
            return null;
        }

        $kind = $type === ComponentType::Responses ? '@OA\Response' : '@OA\Parameter';

        return new Finding(
            ruleId: $this->id,
            severity: $this->severity,
            message: sprintf(
                'The reusable %s component "%s" on %s restates a %s the generator already infers; it can be removed.',
                $kind,
                $name,
                $class,
                $type === ComponentType::Responses ? 'response' : 'parameter',
            ),
            location: new FindingLocation(file: $component->_context->filename ?? null),
            fixHint: 'Remove the redundant swagger-php component definition; inference reproduces the same response/parameter.',
            context: [
                Finding::CONTEXT_SOURCE_CLASS => $class,
                AuthoredAnnotationShape::FINDING_CONTEXT_KEY => $shape->value,
                RedundantOaAnnotationFixer::CONTEXT_COMPONENT_NAME => $name,
            ],
        );
    }



    #[Override]
    public function fixer(): Fixer
    {
        return new RedundantOaAnnotationFixer();
    }
}
