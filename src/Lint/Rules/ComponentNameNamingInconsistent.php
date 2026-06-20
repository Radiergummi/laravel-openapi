<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Override;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\IdentifierCase;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Lint\Visitors\ComponentSchemaRule as ComponentSchemaRuleVisitor;

use function sprintf;

/**
 * Reports component schema names that do not follow the configured naming convention.
 */
#[Scoped]
final class ComponentNameNamingInconsistent extends AbstractNamingRule implements ComponentSchemaRuleVisitor
{
    public string $id = 'component.name-naming-inconsistent';
    public string $description = 'Component schema name does not follow the configured component_name_case convention.';

    public function __construct(
        #[Config('openapi.lint.style.component_name_case', 'pascal')]
        IdentifierCase|string $case = IdentifierCase::Pascal,
    ) {
        parent::__construct($case);
    }

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkComponentSchema(ComponentSchemaNode $componentSchema, LintContext $context): iterable
    {
        if ($this->conforms($componentSchema->name)) {
            return;
        }

        yield new Finding(
            ruleId: $this->id,
            severity: $this->severity,
            message: sprintf(
                'Component schema name "%s" does not follow the %s naming convention',
                $componentSchema->name,
                $this->case->label(),
            ),
            fixHint: $this->fixHint('component schema names'),
        );
    }


}
