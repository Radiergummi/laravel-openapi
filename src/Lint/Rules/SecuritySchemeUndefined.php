<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use OpenApi\Annotations as OA;
use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Lint\Visitors\Resettable;

use function in_array;
use function is_array;
use function Radiergummi\OpenApi\is_defined;
use function Radiergummi\OpenApi\is_undefined;
use function sprintf;

/**
 * Reports when an operation's `security` array references a scheme not declared in
 * `components.securitySchemes`. Distinct from {@see SecurityInvalidScope}, which checks scope names.
 */
final class SecuritySchemeUndefined implements Rule, OperationRuleVisitor, Resettable
{
    /** @var null|list<string> Memoized for the duration of a single walk. */
    private ?array $declaredSchemes = null;

    #[Override]
    public function reset(): void
    {
        $this->declaredSchemes = null;
    }

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        $declaredSchemes = $this->declaredSchemes
            ??= $this->collectDeclaredSchemes($context->rawSpec);

        foreach ($operation->security as $requirement) {
            $schemeName = $requirement['scheme'];

            if (in_array($schemeName, $declaredSchemes, true)) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    'Operation %s %s references undefined security scheme "%s"',
                    $operation->method->forDisplay(),
                    $operation->pathUri,
                    $schemeName,
                ),
                fixHint: 'Declare the scheme in components.securitySchemes or remove it from the operation\'s security array.',
            );
        }
    }

    /**
     * @return list<string>
     */
    private function collectDeclaredSchemes(OA\OpenApi $spec): array
    {
        $components = $spec->components;

        if (is_undefined($components) || $components === null) {
            return [];
        }

        $schemes = $components->securitySchemes;

        if (is_undefined($schemes) || !is_array($schemes)) {
            return [];
        }

        $names = [];

        foreach ($schemes as $scheme) {
            if (is_undefined($scheme)) {
                continue;
            }

            if (
                is_defined($scheme->securityScheme)
                && $scheme->securityScheme !== null
            ) {
                $names[] = $scheme->securityScheme;
            }
        }

        return $names;
    }

    #[Override]
    public function id(): string
    {
        return 'security.scheme-undefined';
    }

    #[Override]
    public function level(): int
    {
        return 0;
    }

    #[Override]
    public function description(): string
    {
        return 'Operation references a security scheme not declared at the document level.';
    }
}
