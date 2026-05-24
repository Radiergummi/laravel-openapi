<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\Resettable;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;

use function in_array;
use function is_array;
use function sprintf;

/**
 * Reports when an operation's `security` array references a scheme name that is not declared in
 * `components.securitySchemes`.
 *
 * This is distinct from {@see SecurityInvalidScope}, which checks scope names within a declared
 * scheme.
 */
final class SecuritySchemeUndefined implements Rule, OperationRuleVisitor, Resettable
{
    /**
     * Declared scheme names, memoized for the duration of a single walk.
     *
     * @var null|list<string>
     */
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
                    $operation->method,
                    $operation->pathUri,
                    $schemeName,
                ),
                fixHint: 'Declare the scheme in components.securitySchemes or remove it from the operation\'s security array.',
            );
        }
    }

    /**
     * Collect all security scheme names declared in `components.securitySchemes`.
     *
     * @return list<string>
     */
    private function collectDeclaredSchemes(OA\OpenApi $spec): array
    {
        $components = $spec->components;

        if ($components === Generator::UNDEFINED || $components === null) {
            return [];
        }

        $schemes = $components->securitySchemes;

        if ($schemes === Generator::UNDEFINED || !is_array($schemes)) {
            return [];
        }

        $names = [];

        foreach ($schemes as $scheme) {
            if ($scheme === Generator::UNDEFINED) {
                continue;
            }

            if (
                $scheme->securityScheme !== Generator::UNDEFINED
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
