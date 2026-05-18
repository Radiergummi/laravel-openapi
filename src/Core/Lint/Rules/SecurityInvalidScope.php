<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Override;

use function in_array;
use function sprintf;

final class SecurityInvalidScope implements Rule, OperationRuleVisitor
{
    /**
     * @param null|list<string> $registeredScopes Known scope identifiers.
     *                                            When null, scopes are resolved
     *                                            from the context index.
     */
    public function __construct(private readonly ?array $registeredScopes = null) {}

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        $knownScopes = $this->registeredScopes ?? $context->index->registeredScopes;

        foreach ($operation->security as $requirement) {
            foreach ($requirement['scopes'] as $scope) {
                if (in_array($scope, $knownScopes, true)) {
                    continue;
                }

                yield new Finding(
                    ruleId: $this->id(),
                    level: $this->level(),
                    message: sprintf(
                        'Operation %s %s references undefined scope "%s"',
                        $operation->method,
                        $operation->pathUri,
                        $scope,
                    ),
                    fixHint: 'Register the scope via Passport::tokensCan() or remove from #[Security(scopes: [...])].',
                );
            }
        }
    }

    #[Override]
    public function id(): string
    {
        return 'security.invalid-scope';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'Operation requires a scope not declared in securitySchemes.';
    }
}
