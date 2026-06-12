<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint;

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
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix\RedundantOaAnnotationFixer;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\AuthoredAnnotationShape;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\OaRedundancyEngine;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\OperationSubsumption;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\SchemaEquivalence;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Stages\HarvestAuthoredAnnotationsStage;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use ReflectionException;
use ReflectionMethod;

use function class_exists;
use function sprintf;

/**
 * Flags a hand-authored operation-level swagger-php annotation — an `@OA\Get`/… docblock or
 * `#[OA\Get]`/`#[OA\Response]`/… attribute on a controller method — that the generator now
 * reproduces on its own. The operation-level counterpart of {@see OaRedundantWithInference}.
 *
 * The operation the harvester read ({@see AuthoredAnnotationScanner::operationForMethod()}) is
 * compared against inference's operation for the *same route* ({@see InferenceView::operationForRoute()}).
 * It fires only when inference reproduces everything the author contributed: `summary` /
 * `description` / `operationId` / `tags` by equality, and `responses` / `parameters` / `requestBody`
 * by {@see SchemaEquivalence::subsumes()}. `parameters` / `requestBody` are checked as a conservative
 * guard even though the harvester merges only metadata and `responses`, so an annotation documenting
 * something inference cannot reproduce is kept rather than silently dropped. An `@OA\Response(ref=…)`
 * pointing at a response component the harvester never merges is treated as not reproducible, so the
 * operation is kept.
 *
 * Like the schema rule, declares {@see NeedsInferenceDocument}, is registered only by the
 * off-by-default swagger-php plugin, and sits at the `migration.*` cleanup tier (level 4).
 *
 * @internal
 */
final class OaRedundantOperationWithInference implements Rule, OperationRule, FixableRule, NeedsInferenceDocument
{
    private readonly OaRedundancyEngine $engine;

    private readonly OperationSubsumption $comparator;

    public function __construct(
        private readonly AuthoredAnnotationScanner $scanner,
        SchemaEquivalence $equivalence,
    ) {
        $this->engine = new OaRedundancyEngine();
        $this->comparator = new OperationSubsumption($equivalence);
    }

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

        $finding = $this->engine->evaluate(
            $authored,
            $inferred,
            $this->comparator,
            fn(): ReflectionMethod => new ReflectionMethod($controller, $method),
            fn(AuthoredAnnotationShape $shape): Finding => new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    'The %s on %s::%s restates an operation the generator already infers; it can be removed.',
                    $shape === AuthoredAnnotationShape::Docblock ? '@OA operation docblock' : '#[OA\*] operation attribute',
                    $controller,
                    $method,
                ),
                location: FindingLocation::fromOperation($operation),
                fixHint: 'Remove the redundant swagger-php operation annotation; inference reproduces the same operation.',
                context: [
                    Finding::CONTEXT_SOURCE_CLASS => $controller,
                    Finding::CONTEXT_SOURCE_MEMBER => $method,
                    AuthoredAnnotationShape::FINDING_CONTEXT_KEY => $shape->value,
                ],
            ),
        );

        if ($finding !== null) {
            yield $finding;
        }
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
    public function fixer(): Fixer
    {
        return new RedundantOaAnnotationFixer();
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
    public function description(): string
    {
        return 'A hand-authored @OA / #[OA\*] operation annotation the generator already reproduces via inference.';
    }
}
