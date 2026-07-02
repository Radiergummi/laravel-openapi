<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Resolvers;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Annotations as OA;
use Override;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Attributes\QueryParam;
use Radiergummi\OpenApi\Contracts\Registry\QueryParameterResolver;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Plugins\Core\Support\AccessorRead;
use Radiergummi\OpenApi\Plugins\Core\Support\FormRequestRulesReader;
use Radiergummi\OpenApi\Plugins\Core\Support\InlineValidatorRulesReader;
use Radiergummi\OpenApi\Plugins\Core\Support\RequestAccessorReader;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Extraction\FieldDescriptor;
use Radiergummi\OpenApi\Support\Extraction\PayloadParameterScanner;
use Radiergummi\OpenApi\Support\Extraction\ValidationRulesToSchema;
use ReflectionMethod;

use function array_unique;
use function array_values;
use function implode;
use function in_array;
use function sprintf;

/**
 * Composes request parameters from four sources (ascending precedence; later wins on `(name, in)`
 * collision):
 *
 *  1. Request-accessor reads via {@see RequestAccessorReader}. `$request->query()`/typed accessors
 *     become query parameters (typed accessors count only on GET/HEAD); `$request->cookie()` and
 *     `$request->header()` become cookie/header parameters on every verb.
 *  2. Inline `validate()` rules on GET/HEAD routes via {@see InlineValidatorRulesReader}.
 *     Nested dotted keys map to wire notation (`filter[name]`, `ids[]`); arrays-of-objects are dropped.
 *     DELETE routes are skipped (validated fields may be body or query string).
 *  3. A {@see FormRequest} type-hinted on a GET/HEAD action via {@see FormRequestRulesReader}, flattened
 *     the same way as inline rules. Same precedence tier as inline `validate()`, applied after it (a
 *     route rarely has both). The request body for the same FormRequest is suppressed on GET/HEAD.
 *  4. `#[QueryParam]` attributes on the action and its controller; method-level wins over class-level.
 *
 * The inferred cookie/header reads are the lowest tier for their location; `#[CookieParam]`/`#[Header]`
 * attributes fold in last (in `OperationBuilder`) and win, symmetric to the `#[QueryParam]` lock.
 */
