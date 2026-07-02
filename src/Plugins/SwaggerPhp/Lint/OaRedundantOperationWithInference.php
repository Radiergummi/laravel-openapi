<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\NeedsInferenceDocument;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\Fix\FixableRule;
use Radiergummi\OpenApi\Lint\Fix\Fixer;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix\RedundantOaAnnotationFixer;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\AuthoredAnnotationShape;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\OaRedundancyEngine;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\OperationSubsumption;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\SchemaEquivalence;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use ReflectionException;
use ReflectionMethod;

use function class_exists;
use function sprintf;

/**
 * Flags a hand-authored `@OA\Get`/`#[OA\Get]` (etc.) on a controller method that inference now
 * reproduces entirely. Operation-level counterpart of {@see OaRedundantWithInference}.
 *
 * Fires only when inference subsumes everything the annotation contributed: metadata fields by
 * equality, `responses` / `parameters` / `requestBody` by {@see SchemaEquivalence::subsumes()}.
 * An `@OA\Response(ref=…)` pointing at a component the harvester never merges keeps the operation.
 *
 * @internal
 */
final class OaRedundantOperationWithInference implements Rule, OperationRule, FixableRule, NeedsInferenceDocument
{
    public string $id = 'migration.oa-redundant-operation-with-inference';
    public Severity $severity = Severity::Improvable;
    public string $description = 'A hand-authored @OA / #[OA\*] operation annotation the generator already reproduces via inference.';

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

        $inferred = $context->inference->operationForRoute(
            $operation->method->value,
            $operation->pathUri,
        );

        $finding = $this->engine->evaluate(
            $authored,
            $inferred,
            $this->comparator,
            fn(): ReflectionMethod => new ReflectionMethod($controller, $method),
            fn(AuthoredAnnotationShape $shape): Finding => new Finding(
                ruleId: $this->id,
                severity: $this->severity,
                message: sprintf(
                    'The %s on %s::%s restates an operation the generator already infers; it can be removed.',
                    $shape === AuthoredAnnotationShape::Docblock
                        ? '@OA operation docblock'
                        : '#[OA\*] operation attribute',
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
    public function fixer(): Fixer
    {
        return new RedundantOaAnnotationFixer();
    }
}
