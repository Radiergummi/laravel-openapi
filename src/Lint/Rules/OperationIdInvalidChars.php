<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Fix\FixableRule;
use Radiergummi\OpenApi\Lint\Fix\Fixer;
use Radiergummi\OpenApi\Lint\Fix\RemoveAttributeFixer;
use Radiergummi\OpenApi\Lint\Fix\SanitizeOperationIdFixer;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Support\Generator\OperationIdDeriver;

use function preg_match;
use function sprintf;

/**
 * Reports operationIds that don't match `/^[A-Za-z][A-Za-z0-9._-]*$/` (not codegen-safe).
 * Operations without an operationId are skipped; see `operation.id-missing`.
 */
final class OperationIdInvalidChars implements FixableRule, OperationRuleVisitor
{
    public string $id = 'operation.id-invalid-chars';
    public Severity $severity = Severity::Degraded;
    public string $description = 'operationId is not a codegen-safe identifier.';

    /** The codegen-safe identifier shape; {@see OperationIdDeriver::sanitise()} maps to this set. */
    public const string PATTERN = '/^[A-Za-z][A-Za-z0-9._-]*$/';

    public function __construct(
        private readonly OperationIdDeriver $operationIdDeriver = new OperationIdDeriver(),
    ) {}

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

        // An invalid operationId can only have come from an explicit attribute, so the fixer always
        // has an #[Operation] to rewrite; stamp the sanitised value and source member for it.
        $fixContext = [];

        if ($operation->descriptor !== null) {
            $fixContext = [
                ...RemoveAttributeFixer::contextForOperation($operation->descriptor),
                SanitizeOperationIdFixer::CONTEXT_OPERATION_ID => $this->operationIdDeriver->sanitise(
                    $operation->operationId,
                ),
            ];
        }

        yield new Finding(
            ruleId: $this->id,
            severity: $this->severity,
            message: sprintf(
                'Operation ID "%s" on %s %s contains characters that are not safe for code generation',
                $operation->operationId,
                $operation->method->forDisplay(),
                $operation->pathUri,
            ),
            fixHint: 'Use only letters, digits, dots, hyphens and underscores in operationId, starting with a letter.',
            context: $fixContext,
        );
    }

    #[Override]
    public function fixer(): Fixer
    {
        return new SanitizeOperationIdFixer();
    }



}
