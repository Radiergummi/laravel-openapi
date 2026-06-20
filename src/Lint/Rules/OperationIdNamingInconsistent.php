<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Override;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\IdentifierCase;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;

use function sprintf;

/**
 * Reports operation IDs that do not follow the configured naming convention. Defaults to
 * {@see IdentifierCase::Dot} (e.g., `api.v0.projects.index`). Operations without an
 * operationId are skipped (caught by `operation.id-missing`).
 */
#[Scoped]
final class OperationIdNamingInconsistent extends AbstractNamingRule implements OperationRuleVisitor
{
    public string $id = 'operation.id-naming-inconsistent';
    public string $description = "operationId doesn't follow the project's operation_id_case convention.";

    public function __construct(
        #[Config('openapi.lint.style.operation_id_case', 'dot')]
        IdentifierCase|string $case = IdentifierCase::Dot,
    ) {
        parent::__construct($case);
    }

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->operationId === null) {
            return;
        }

        if ($this->conforms($operation->operationId)) {
            return;
        }

        yield new Finding(
            ruleId: $this->id,
            severity: $this->severity,
            message: sprintf(
                'Operation ID "%s" on %s %s does not follow the %s naming convention',
                $operation->operationId,
                $operation->method->forDisplay(),
                $operation->pathUri,
                $this->case->label(),
            ),
            fixHint: $this->fixHint('operationId'),
        );
    }


}
