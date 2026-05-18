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
use Radiergummi\OpenApi\Core\Lint\IdentifierCase;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\ComponentSchemaRule as ComponentSchemaRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\ComponentSchemaNode;
use Override;

use function sprintf;

/**
 * Reports component schema names that do not follow the configured naming
 * convention.
 *
 * The expected casing is injected via {@see IdentifierCase} and defaults to
 * {@see IdentifierCase::Pascal} (e.g. `ProjectResource`), which matches the
 * house style used across all JSON:API component schemas in this codebase.
 */
final readonly class ComponentNameNamingInconsistent extends AbstractNamingRule implements ComponentSchemaRuleVisitor
{
    public function __construct(IdentifierCase $case = IdentifierCase::Pascal)
    {
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
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'Component schema name "%s" does not follow the %s naming convention',
                $componentSchema->name,
                $this->case->label(),
            ),
            fixHint: $this->fixHint('component schema names'),
        );
    }

    #[Override]
    public function id(): string
    {
        return 'component.name-naming-inconsistent';
    }

    #[Override]
    public function description(): string
    {
        return 'Component schema name does not follow the configured component_name_case convention.';
    }
}
