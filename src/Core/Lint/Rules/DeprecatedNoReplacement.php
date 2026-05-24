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
 * Reports deprecated operations whose description does not mention a replacement.
 *
 * When an operation is marked as `deprecated: true`, its description should guide consumers
 * toward the replacement endpoint. This rule checks for keywords like "use", "replaced by",
 * "replacement", or "sunset".
 *
 * A non-empty `x-replacement` OAS extension on the operation also satisfies the requirement.
 */
final class DeprecatedNoReplacement implements Rule, OperationRuleVisitor
{
    private const REPLACEMENT_PATTERN = "/\b(use|replaced\s+by|replacement|sunset)\b/i";

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if (!$operation->deprecated) {
            return;
        }

        // A non-empty x-replacement extension satisfies the requirement.
        $extensions = $operation->raw->x;

        if (is_array($extensions) && isset($extensions['x-replacement']) && is_string(
            $extensions['x-replacement'],
        ) && $extensions['x-replacement'] !== '') {
            return;
        }

        $descriptionText = $operation->description ?? '';

        if (preg_match(self::REPLACEMENT_PATTERN, $descriptionText) === 1) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'Deprecated operation %s %s does not mention a replacement in its description',
                $operation->method,
                $operation->pathUri,
            ),
            fixHint: 'Add migration guidance to the description (e.g. "Use GET /v2/resource instead").',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'deprecated.no-replacement';
    }

    #[Override]
    public function level(): int
    {
        return 4;
    }

    #[Override]
    public function description(): string
    {
        return 'Deprecated operation/field has no x-replacement or suggested alternative.';
    }
}
