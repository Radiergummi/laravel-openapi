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

use function preg_match;
use function sprintf;

/**
 * Reports operations whose operationId contains characters that are not safe
 * for code generation — i.e. not matching `/^[A-Za-z][A-Za-z0-9._-]*$/`.
 *
 * The id must start with a letter; subsequent characters may be letters,
 * digits, dots, hyphens, or underscores. Operations without an operationId
 * are skipped — that case is owned by `operation.id-missing`.
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
                $operation->method,
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
