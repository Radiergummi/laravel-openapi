<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;

use function preg_match;
use function sprintf;

/**
 * Reports operationIds that don't match `/^[A-Za-z][A-Za-z0-9._-]*$/` (not codegen-safe).
 * Operations without an operationId are skipped; see `operation.id-missing`.
 */
final class OperationIdInvalidChars implements Rule, OperationRuleVisitor
{
    private const string PATTERN = '/^[A-Za-z][A-Za-z0-9._-]*$/';

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->operationId === null || $operation->operationId === '') {
            return;
        }

        if (preg_match(self::PATTERN, $operation->operationId) === 1) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'Operation ID "%s" on %s %s contains characters that are not safe for code generation',
                $operation->operationId,
                $operation->method->forDisplay(),
                $operation->pathUri,
            ),
            fixHint: 'Use only letters, digits, dots, hyphens and underscores in operationId, starting with a letter.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'operation.id-invalid-chars';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'operationId is not a codegen-safe identifier.';
    }
}
