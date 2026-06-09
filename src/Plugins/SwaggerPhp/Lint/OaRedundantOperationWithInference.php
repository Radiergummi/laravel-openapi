<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint;

use OpenApi\Annotations as OA;
use Override;
use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Contracts\Lint\NeedsInferenceDocument;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\Fix\FixableRule;
use Radiergummi\OpenApi\Lint\Fix\Fixer;
use Radiergummi\OpenApi\Lint\InferenceView;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix\RedundantOaOperationFixer;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\AuthoredSchemaShape;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\SchemaEquivalence;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Stages\HarvestAuthoredAnnotationsStage;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use ReflectionException;
use ReflectionMethod;

use function class_exists;
use function in_array;
use function is_array;
use function Radiergummi\OpenApi\is_defined;
use function sprintf;

/**
 * Flags a hand-authored operation-level swagger-php annotation — an `@OA\Get`/`@OA\Post`/… docblock
 * or `#[OA\Get]`/`#[OA\Response]`/… attribute on a controller method — whose contribution the
 * generator now reproduces on its own, so it can be deleted as the codebase moves onto inference.
 * The operation-level counterpart of {@see OaRedundantWithInference} (which handles class-level
 * `#[OA\Schema]` definitions).
 *
 * The verdict mirrors the schema rule's: the operation the harvester read for this controller method
 * ({@see AuthoredAnnotationScanner::operationForMethod()}) is compared against inference's operation
 * for the *same route* in the inference-only view the runner builds and hands in via
 * {@see InferenceView::operationForRoute()}. It fires only when inference reproduces everything
 * the author contributed, field by field: `summary` / `description` / `operationId` / `tags` by
 * equality, and `responses` / `parameters` / `requestBody` by {@see SchemaEquivalence::subsumes()}.
 * The harvester merges only the metadata and `responses`; `parameters` / `requestBody` are checked
 * too as a conservative guard, so an annotation documenting a parameter or body inference cannot
 * reproduce is kept rather than silently dropped. A `description` inference cannot derive, or a
 * response shape that exists only at runtime, likewise keeps the annotation load-bearing.
 *
 * Removing an operation annotation cannot dangle a `$ref`: an operation defines no reusable component
 * another annotation could reference. The one referencing case — an `@OA\Response(ref="…")` pointing
 * at a response component the harvester never merges — is treated as not reproducible, so such an
 * operation is kept rather than removed.
 *
 * Like the schema rule it declares {@see NeedsInferenceDocument} (sharing the same inference-only
 * control document), is registered only by the off-by-default swagger-php plugin, and sits at the
 * `migration.*` cleanup tier (level 4) so it stays off ordinary runs until requested
 * (`openapi:lint --only 'migration.*'`).
 *
 * @internal
 */
final class OaRedundantOperationWithInference implements Rule, OperationRule, FixableRule, NeedsInferenceDocument
{
    public const string CONTEXT_SHAPE = 'oaOperationShape';

    public function __construct(
        private readonly AuthoredAnnotationScanner $scanner,
        private readonly SchemaEquivalence $equivalence,
    ) {}

