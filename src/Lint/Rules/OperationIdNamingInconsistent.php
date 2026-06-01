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
 * Reports operation IDs that do not follow the configured naming convention.
 *
 * The expected casing is injected via {@see IdentifierCase} and defaults to {@see IdentifierCase::Dot}
 * (e.g. `api.v0.projects.index`). Operations without an operationId are skipped — that is caught
 * by `operation.id-missing`.
 */
#[Scoped]
final readonly class OperationIdNamingInconsistent extends AbstractNamingRule implements OperationRuleVisitor
{
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
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'Operation ID "%s" on %s %s does not follow the %s naming convention',
                $operation->operationId,
                $operation->method,
                $operation->pathUri,
                $this->case->label(),
            ),
            fixHint: $this->fixHint('operationId'),
        );
    }

    #[Override]
    public function id(): string
    {
        return 'operation.id-naming-inconsistent';
    }

    #[Override]
    public function description(): string
    {
        return "operationId doesn't follow the project's operation_id_case convention.";
    }
}
