<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Illuminate\Http\Resources\Json\JsonResource;
use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Lint\Tree\FieldNode;
use Radiergummi\OpenApi\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Lint\Tree\SchemaAccessor;
use Radiergummi\OpenApi\Lint\Visitors\ResponseRule as ResponseRuleVisitor;
use ReflectionClass;

use function array_any;
use function class_exists;
use function in_array;
use function is_a;
use function Radiergummi\OpenApi\is_defined;
use function sprintf;
use function strrchr;
use function substr;

/**
 * Flags a 2xx response whose payload derives from an abstract or framework API-Resource base
 * (e.g. `JsonResource`) rather than a concrete subclass.
 *
 * Such a return type resolves honestly to an empty component schema (the "type your returns"
 * contract working as specified). The fix lives entirely in the app: narrow the action's return
 * type to the concrete resource subclass.
 */
final class OperationResponseTypeAbstract implements Rule, ResponseRuleVisitor
{
    public string $id = 'operation.response-type-abstract';
    public Severity $severity = Severity::Underspecified;
    public string $description = 'The primary response refs an abstract or framework resource base (e.g. JsonResource); narrow the return type to the concrete resource subclass to get a schema.';

    /** @var list<class-string> */
    private const array FRAMEWORK_RESOURCE_BASES = [
        'Illuminate\\Http\\Resources\\Json\\JsonResource',
        'Illuminate\\Http\\Resources\\Json\\ResourceCollection',
        'Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection',
        'Illuminate\\Http\\Resources\\JsonApi\\JsonApiResource',
    ];

    /** @var list<class-string> */
    private const array RESOURCE_ROOTS = [
        JsonResource::class,
        'Illuminate\\Http\\Resources\\JsonApi\\JsonApiResource',
    ];

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkResponse(ResponseNode $response, LintContext $context): iterable
    {
        if (!$response->isSuccess) {
            return;
        }

        // Resource payloads are wrapped in an inline envelope (`{data: {$ref: …}}`), so the
        // component is reached through the response's field refs, not its top-level schemaRef.
        $seen = [];

        foreach ($this->referencedComponentNames($response) as $name) {
            $component = $context->index->componentsByName[$name] ?? null;
            $base = $component?->sourceClass;

            if (
                $component === null
                || $base === null
                || isset($seen[$base])
                || !$this->isAbstractResourceBase($base)
                || !$this->isEmptySchema($component)
            ) {
                continue;
            }

            $seen[$base] = true;

            $operation = $response->operation();
            $route = $operation !== null
                ? sprintf('%s %s', $operation->method->forDisplay(), $operation->pathUri)
                : '<unknown operation>';

            yield new Finding(
                ruleId: $this->id,
                severity: $this->severity,
                message: sprintf(
                    'Response %s on %s refs an abstract resource base (%s); narrow the action\'s '
                    . 'return type to the concrete resource subclass to get a response schema.',
                    $response->statusCode,
                    $route,
                    $this->shortName($base),
                ),
                fixHint: 'Narrow the return type to the concrete resource subclass. If the value '
                    . 'comes from ->toResource(), narrowing may be impossible (Laravel types '
                    . 'Model::toResource(): JsonResource, so PHPStan rejects the narrower type); '
                    . 'the library resolves those cases itself via the receiver-resolution family.',
            );
        }
    }

    /**
     * Component names the response references: its top-level `$ref` plus every field ref (the
     * resource sits under the envelope's `data` field).
     *
     * @return list<string>
     */
    private function referencedComponentNames(ResponseNode $response): array
    {
        $names = [];

        if ($response->schemaRef !== null) {
            $names[] = $response->schemaRef;
        }

        $this->collectFieldRefs($response->fields, $names);

        return $names;
    }

    /**
     * @param list<FieldNode> $fields
     * @param list<string>    $names
     */
    private function collectFieldRefs(array $fields, array &$names): void
    {
        foreach ($fields as $field) {
            if ($field->ref !== null) {
                $names[] = $field->ref;
            }

            $this->collectFieldRefs($field->children, $names);
        }
    }

    /**
     * Whether the source class is a framework resource base or an app-defined abstract subclass of
     * one. Concrete app resources (even ones that render empty) are out of scope.
     *
     * @param class-string $class
     */
    private function isAbstractResourceBase(string $class): bool
    {
        if (in_array($class, self::FRAMEWORK_RESOURCE_BASES, true)) {
            return true;
        }

        if (!class_exists($class) || !$this->isResourceSubclass($class)) {
            return false;
        }

        return new ReflectionClass($class)->isAbstract();
    }

    /**
     * @param class-string $class
     */
    private function isResourceSubclass(string $class): bool
    {
        return array_any(
            self::RESOURCE_ROOTS,
            static fn(string $root): bool => class_exists($root) && is_a($class, $root, true),
        );
    }

    /**
     * An empty resource component carries no properties, array items, or composition. The gate
     * keeps the rule from firing on a resolved collection, whose element schema is non-empty.
     */
    private function isEmptySchema(ComponentSchemaNode $component): bool
    {
        if ($component->fields !== []) {
            return false;
        }

        $raw = $component->raw;

        if ($raw === null) {
            return true;
        }

        if (is_defined($raw->items) || is_defined($raw->allOf)) {
            return false;
        }

        $composition = SchemaAccessor::classifyComposition($raw);

        return $composition['branch'] === null && !$composition['uninspectedComposite'];
    }

    /**
     * @param class-string $class
     */
    private function shortName(string $class): string
    {
        $tail = strrchr($class, '\\');

        return $tail === false ? $class : substr($tail, 1);
    }
}
