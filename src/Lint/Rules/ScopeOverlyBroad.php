<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\SecuritySchemeTypes;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;

use function array_diff;
use function count;
use function sprintf;

/**
 * Reports when an operation's only OAuth scope is the wildcard `*` and more specific scopes are
 * available in Passport. Using `*` alone defeats the purpose of scope-based access control.
 */
final readonly class ScopeOverlyBroad implements Rule, OperationRuleVisitor
{
    /**
     * @param null|list<string> $registeredScopes Known scope identifiers. When null, scopes are
     *                                            resolved from the context index.
     */
    public function __construct(private ?array $registeredScopes = null) {}

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        $knownScopes = $this->registeredScopes ?? $context->index->registeredScopes;

        // No specific scopes registered (or only the wildcard): nothing to flag.
        if (array_diff($knownScopes, ['*']) === []) {
            return;
        }

        $schemeTypes = SecuritySchemeTypes::fromSpec($context->rawSpec);

        foreach ($operation->security as $requirement) {
            // Only oauth2/oidc schemes carry a scope registry.
            if (!$schemeTypes->carriesScopes($requirement['scheme'])) {
                continue;
            }

            $scopeList = $requirement['scopes'];

            if (count($scopeList) === 1 && $scopeList[0] === '*') {
                yield new Finding(
                    ruleId: $this->id(),
                    level: $this->level(),
                    message: sprintf(
                        'Operation %s %s uses only the wildcard scope "*" — consider using more specific scopes',
                        $operation->method->forDisplay(),
                        $operation->pathUri,
                    ),
                    fixHint: 'Replace the "*" scope with specific scopes from Passport::tokensCan().',
                );
            }
        }
    }

    #[Override]
    public function id(): string
    {
        return 'scope.overly-broad';
    }

    #[Override]
    public function level(): int
    {
        return 3;
    }

    #[Override]
    public function description(): string
    {
        return 'Operation requires a scope that is broader than the resource warrants.';
    }
}
