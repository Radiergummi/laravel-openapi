<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;

use function is_array;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Reports deprecated operations missing a sunset date (ISO YYYY-MM-DD, quarter notation, or `x-sunset`).
 */
final class DeprecatedNoSunsetDate implements Rule, OperationRuleVisitor
{
    private const string DATE_PATTERN = "/\b(\d{4}-\d{2}-\d{2}|Q[1-4]\s*\d{4})\b/";

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if (!$operation->deprecated) {
            return;
        }

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
                $operation->method->forDisplay(),
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
