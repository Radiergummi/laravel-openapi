<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;

use function is_array;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Reports deprecated operations whose description lacks a replacement hint. Checks for
 * keywords "use", "replaced by", "replacement", or "sunset", or a non-empty `x-replacement`
 * extension.
 */
final class DeprecatedNoReplacement implements Rule, OperationRuleVisitor
{
    public string $id = 'deprecated.no-replacement';
    public Severity $severity = Severity::Improvable;
    public string $description = 'Deprecated operation/field has no x-replacement or suggested alternative.';

    private const string REPLACEMENT_PATTERN = "/\b(use|replaced\s+by|replacement|sunset)\b/i";

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
            ruleId: $this->id,
            severity: $this->severity,
            message: sprintf(
                'Deprecated operation %s %s does not mention a replacement in its description',
                $operation->method->forDisplay(),
                $operation->pathUri,
            ),
            fixHint: 'Add migration guidance to the description (e.g. "Use GET /v2/resource instead").',
        );
    }



}