#[Scoped]
final readonly class CoreQueryParameterResolver implements QueryParameterResolver
{
    private const array BODYLESS_METHODS = [HttpMethod::Get, HttpMethod::Head];

    public function __construct(
        private RequestAccessorReader $accessorReader,
        private InlineValidatorRulesReader $validationReader,
        private ValidationRulesToSchema $rulesMapper,
        private LoggerInterface $logger,
        private FormRequestRulesReader $formRequestRulesReader,
        private PayloadParameterScanner $scanner,
    ) {}

    /**
     * @return list<OA\Parameter>
     */
    #[Override]
    public function resolveQueryParameters(ActionDescriptor $descriptor): array
    {
        // Keyed by (name, in): a query `x` and a header `x` are distinct parameters, while a later
        // query source (inline validate, FormRequest, #[QueryParam]) overwrites an accessor read of
        // the same query name.
        /** @var array<string, OA\Parameter> $byKey */
        $byKey = [];

        foreach ($this->accessorParameters($descriptor) as $parameter) {
            $byKey[$parameter->name . "\0" . $parameter->in] = $parameter;
        }

        foreach ($this->inlineValidationParameters($descriptor) as $parameter) {
            $byKey[$parameter->name . "\0" . $parameter->in] = $parameter;
        }

        foreach ($this->formRequestParameters($descriptor) as $parameter) {
            $byKey[$parameter->name . "\0" . $parameter->in] = $parameter;
        }

        foreach ($this->attributeParameters($descriptor) as $parameter) {
            $byKey[$parameter->name . "\0" . $parameter->in] = $parameter;
        }

        return array_values($byKey);
    }

    // region Accessor reads

    /**
     * @return list<OA\Parameter>
     */
    private function accessorParameters(ActionDescriptor $descriptor): array
    {
        $method = $descriptor->method;

        if ($method === null) {
            return [];
        }

        $scan = $this->accessorReader->read($method);
        $bodylessVerb = in_array($descriptor->httpMethod, self::BODYLESS_METHODS, true);

        // Dedup per location; a query `x` and a header `x` are distinct parameters.
        /** @var array<string, array<string, AccessorRead>> $chosen */
        $chosen = ['query' => [], 'cookie' => [], 'header' => []];

        foreach ($scan->reads as $read) {
            // On a body-carrying verb, input()/string()/integer()/boolean() read the merged
            // body+query bag, so they mean body fields, not query parameters; only query() survives.
            // Cookie and header reads are verb-independent and emit on every verb.
            if ($read->location === 'query' && !$bodylessVerb && $read->accessor !== 'query') {
                continue;
            }

            $existing = $chosen[$read->location][$read->name] ?? null;

            // A typed accessor (string()/integer()/boolean()) beats the untyped bag accessors for
            // the same name; otherwise the first read wins.
            if ($existing === null || (!$existing->typed && $read->typed)) {
                $chosen[$read->location][$read->name] = $read;
            }
        }

        $this->noticeUnreadableAccessors($scan->unreadableAccessors, $method, $bodylessVerb);

        /** @var list<OA\Parameter> $parameters */
        $parameters = [];

        foreach ($chosen as $location => $reads) {
            foreach ($reads as $name => $read) {
                $schemaProperties = ['type' => $read->type];

                if ($read->default !== null) {
                    $schemaProperties['default'] = $read->default;
                }

                $parameters[] = $this->parameter(
                    $name,
                    new OA\Schema($schemaProperties),
                    required: false,
                    in: $location,
                );
            }
        }

        return $parameters;
    }

    /**
     * Notes accessor reads whose parameter name was not a static literal, grouped by location so
     * each notice names the right override attribute. Query notes only when the read would be a
     * query parameter (bodyless verb, or `query()` which only ever reads the query string); cookie
     * and header notes fire on every verb.
     *
     * @param list<string> $unreadableAccessors
     */
    private function noticeUnreadableAccessors(
        array $unreadableAccessors,
        ReflectionMethod $method,
        bool $bodylessVerb,
    ): void {
        /** @var array<string, list<string>> $byLocation */
        $byLocation = ['query' => [], 'cookie' => [], 'header' => []];

        foreach ($unreadableAccessors as $accessor) {
            $location = RequestAccessorReader::ACCESSOR_LOCATIONS[$accessor];

            if ($location === 'query' && !$bodylessVerb && $accessor !== 'query') {
                continue;
            }

            $byLocation[$location][] = $accessor;
        }

        $attributes = ['query' => '#[QueryParam]', 'cookie' => '#[CookieParam]', 'header' => '#[Header]'];

        foreach ($byLocation as $location => $accessors) {
            if ($accessors === []) {
                continue;
            }

            $this->logger->notice(
                sprintf(
                    'Request accessor read(s) in %s (%s) have a non-literal parameter name; those %s '
                    . 'parameters are not documented. Annotate the action with %s to document them.',
                    $this->actionName($method),
                    implode(', ', array_unique($accessors)),
                    $location,
                    $attributes[$location],
                ),
            );
        }
    }

    // endregion

    // region GET inline-validate hand-off

    private function actionName(ReflectionMethod $method): string
    {
        return sprintf('%s::%s', $method->getDeclaringClass()->getName(), $method->getName());
    }

    private function parameter(string $name, OA\Schema $schema, bool $required, string $in = 'query'): OA\Parameter
    {
        return new OA\Parameter([
            'name' => $name,
            'in' => $in,
            'required' => $required,
            'schema' => $schema,
        ]);
    }

    // endregion

    // region #[QueryParam] attributes

    /**
     * @return array<string, OA\Parameter>
     */
    private function inlineValidationParameters(ActionDescriptor $descriptor): array
    {
        $method = $descriptor->method;
        $bodylessVerb = in_array($descriptor->httpMethod, self::BODYLESS_METHODS, true);

        if ($method === null || (!$bodylessVerb && $descriptor->httpMethod !== HttpMethod::Delete)) {
            return [];
        }

        $scan = $this->validationReader->read($method);

        if ($scan === null) {
            return [];
        }

        $actionName = $this->actionName($method);

        if ($descriptor->httpMethod === HttpMethod::Delete) {
            // DELETE validated fields may live in body or query string; refusing to guess.
            $this->logger->notice(
                sprintf(
                    'Inline validation in %s is not documented: a DELETE route may carry the validated '
                    . 'fields in either the request body or the query string. Annotate the action with '
                    . '#[QueryParam] or #[RequestBody] to document them.',
                    $actionName,
                ),
            );

            return [];
        }

        if ($scan->rules === null) {
            $this->logger->notice(
                sprintf(
                    'Inline validation in %s could not be read statically (%s); no query parameters '
                    . 'inferred. Annotate the action with #[QueryParam] to document them.',
                    $actionName,
                    $scan->degradeReason,
                ),
            );

            return [];
        }

        if ($scan->skippedFields !== []) {
            $this->logger->notice(
                sprintf(
                    'Inline validation in %s: dropped field(s) %s — their rules are not statically readable.',
                    $actionName,
                    implode(', ', $scan->skippedFields),
                ),
            );
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
            $this->logger->notice(
                sprintf(
                    'Inline validation in %s: rule key(s) %s cannot be expressed as query parameters; '
                    . 'dropped. Annotate the action with #[QueryParam] to document them.',
                    $actionName,
                    implode(', ', $droppedKeys),
                ),
            );
        }

        return $parameters;
    }

    // endregion

    // region FormRequest query source

    /**
     * Surfaces a GET/HEAD action's {@see FormRequest} `rules()` as query parameters, flattened the
     * same way as inline `validate()` rules. The request body for the same FormRequest is suppressed
     * on these verbs by {@see FormRequestRequestSchemaResolver}. DELETE is intentionally left to its
     * request body (body-vs-query ambiguous), mirroring the inline path's DELETE handling.
     *
     * @return array<string, OA\Parameter>
     */
    private function formRequestParameters(ActionDescriptor $descriptor): array
    {
        $method = $descriptor->method;

        if ($method === null || !in_array($descriptor->httpMethod, self::BODYLESS_METHODS, true)) {
            return [];
        }

        $formRequestClass = $this->scanner->candidateOfType($method, FormRequest::class);

        if ($formRequestClass === null) {
            return [];
        }

        $result = $this->formRequestRulesReader->read($formRequestClass);

        if ($result->rules === null) {
            $this->logger->notice(
                sprintf(
                    'FormRequest %s rules() could not be read statically (%s); no query parameters '
                    . 'inferred. Annotate the action with #[QueryParam] to document them.',
                    $formRequestClass,
                    $result->degradeReason,
                ),
            );

            return [];
        }

        $mapped = $this->rulesMapper->process($result->rules, sourceClass: $formRequestClass);

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
                descriptions: [],
                parameters: $parameters,
                droppedKeys: $droppedKeys,
            );
        }

        if ($droppedKeys !== []) {
            $this->logger->notice(
                sprintf(
                    'FormRequest %s: rule key(s) %s cannot be expressed as query parameters; dropped. '
                    . 'Annotate the action with #[QueryParam] to document them.',
                    $formRequestClass,
                    implode(', ', $droppedKeys),
                ),
            );
        }

        return $parameters;
    }

    // endregion

    /**
     * Maps a rule field to query parameters in wire notation. Objects recurse into bracket names
     * (`filter[name]`); scalar arrays become `name[]`; arrays of objects are dropped (no valid
     * representation). Required only when the field and all ancestors are required.
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

            // A repeated `name[]` parameter is form-style with explode: each value is its own
            // `name[]=…` pair. Declaring it removes the serialization ambiguity flagged by the
            // parameter.query-array-no-explode lint rule.
            $parameters[$name] = new OA\Parameter([
                'name' => $name,
                'in' => 'query',
                'required' => $ancestorsRequired && $descriptor->required === true,
                'style' => 'form',
                'explode' => true,
                'schema' => $schema,
            ]);

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
}
