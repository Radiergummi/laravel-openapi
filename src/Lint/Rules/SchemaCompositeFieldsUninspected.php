<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use OpenApi\Annotations as OA;
use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Lint\Tree\FieldNode;
use Radiergummi\OpenApi\Lint\Tree\RequestBodyNode;
use Radiergummi\OpenApi\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Lint\Tree\SchemaAccessor;
use Radiergummi\OpenApi\Lint\Visitors\ComponentSchemaRule as ComponentSchemaRuleVisitor;
use Radiergummi\OpenApi\Lint\Visitors\FieldRule as FieldRuleVisitor;
use Radiergummi\OpenApi\Lint\Visitors\RequestBodyRule as RequestBodyRuleVisitor;
use Radiergummi\OpenApi\Lint\Visitors\ResponseRule as ResponseRuleVisitor;

use function sprintf;

/**
 * Reports schemas whose `oneOf` / `anyOf` is a union of two or more genuine alternatives, which
 * field-level rules cannot descend into. This covers both a property using such a shape and a
 * top-level schema that is itself the union — a component schema, a response body, or a request
 * body. Without this, such a schema produces no field findings at all, leaving the coverage gap
 * invisible.
 */
final class SchemaCompositeFieldsUninspected implements
    Rule,
    FieldRuleVisitor,
    ComponentSchemaRuleVisitor,
    ResponseRuleVisitor,
    RequestBodyRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkField(FieldNode $field, LintContext $context): iterable
    {
        if ($field->raw === null) {
            return;
        }

        if (!SchemaAccessor::classifyComposition($field->raw)['uninspectedComposite']) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'Field "%s" is a oneOf/anyOf union of multiple alternatives; its branches are not inspected by field-level rules',
                $field->name,
            ),
            fixHint: 'Document each alternative by hand, or restructure the schema so the property resolves to a single concrete shape.',
        );
    }

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkComponentSchema(ComponentSchemaNode $componentSchema, LintContext $context): iterable
    {
        if ($componentSchema->raw === null) {
            return;
        }

        yield from $this->flagUninspectedComposite(
            $componentSchema->raw,
            sprintf('Component schema "%s"', $componentSchema->name),
        );
    }

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkResponse(ResponseNode $response, LintContext $context): iterable
    {
        if ($response->raw === null) {
            return;
        }

        $schema = SchemaAccessor::bodySchema($response->raw);

        if ($schema === null) {
            return;
        }

        yield from $this->flagUninspectedComposite(
            $schema,
            sprintf('Response "%s" body', $response->statusCode),
        );
    }

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkRequestBody(RequestBodyNode $requestBody, LintContext $context): iterable
    {
        if ($requestBody->raw === null) {
            return;
        }

        $schema = SchemaAccessor::bodySchema($requestBody->raw);

        if ($schema === null) {
            return;
        }

        yield from $this->flagUninspectedComposite($schema, 'Request body');
    }

    /**
     * @return iterable<Finding>
     */
    private function flagUninspectedComposite(OA\Schema $schema, string $subject): iterable
    {
        if (!SchemaAccessor::classifyComposition($schema)['uninspectedComposite']) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                '%s is a oneOf/anyOf union of multiple alternatives; its branches are not inspected by field-level rules',
                $subject,
            ),
            fixHint: 'Document each alternative by hand, or restructure the schema so the body resolves to a single concrete shape.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'schema.composite-fields-uninspected';
    }

    #[Override]
    public function level(): int
    {
        // A coverage gap, not invalid documentation.
        return 3;
    }

    #[Override]
    public function description(): string
    {
        return 'Schema is a oneOf/anyOf of multiple alternatives whose fields are not inspected by field-level rules.';
    }
}
