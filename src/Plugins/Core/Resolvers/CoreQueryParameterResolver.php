<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Resolvers;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Attributes\QueryParam;
use Radiergummi\OpenApi\Contracts\Registry\QueryParameterResolver;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Plugins\Core\Support\InlineValidatorRulesReader;
use Radiergummi\OpenApi\Plugins\Core\Support\QueryAccessorRead;
use Radiergummi\OpenApi\Plugins\Core\Support\RequestQueryAccessorReader;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Extraction\FieldDescriptor;
use Radiergummi\OpenApi\Support\Extraction\ValidationRulesToSchema;
use ReflectionMethod;

use function array_unique;
use function array_values;
use function implode;
use function in_array;
use function sprintf;

/**
 * Core query-parameter resolver.
 *
 * Composes an operation's query parameters from three sources, in ascending precedence —
 * later sources replace earlier ones on a name collision, different names compose:
 *
 *  1. **Request-accessor reads** in the method body (`$request->query('sort')`,
 *     `->integer('page')`, …) via the Tier-1 bounded scan in {@see RequestQueryAccessorReader}.
 *     `query()` is matched on every verb (it can only read the query string); `input()` /
 *     `string()` / `integer()` / `boolean()` read the merged body+query input, so they count as
 *     query parameters only on GET/HEAD routes — on body-carrying verbs they overwhelmingly
 *     mean body fields. Typed accessors beat the untyped bag accessors for the same name.
 *  2. **Inline-validation rules** on GET/HEAD routes via {@see InlineValidatorRulesReader} —
 *     the #9 hand-off: on a bodyless verb, `validate()` keys describe query parameters, not a
 *     request body. Rules contribute schema (type, format, constraints) and `required`.
 *     Nested keys map to wire notation (`filter.name` → `filter[name]`, scalar `ids.*` →
 *     `ids[]`); shapes a parameter name cannot express honestly — arrays of objects, the bare
 *     `*` rule — are dropped with a generation-log note.
 *  3. **`#[QueryParam]` attributes** on the action and its enclosing class. An attribute wins
 *     entirely for its name; class-level entries are emitted first, method-level entries
 *     replace class-level ones on the same name.
 */
