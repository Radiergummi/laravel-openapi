<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SpatieData\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Attributes\PathParam;
use Radiergummi\OpenApi\Attributes\RequestField;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Support\Extraction\PayloadParameterScanner;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use Spatie\LaravelData\Data;

use function sprintf;

/**
 * Flags a scoped field attribute placed where its scope does not apply: #[RequestField] on a
 * controller route parameter, or #[PathParam] on a Data-class request-body property.
 *
 * Detection is limited to operation method parameters, where the scope is unambiguous.
 * Property-level placement on a Data class that backs a response is intentionally not checked —
 * a Data class may serve either direction.
 *
 * Data classes injected through Domain Actions (the standard write-endpoint pattern) are also
 * checked by using {@see PayloadParameterScanner}, which descends into Action constructor
 * parameters the same way the request-schema resolver does.
 */
final readonly class FieldAttributeWrongScope implements Rule, OperationRuleVisitor
{
    public function __construct(
        private PayloadParameterScanner $scanner,
    ) {}

    /**
     * @return iterable<Finding>
     *
     * @throws ReflectionException
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->webhook) {
            return [];
        }

        $method = $operation->descriptor?->method;

        if ($method === null) {
            return [];
        }

        // Use PayloadParameterScanner so Action-indirected Data classes are reached (e.g.
        // CreateProjectAction whose constructor takes CreateProjectData).
        $dataClass = $this->scanner->candidateOfType($method, Data::class);

        if ($dataClass !== null) {
            yield from $this->checkDataClass(new ReflectionClass($dataClass));
        }

        // Route parameters (non-Data typed params) are checked for misplaced #[RequestField].
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();

            $isData = $type instanceof ReflectionNamedType
                && !$type->isBuiltin()
                && is_a($type->getName(), Data::class, allow_string: true);

            if (!$isData) {
                yield from $this->checkRouteParameter($param, $operation);
            }
        }
    }

    /**
     * @param ReflectionClass<Data> $class
     *
     * @return iterable<Finding>
     */
    private function checkDataClass(ReflectionClass $class): iterable
    {
        foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getAttributes(PathParam::class) === []) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    '#[PathParam] on %s::$%s applies to URI parameters, not request-body fields',
                    $property->getDeclaringClass()->getName(),
                    $property->getName(),
                ),
                fixHint: 'Use #[RequestField] for a request-body field.',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'field.attribute-wrong-scope';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    /** @return iterable<Finding> */
    private function checkRouteParameter(ReflectionParameter $param, OperationNode $operation): iterable
    {
        if ($param->getAttributes(RequestField::class) === []) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                '#[RequestField] on URI parameter $%s of %s %s applies to request-body fields, not URI parameters',
                $param->getName(),
                $operation->method->forDisplay(),
                $operation->pathUri,
            ),
            fixHint: 'Use #[PathParam] for a URI parameter.',
        );
    }

    #[Override]
    public function description(): string
    {
        return '#[RequestField] on a URI parameter, or #[PathParam] on a Data-class property.';
    }
}
