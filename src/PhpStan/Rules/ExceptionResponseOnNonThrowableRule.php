<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\PhpStan\Rules;

use Override;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\ShouldNotHappenException;
use Radiergummi\OpenApi\Attributes\ExceptionResponse;
use Radiergummi\OpenApi\PhpStan\Support\AttributeHelpers;
use Throwable;

use function assert;

/**
 * Flags `#[ExceptionResponse]` on a class that doesn't implement {@see Throwable}. The generator
 * only reads this attribute on throwable types; on anything else it is silently ignored.
 *
 * @implements Rule<Class_>
 */
final class ExceptionResponseOnNonThrowableRule implements Rule
{
    public function __construct(private readonly ReflectionProvider $reflectionProvider) {}

    #[Override]
    public function getNodeType(): string
    {
        return Class_::class;
    }

    /**
     * @return list<RuleError>
     *
     * @throws ShouldNotHappenException
     */
    #[Override]
    public function processNode(Node $node, Scope $scope): array
    {
        assert($node instanceof Class_);

        if ($node->namespacedName === null) {
            return [];
        }

        $exceptionResponses = AttributeHelpers::attributesNamed(
            AttributeHelpers::getAttributeGroups($node),
            ExceptionResponse::class,
        );

        if ($exceptionResponses === []) {
            return [];
        }

        $fqn = $node->namespacedName->toString();

        if (!$this->reflectionProvider->hasClass($fqn)) {
            return [];
        }

        if ($this->reflectionProvider->getClass($fqn)->implementsInterface(Throwable::class)) {
            return [];
        }

        $errors = [];

        foreach ($exceptionResponses as $attribute) {
            $errors[] = RuleErrorBuilder::message(
                "#[ExceptionResponse] is attached to {$fqn}, which does not implement Throwable — the attribute will be silently ignored.",
            )
                ->identifier('openapi.exceptionResponse.nonThrowable')
                ->line($attribute->getStartLine())
                ->build();
        }

        return $errors;
    }
}
