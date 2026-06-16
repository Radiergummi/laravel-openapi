<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Routing\ThrowsExtractor;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

use function array_map;
use function class_exists;
use function in_array;
use function ltrim;
use function sprintf;
use function str_contains;
use function str_ends_with;

/**
 * Flags controller methods that type-hint an Action class whose `handle()` method declares
 * `throws` exceptions not redeclared on the controller method.
 *
 * This catches the common mistake of adding a new exception path inside an Action without
 * propagating it to the controller's PHPDoc, which in turn means the OpenAPI spec will lack the
 * corresponding error response.
 */
final readonly class ThrowsTransitiveMissing implements Rule, OperationRuleVisitor
{
    private ThrowsExtractor $throwsExtractor;

    public function __construct(?ThrowsExtractor $throwsExtractor = null)
    {
        $this->throwsExtractor = $throwsExtractor ?? ThrowsExtractor::create();
    }

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->webhook) {
            return;
        }

        $descriptor = $operation->descriptor;
        $method = $descriptor?->method;

        if ($descriptor === null || $method === null) {
            return;
        }

        foreach ($method->getParameters() as $parameter) {
            $actionClass = $this->resolveActionClass($parameter);

            if ($actionClass === null) {
                continue;
            }

            yield from $this->compareThrows($descriptor, $method, $actionClass);
        }
    }

    /**
     * @return null|class-string
     */
    private function resolveActionClass(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $className = $type->getName();

        if (!class_exists($className)) {
            return null;
        }

        if (!$this->isActionClass($className)) {
            return null;
        }

        return $className;
    }

    /**
     * Heuristic: a class is an "Action" if its name ends with `Action` or it lives in a
     * `Domain\*\Actions` namespace.
     */
    private function isActionClass(string $className): bool
    {
        if (str_ends_with($className, 'Action')) {
            return true;
        }

        return str_contains($className, '\\Actions\\');
    }

    /**
     * @param class-string $actionClass
     *
     * @return iterable<Finding>
     */
    private function compareThrows(
        ActionDescriptor $descriptor,
        ReflectionMethod $method,
        string $actionClass,
    ): iterable {
        try {
            $handleMethod = new ReflectionMethod($actionClass, 'handle');
        } catch (ReflectionException) {
            return;
        }

        $exceptionTypes = $this->throwsExtractor->extract($handleMethod);

        if ($exceptionTypes === []) {
            return;
        }

        // Normalize the controller's declared throws to bare FQCNs
        $controllerThrows = array_map(
            static fn(string $fqcn): string => ltrim($fqcn, '\\'),
            $descriptor->throws,
        );

        $actionShortName = $handleMethod->getDeclaringClass()->getShortName();
        $controllerShortName = $descriptor->controller?->getShortName() ?? '(unknown)';
        $methodName = $method->getName();

        foreach ($exceptionTypes as $exceptionType) {
            if ($exceptionType === '' || in_array($exceptionType, $controllerThrows, true)) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    '%s::handle() declares @throws %s, but %s::%s() does not redeclare it',
                    $actionShortName,
                    $exceptionType,
                    $controllerShortName,
                    $methodName,
                ),
                fixHint: sprintf(
                    'Add @throws %s to %s::%s() or add a matching #[ExceptionResponse] attribute.',
                    $exceptionType,
                    $controllerShortName,
                    $methodName,
                ),
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'throws.transitive-missing';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'Action::handle() declares @throws exceptions not redeclared on the controller method.';
    }
}
