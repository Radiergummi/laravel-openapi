<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\PhpStan\Rules;

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
 * Flags `#[ExceptionResponse]` on a class that doesn't implement {@see Throwable}. The
 * {@see \Radiergummi\OpenApi\Core\ErrorContributors\ThrowsErrorContributor} only consults the attribute
 * when resolving `@throws` FQCNs to response shapes, so an attribute on a non-throwable class
 * is dead documentation that the generator silently skips.
 *
 * Only `Stmt\Class_` nodes are visited. Anonymous classes (no `namespacedName`) and unresolvable
 * names are skipped — PHPStan's own checks catch missing classes, and the per-class hierarchy
 * lookup requires a known FQN.
 *
 * @implements Rule<Class_>
 */
final class ExceptionResponseOnNonThrowableRule implements Rule
{
    public function __construct(private readonly ReflectionProvider $reflectionProvider) {}

    public function getNodeType(): string
    {
        return Class_::class;
    }

    /**
     * @return list<RuleError>
     *
     * @throws ShouldNotHappenException
     */
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
