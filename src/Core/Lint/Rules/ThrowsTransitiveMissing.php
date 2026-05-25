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
use phpDocumentor\Reflection\DocBlock\Tags\Throws;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\DocBlockFactoryInterface;
use phpDocumentor\Reflection\Types\ContextFactory;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
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
 * `@throws` exceptions not redeclared on the controller method.
 *
 * This catches the common mistake of adding a new exception path inside an Action without
 * propagating it to the controller's PHPDoc, which in turn means the OpenAPI spec will lack the
 * corresponding error response.
 */
final readonly class ThrowsTransitiveMissing implements Rule, OperationRuleVisitor
{
    private DocBlockFactoryInterface $docBlockFactory;

    private ContextFactory $contextFactory;

    public function __construct(
        ?DocBlockFactoryInterface $docBlockFactory = null,
        ?ContextFactory $contextFactory = null,
    ) {
        $this->docBlockFactory = $docBlockFactory ?? DocBlockFactory::createInstance();
        $this->contextFactory = $contextFactory ?? new ContextFactory();
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
     * Resolve the parameter type to an Action class name, or null if not an Action.
     *
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
     * Parse `@throws` from the Action's `handle()` method and emit a finding for each exception
     * not declared on the controller method.
     *
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

        $docComment = $handleMethod->getDocComment();

        if ($docComment === false) {
            return;
        }

        // Resolve the docblock against the Action's namespace and use-statements so short
        // `@throws` names (e.g. `NotFoundException`) become FQCNs and can be compared against
        // the controller's resolved throws.
        $context = $this->contextFactory->createFromReflector($handleMethod);
        $docBlock = $this->docBlockFactory->create($docComment, $context);
        $throwsTags = $docBlock->getTagsByName('throws');

        if ($throwsTags === []) {
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

        foreach ($throwsTags as $tag) {
            if (!$tag instanceof Throws) {
                continue;
            }

            $exceptionType = ltrim((string) $tag->getType(), '\\');

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