#[Scoped]
final readonly class CoreQueryParameterResolver implements QueryParameterResolver
{
    private const array BODYLESS_METHODS = [HttpMethod::Get, HttpMethod::Head];

    public function __construct(
        private RequestQueryAccessorReader $accessorReader,
        private InlineValidatorRulesReader $validationReader,
        private ValidationRulesToSchema $rulesMapper,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return list<OA\Parameter>
     */
    public function resolveQueryParameters(ActionDescriptor $descriptor): array
    {
        /** @var array<string, OA\Parameter> $byName */
        $byName = [];

        foreach ($this->accessorParameters($descriptor) as $name => $parameter) {
            $byName[$name] = $parameter;
        }

        foreach ($this->inlineValidationParameters($descriptor) as $name => $parameter) {
            $byName[$name] = $parameter;
        }

        foreach ($this->attributeParameters($descriptor) as $name => $parameter) {
            $byName[$name] = $parameter;
        }

        return array_values($byName);
    }

    // region Accessor reads

    /**
     * @return array<string, OA\Parameter>
     */
    private function accessorParameters(ActionDescriptor $descriptor): array
    {
        $method = $descriptor->method;

        if ($method === null) {
            return [];
        }

        $scan = $this->accessorReader->read($method);
        $bodylessVerb = in_array($descriptor->httpMethod, self::BODYLESS_METHODS, true);

        /** @var array<string, QueryAccessorRead> $chosen */
        $chosen = [];

        foreach ($scan->reads as $read) {
            if (!$bodylessVerb && $read->accessor !== 'query') {
                continue;
            }

            $existing = $chosen[$read->name] ?? null;

            // Within the scan, a typed accessor (string()/integer()/boolean()) beats the
            // untyped bag accessors for the same name; otherwise the first read wins.
            if ($existing === null || (!$existing->typed && $read->typed)) {
                $chosen[$read->name] = $read;
            }
        }

        if ($scan->unreadableAccessors !== []) {
            $this->logger->notice(sprintf(
                'Request accessor read(s) in %s (%s) have a non-literal parameter name; those query '
                . 'parameters are not documented. Annotate the action with #[QueryParam] to document them.',
                $this->actionName($method),
                implode(', ', array_unique($scan->unreadableAccessors)),
            ));
        }

        /** @var array<string, OA\Parameter> $parameters */
        $parameters = [];

        foreach ($chosen as $name => $read) {
            $schemaProperties = ['type' => $read->type];

            if ($read->default !== null) {
                $schemaProperties['default'] = $read->default;
            }

            $parameters[$name] = $this->parameter($name, new OA\Schema($schemaProperties), required: false);
        }

        return $parameters;
    }

    // endregion

    // region GET inline-validate hand-off

    /**
     * @return array<string, OA\Parameter>
     */
    private function inlineValidationParameters(ActionDescriptor $descriptor): array
    {
        $method = $descriptor->method;

        if ($method === null || !in_array($descriptor->httpMethod, self::BODYLESS_METHODS, true)) {
            return [];
        }

        $scan = $this->validationReader->read($method);

        if ($scan === null) {
            return [];
        }

        $actionName = $this->actionName($method);

        if ($scan->rules === null) {
            $this->logger->notice(sprintf(
                'Inline validation in %s could not be read statically (%s); no query parameters '
                . 'inferred. Annotate the action with #[QueryParam] to document them.',
                $actionName,
                $scan->degradeReason,
            ));

            return [];
        }

        if ($scan->skippedFields !== []) {
            $this->logger->notice(sprintf(
                'Inline validation in %s: dropped field(s) %s — their rules are not statically readable.',
                $actionName,
                implode(', ', $scan->skippedFields),
            ));
        }

        $mapped = $this->rulesMapper->process(
            $scan->rules,
            sourceClass: $method->getDeclaringClass()->getName(),
        );

        /** @var array<string, OA\Parameter> $parameters */
        $parameters = [];

        /** @var list<string> $droppedKeys */
        $droppedKeys = [];

        if ($mapped['additionalPropertiesField'] !== null) {
            $droppedKeys[] = '*';
        }

        foreach ($mapped['fields'] as $fieldName => $fieldDescriptor) {
            $this->flattenField(
                wireName: $fieldName,
                dottedPath: $fieldName,
                descriptor: $fieldDescriptor,
                ancestorsRequired: true,
                descriptions: $scan->descriptions,
                parameters: $parameters,
                droppedKeys: $droppedKeys,
            );
        }

        if ($droppedKeys !== []) {
            $this->logger->notice(sprintf(
                'Inline validation in %s: rule key(s) %s cannot be expressed as query parameters; '
                . 'dropped. Annotate the action with #[QueryParam] to document them.',
                $actionName,
                implode(', ', $droppedKeys),
            ));
        }

        return $parameters;
    }

    /**
     * Maps one rule field onto query parameters in wire notation. Object children recurse into
     * bracket names (`filter[name]`), an array of scalars becomes a repeatable `name[]`
     * parameter with an array schema, and an array of objects has no honest parameter-name
     * representation — its key is reported back for the generation-log note. A parameter is
     * `required` only when its own rules say so and every ancestor is required too.
     *
     * @param array<string, string>       $descriptions
     * @param array<string, OA\Parameter> $parameters
     * @param list<string>                $droppedKeys
     */
    private function flattenField(
        string $wireName,
        string $dottedPath,
        FieldDescriptor $descriptor,
        bool $ancestorsRequired,
        array $descriptions,
        array &$parameters,
        array &$droppedKeys,
    ): void {
        if ($descriptor->properties !== null) {
            foreach ($descriptor->properties as $childName => $childDescriptor) {
                $this->flattenField(
                    wireName: $wireName . '[' . $childName . ']',
                    dottedPath: $dottedPath . '.' . $childName,
                    descriptor: $childDescriptor,
                    ancestorsRequired: $ancestorsRequired && $descriptor->required === true,
                    descriptions: $descriptions,
                    parameters: $parameters,
                    droppedKeys: $droppedKeys,
                );
            }

            return;
        }

        if ($descriptor->type === 'array') {
            $items = $descriptor->items;

            if ($items !== null && ($items->properties !== null || $items->type === 'array')) {
                $droppedKeys[] = $dottedPath;

                return;
            }

            $name = $wireName . '[]';
            $description = $descriptions[$dottedPath] ?? $descriptions[$dottedPath . '.*'] ?? null;

            if ($description !== null) {
                $descriptor->description = $description;
            }

            $schema = new OA\Schema([]);
            $descriptor->applyTo($schema);

            $parameters[$name] = $this->parameter(
                $name,
                $schema,
                required: $ancestorsRequired && $descriptor->required === true,
            );

            return;
        }

        if (isset($descriptions[$dottedPath])) {
            $descriptor->description = $descriptions[$dottedPath];
        }

        $schema = new OA\Schema([]);
        $descriptor->applyTo($schema);

        $parameters[$wireName] = $this->parameter(
            $wireName,
            $schema,
            required: $ancestorsRequired && $descriptor->required === true,
        );
    }

    // endregion

    // region #[QueryParam] attributes

    /**
     * @return array<string, OA\Parameter>
     */
    private function attributeParameters(ActionDescriptor $descriptor): array
    {
        $reflector = $descriptor->actionReflector;

        if ($reflector === null) {
            return [];
        }

        /** @var array<string, QueryParam> $merged */
        $merged = [];

        if ($descriptor->controller !== null) {
            foreach ($descriptor->controller->getAttributes(QueryParam::class) as $attribute) {
                $instance = $attribute->newInstance();
                $merged[$instance->name] = $instance;
            }
        }

        foreach ($reflector->getAttributes(QueryParam::class) as $attribute) {
            $instance = $attribute->newInstance();
            $merged[$instance->name] = $instance;
        }

        /** @var array<string, OA\Parameter> $parameters */
        $parameters = [];

        foreach ($merged as $name => $attributeInstance) {
            $properties = [
                'name' => $name,
                'in' => 'query',
                'required' => $attributeInstance->required,
                'schema' => $attributeInstance->descriptor()->toSchema(),
            ];

            if ($attributeInstance->deprecated) {
                $properties['deprecated'] = true;
            }

            $parameters[$name] = new OA\Parameter($properties);
        }

        return $parameters;
    }

    // endregion

    private function parameter(string $name, OA\Schema $schema, bool $required): OA\Parameter
    {
        return new OA\Parameter([
            'name' => $name,
            'in' => 'query',
            'required' => $required,
            'schema' => $schema,
        ]);
    }

    private function actionName(ReflectionMethod $method): string
    {
        return sprintf('%s::%s', $method->getDeclaringClass()->getName(), $method->getName());
    }
}
