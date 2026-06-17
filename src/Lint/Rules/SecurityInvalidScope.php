<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\SecuritySchemeTypes;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;

use function in_array;
use function sprintf;

final readonly class SecurityInvalidScope implements Rule, OperationRuleVisitor
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

        // An empty scope list means scope coverage cannot be verified: either no scopes are
        // registered or Passport is not installed. Skip the check rather than flagging every
        // spec scope as undefined (false positives).
        if ($knownScopes === []) {
            return;
        }

        $schemeTypes = SecuritySchemeTypes::fromSpec($context->rawSpec);

        foreach ($operation->security as $requirement) {
            // Only oauth2/oidc schemes carry a scope registry. Skip non-scope-bearing schemes
            // (e.g., Sanctum's http/bearer scheme, whose abilities surface as scopes).
            if (!$schemeTypes->carriesScopes($requirement['scheme'])) {
                continue;
            }

            foreach ($requirement['scopes'] as $scope) {
                if (in_array($scope, $knownScopes, true)) {
                    continue;
                }

                yield new Finding(
                    ruleId: $this->id(),
                    severity: $this->severity(),
                    message: sprintf(
                        'Operation %s %s references undefined scope "%s"',
                        $operation->method->forDisplay(),
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
    public function severity(): Severity
    {
        return Severity::Degraded;
    }

    #[Override]
    public function description(): string
    {
        return 'Operation requires a scope not declared in securitySchemes.';
    }
}