    /**
     * @return iterable<Finding>
     *
     * @throws ReflectionException
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        $controller = $operation->descriptor?->controller?->getName();
        $method = $operation->descriptor?->method?->getName();

        if ($controller === null || $method === null || !class_exists($controller)) {
            return;
        }

        $authored = $this->scanner->operationForMethod($controller, $method);

        if ($authored === null) {
            return;
        }

        $inferred = $context->inference->operationForRoute($operation->method->value, $operation->pathUri);

        // Inference produces no operation for this route: the annotation is load-bearing — keep it.
        if ($inferred === null) {
            return;
        }

        // Fire only when inference reproduces everything the author contributed (and possibly more).
        if (!$this->subsumesAuthoredOperation($inferred, $authored)) {
            return;
        }

        $shape = AuthoredSchemaShape::detect(new ReflectionMethod($controller, $method));

        if ($shape === null) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'The %s on %s::%s restates an operation the generator already infers; it can be removed.',
                $shape === AuthoredSchemaShape::Docblock ? '@OA operation docblock' : '#[OA\*] operation attribute',
                $controller,
                $method,
            ),
            location: FindingLocation::fromOperation($operation),
            fixHint: 'Remove the redundant swagger-php operation annotation; inference reproduces the same operation.',
            context: [
                Finding::CONTEXT_SOURCE_CLASS => $controller,
                Finding::CONTEXT_SOURCE_MEMBER => $method,
                self::CONTEXT_SHAPE => $shape->value,
            ],
        );
    }

    /**
     * Whether the inferred operation reproduces every field the harvester merges from the authored
     * one. Scalar metadata must match exactly; collections (`tags`) and schema-bearing members
     * (`responses` / `parameters` / `requestBody`) must be subsumed by inference's. Routing identity
     * (`path` / `method`) is not compared — both sides describe the same route by construction.
     */
    private function subsumesAuthoredOperation(OA\Operation $inferred, OA\Operation $authored): bool
    {
        foreach (['summary', 'description', 'operationId'] as $field) {
            if (!is_defined($authored->{$field})) {
                continue;
            }

            if (!is_defined($inferred->{$field}) || $authored->{$field} !== $inferred->{$field}) {
                return false;
            }
        }

        if (is_defined($authored->tags) && is_array($authored->tags)) {
            $inferredTags = is_array($inferred->tags) ? $inferred->tags : [];

            foreach ($authored->tags as $tag) {
                if (!in_array($tag, $inferredTags, true)) {
                    return false;
                }
            }
        }

        if (is_defined($authored->parameters) && is_array($authored->parameters)) {
            $inferredParameters = is_array($inferred->parameters) ? $inferred->parameters : [];

            foreach ($authored->parameters as $parameter) {
                if (!$this->anySubsumes($inferredParameters, $parameter)) {
                    return false;
                }
            }
        }

        if (is_defined($authored->requestBody)) {
            if (!is_defined($inferred->requestBody)
                || !$this->equivalence->subsumes($inferred->requestBody, $authored->requestBody)
            ) {
                return false;
            }
        }

        if (is_defined($authored->responses) && is_array($authored->responses)) {
            $inferredResponses = is_array($inferred->responses) ? $inferred->responses : [];

            foreach ($authored->responses as $response) {
                if (!is_defined($response->response)) {
                    continue;
                }

                // A response that is itself a `$ref` to a response component the harvester never
                // merges cannot be reproduced by inference — keep the annotation.
                if (is_defined($response->ref) || !$this->anySubsumesResponse($inferredResponses, $response)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Whether some element of `$inferred` subsumes `$authored`.
     *
     * @param array<array-key, OA\AbstractAnnotation> $inferred
     */
    private function anySubsumes(array $inferred, OA\AbstractAnnotation $authored): bool
    {
        foreach ($inferred as $candidate) {
            if ($this->equivalence->subsumes($candidate, $authored)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the inferred response for the authored response's status subsumes it.
     *
     * @param array<array-key, OA\Response> $inferred
     */
    private function anySubsumesResponse(array $inferred, OA\Response $authored): bool
    {
        foreach ($inferred as $candidate) {
            if ((string) $candidate->response === (string) $authored->response
                && $this->equivalence->subsumes($candidate, $authored)
            ) {
                return true;
            }
        }

        return false;
    }

    #[Override]
    public function fixer(): Fixer
    {
        return new RedundantOaOperationFixer();
    }

    /**
     * The inference-only view this rule compares against is the document with the authored-annotation
     * harvest excluded — i.e. pure inference, no harvested operations.
     *
     * @return list<class-string<SpecStage>>
     */
    #[Override]
    public function excludedStages(): array
    {
        return [HarvestAuthoredAnnotationsStage::class];
    }

    #[Override]
    public function id(): string
    {
        return 'migration.oa-redundant-operation-with-inference';
    }

    #[Override]
    public function level(): int
    {
        // A redundant annotation is a cleanup opportunity, not a spec defect — the generated
        // document is correct with or without it.
        return 4;
    }

    #[Override]
    public function description(): string
    {
        return 'A hand-authored @OA / #[OA\*] operation annotation the generator already reproduces via inference.';
    }
}
