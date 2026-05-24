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
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;

use function is_array;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Reports deprecated operations that do not mention a sunset date or timeline.
 *
 * When an operation is marked as `deprecated: true`, its description should include a specific
 * date or timeline so that consumers know when the endpoint will be removed. This rule checks for
 * ISO dates (YYYY-MM-DD) or quarter notation (Q1 2025).
 *
 * A non-empty `x-sunset` OAS extension on the operation also satisfies the requirement.
 */
final class DeprecatedNoSunsetDate implements Rule, OperationRuleVisitor
{
    private const DATE_PATTERN = "/\b(\d{4}-\d{2}-\d{2}|Q[1-4]\s*\d{4})\b/";

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if (!$operation->deprecated) {
            return;
        }

        // A non-empty x-sunset extension satisfies the requirement.
        $extensions = $operation->raw->x;

        if (is_array($extensions) && isset($extensions['x-sunset']) && is_string(
            $extensions['x-sunset'],
        ) && $extensions['x-sunset'] !== '') {
            return;
        }

        $descriptionText = $operation->description ?? '';

        if (preg_match(self::DATE_PATTERN, $descriptionText) === 1) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'Deprecated operation %s %s does not mention a sunset date or timeline',
                $operation->method,
                $operation->pathUri,
            ),
            fixHint: 'Add an ISO date (e.g. "Will be removed on 2025-12-31") or quarter notation (e.g. "Sunset in Q1 2026") to the description.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'deprecated.no-sunset-date';
    }

    #[Override]
    public function level(): int
    {
        return 4;
    }

    #[Override]
    public function description(): string
    {
        return 'Deprecated operation has no x-sunset date.';
    }
}
