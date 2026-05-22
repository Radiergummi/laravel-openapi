# Linting

`php artisan openapi:lint` generates the spec then walks a domain tree to check
convention and completeness. It is fully independent of the generation pipeline
from the consumer's perspective.

```bash
php artisan openapi:lint            # default: level 1 (broken + degraded)
php artisan openapi:lint --level=max
```

## How it works

1. The command generates the spec (optionally restricted by `--path` or `--diff`).
2. `SpecTreeBuilder` converts the raw `OA\OpenApi` graph into a typed node tree
   (`ApiNode`, `OperationNode`, `ResponseNode`, `FieldNode`, …) in
   `Core/Lint/Tree/`.
3. `SpecTreeWalker` dispatches each node to rules that implement the matching
   visitor interface (`OperationRule`, `ResponseRule`, `FieldRule`,
   `ParameterRule`, `HeaderRule`, `LinkRule`, `WebhookRule`,
   `ComponentSchemaRule`, `ApiRule`, `QueryParameterRule`, `RequestBodyRule`,
   `ExampleRule`).
4. Rules may also implement `Finalizable` (called after each operation's
   sub-tree) or `Resettable` (reset between operations).
5. `MetaSuppressionStale` runs as a `PostWalkRule` after the tree walk,
   because it needs the complete findings set.
6. Findings are filtered to the active severity level, suppressed by
   `#[IgnoreLint]` directives, and formatted for output.

## Severity levels

Rules carry a numeric severity level. The scale is an **open-ended gradient of
decreasing severity** (lower = more severe), modelled on PHPStan — levels are
not fixed categories, and finer levels may be added over time. The `--level`
flag (or `config/openapi.lint.level`) sets the threshold: only rules at or
below it run.

| Level | Name | Meaning |
|---|---|---|
| 0 | Broken | A conformant OpenAPI validator rejects the document, or a major consumer (Scalar, codegen) fails outright. |
| 1 | Degraded | The document parses, but a violation makes part of it wrong — it lies about the API, drops information, or misbehaves in tooling. |
| 2 | Underspecified | Correct but incomplete — a component that should carry detail is missing it. |
| 3 | Inconsistent | Complete and correct, but violates a naming/structure convention or a hygiene meta-rule. |
| 4 | Improvable | Optional polish whose absence costs nothing concrete. |

Pass `--level=max` to run every rule regardless of level. The default
threshold is **level 1** (broken + degraded).

## Commands

```bash
# Default: level 1
php artisan openapi:lint

# Run all rules
php artisan openapi:lint --level=max

# Target specific rules
php artisan openapi:lint --only=summary.missing,operation.description-missing

# Exclude rules
php artisan openapi:lint --skip=tags.no-description

# Restrict to routes matching a URI glob
php artisan openapi:lint --path='api/v0/projects*'

# Restrict to routes touched since the merge-base with develop
php artisan openapi:lint --diff

# Restrict to routes touched since a specific git ref
php artisan openapi:lint --diff=main

# Ignore all #[IgnoreLint] suppressions
php artisan openapi:lint --no-suppress

# Output formats (auto-detected: cli in terminal, github in CI, json otherwise)
php artisan openapi:lint --format=json
php artisan openapi:lint --format=github

# Print the rule catalog instead of linting
php artisan openapi:lint --list
```

## Suppress a finding

Use the `#[OpenApi\IgnoreLint]` attribute. Each instance suppresses exactly
one rule; stack the attribute for several. Always pass a `reason`:

```php
use Radiergummi\OpenApi\Core\Attributes as OpenApi;

#[OpenApi\IgnoreLint('response.no-error', reason: 'Internal-only endpoint, errors are handled by the framework')]
public function internal(): JsonResponse { … }
```

Scope follows the annotated symbol:

- **Class** — silences the rule for every operation in the controller.
- **Method** — silences it for that action only.
- **Property** — silences `field.*` findings for that property; place it on the
  Data-class property.

> [!WARNING]
> `spec.invalid` can never be suppressed. Run with `--no-suppress` to ignore
> all directives.

Meta-rules enforce directive hygiene:

- `meta.no-suppression-reason` — directive has no `reason` parameter.
- `meta.suppression-stale` — directive did not suppress any finding.
- `meta.too-many-suppressions` — a symbol carries an excessive number of
  directives.

## Style conventions (naming rules)

Naming rules read their expected case convention from `config/openapi.lint.style`:

| Config key | Default | Affected rule |
|---|---|---|
| `operation_id_case` | `dot` | `operation.id-naming-inconsistent` |
| `property_name_case` | `camel` | `field.name-naming-inconsistent` |
| `path_segment_case` | `kebab` | `path.segment-naming-inconsistent` |
| `parameter_name_case` | `snake` | `parameter.name-naming-inconsistent` |
| `tag_case` | `pascal` | `tag.name-naming-inconsistent` |
| `header_case` | `train` | `header.name-naming-inconsistent` |

Supported case values: `dot`, `kebab`, `snake`, `camel`, `pascal`, `train`,
`screaming_snake`.

## Adding a custom rule

1. Implement `Radiergummi\OpenApi\Core\Lint\Rules\Rule` and one or more visitor
   interfaces from `Core/Lint/Rules/Visitors/`.
2. Add the class to `config/openapi.lint.rules` (or register it in a plugin via
   `$registry->addRule(YourRule::class)` — see
   [Plugin authoring](plugin-authoring.md)).

## Rule catalog

All built-in rule IDs. Run `php artisan openapi:lint --list` for the live
catalog with the current state of any plugin-registered rules.

<!-- BEGIN: lint-rule-catalog -->
| Rule ID | Level | Description |
|---|---|---|
| `discriminator.invalid-mapping` | 0 | Discriminator mapping references a missing component schema. |
| `field.enum-mismatch` | 0 | Enum value type doesn't match the field's declared type. |
| `link.both-operation-id-and-ref` | 0 | Link declares both operationId and operationRef (mutually exclusive). |
| `link.duplicate-name` | 0 | Two links on the same response share the same name. |
| `link.invalid-parameter` | 0 | Link references a parameter that the target operation doesn't declare. |
| `link.neither-operation-id-nor-ref` | 0 | Link has neither operationId nor operationRef. |
| `link.parameter-required-missing` | 0 | Link omits a parameter that the target operation requires. |
| `operation.id-duplicate` | 0 | Two operations share the same operationId. |
| `parameter.duplicate-name` | 0 | Two parameters in the same operation share the same name and location. |
| `parameter.path-must-be-required` | 0 | Path parameter is not marked required: true. |
| `parameter.query-no-schema` | 0 | Query parameter has no schema. |
| `path.parameter-undeclared` | 0 | Path template uses a variable not declared as a parameter. |
| `path.parameter-undefined` | 0 | A declared path parameter doesn't appear in the path template. |
| `queryparam.duplicate` | 0 | Two #[QueryParam] attributes on the same controller/method share the same name. |
| `ref.broken` | 0 | A $ref points to a component that doesn't exist in the spec. |
| `response.description-missing` | 0 | Response has no description. OAS 3.1 requires description on every Response Object. |
| `response.duplicate-status` | 0 | Two responses on the same operation share the same status code. |
| `schema.enum-type-mismatch` | 0 | Schema enum contains values that don't match the declared type. |
| `schema.required-without-property` | 0 | required names a field not in properties. |
| `security.scheme-undefined` | 0 | Operation references a security scheme not declared at the document level. |
| `server.invalid-url` | 0 | A servers[].url is malformed. |
| `server.variable-undeclared` | 0 | A server URL template uses a {var} with no matching variables entry. |
| `spec.invalid` | 0 | Spec fails swagger-php validation. Cannot be suppressed or remapped. |
| `tag.duplicate` | 0 | Two top-level tag definitions share the same name. |
| `webhook.name-duplicate` | 0 | Two webhooks share the same name. |
| `externaldocs.invalid-url` | 1 | externalDocs.url is not a valid URL. |
| `field.attribute-wrong-scope` | 1 | #[RequestField] on a URI parameter, or #[PathParam] on a Data-class property. |
| `field.conflicting-type` | 1 | Field declares conflicting type and format values. |
| `header.invalid-name` | 1 | Header name contains invalid characters. |
| `link.invalid-operation` | 1 | Link references an operationId that doesn't exist in the document. |
| `multipart.file-without-multipart` | 1 | Data class has a file property but the request body isn't multipart/form-data — produces an incorrect spec. |
| `operation.id-invalid-chars` | 1 | operationId is not a codegen-safe identifier. |
| `operation.id-missing` | 1 | Operation has no operationId. |
| `operation.security-missing` | 1 | Route enforces auth middleware but the operation declares no security, implying the endpoint is public. |
| `operation.tag-missing` | 1 | Operation has no tags. |
| `parameter.example-conflict` | 1 | A parameter sets both example and examples (mutually exclusive). |
| `parameter.query-array-no-explode` | 1 | Array query parameter is missing explode: true. |
| `publicendpoint.contradicts-middleware` | 1 | #[PublicEndpoint] is present but the route has auth/scope middleware. |
| `request-body.no-content` | 1 | A requestBody object has no media-type entries. |
| `request-body.on-get-or-delete` | 1 | GET or DELETE operation has a request body. |
| `resource.fields-undeclared` | 1 | An API Resource used as a response declares no #[ResourceField] attributes. |
| `resource.response-ambiguous` | 1 | A resource collection response has no #[ResponseResource] naming its item class. |
| `response.no-error` | 1 | Operation has no error responses (4xx/5xx). |
| `response.resource.indeterminate` | 1 | Controller return type cannot be resolved to a concrete response resource. |
| `responseresource.unresolvable` | 1 | #[ResponseResource] references a class that is not a resolvable response resource. |
| `schema.allof-type-conflict` | 1 | allOf members declare conflicting type values. |
| `schema.enum-empty` | 1 | A schema declares an empty enum (enum: []) and is unsatisfiable. |
| `schema.nullable-via-deprecated-keyword` | 1 | Schema uses the deprecated OpenAPI 3.0 nullable: true keyword instead of a type array. |
| `security.invalid-scope` | 1 | Operation requires a scope not declared in securitySchemes. |
| `streaming.no-content-type` | 1 | Streaming operation has no content-type: text/event-stream response. |
| `throws.transitive-missing` | 1 | An action's handler declares @throws exceptions not redeclared on the controller method. |
| `visibility.hide-expose-conflict` | 1 | Route carries overlapping #[Hide] and #[Expose] in the current environment. |
| `enum.values-undocumented` | 2 | Enum field has no description explaining the allowed values. |
| `field.description-missing` | 2 | Schema property has no description. |
| `header.description-missing` | 2 | Response header has no description. |
| `info.description-missing` | 2 | The document info.description is empty. |
| `operation.description-missing` | 2 | Operation has no description (beyond the summary). |
| `parameter.description-missing` | 2 | Parameter has no description. |
| `request-body.description-missing` | 2 | requestBody has no description. |
| `request.empty` | 2 | POST/PUT/PATCH action has no resolvable request-body schema. Add a Data class or FormRequest. |
| `resource.field-type-missing` | 2 | A #[ResourceField] is declared without a resolvable type. |
| `response.empty` | 2 | Non-DELETE action has no resolvable response schema. Return a typed resource or add #[Response]. |
| `response.no-success` | 2 | Operation has no 2xx response. |
| `response.redirect-without-location` | 2 | 3xx response has no Location header. |
| `rule.unknown` | 2 | A Laravel validation Rule object cannot be mapped to a JSON Schema constraint and was dropped. |
| `schema.description-missing` | 2 | Named component schema has no description. |
| `summary.missing` | 2 | Operation has no summary. |
| `tags.no-description` | 2 | Document-level tag has no description. |
| `throws.unmapped` | 2 | A @throws FQCN has no entry in the exception map or #[ExceptionResponse] attribute. |
| `visibility.attribute-no-op` | 2 | Unconditional visibility attribute that has no effect under the active default. |
| `webhook.description-missing` | 2 | Webhook operation has no description. |
| `component.name-naming-inconsistent` | 3 | Component schema name does not follow the configured component_name_case convention. |
| `component.orphaned` | 3 | Component schema is registered but never referenced. |
| `deprecated.attribute` | 3 | A deprecated authoring attribute (#[Deprecated] or @deprecated) is still used on a controller. |
| `field.invalid-format` | 3 | format value is not a recognised OAS 3.1 format (custom formats are advisory but non-standard). |
| `field.name-naming-inconsistent` | 3 | Field name doesn't follow the configured property_name_case convention. |
| `field.no-effect` | 3 | A field attribute was applied but has no visible effect on the schema. |
| `header.name-naming-inconsistent` | 3 | Header name doesn't follow the configured header_case convention. |
| `meta.no-suppression-reason` | 3 | #[IgnoreLint] has no reason parameter. |
| `meta.too-many-suppressions` | 3 | Symbol carries an excessive number of suppression directives. |
| `meta.unknown-rule` | 3 | #[IgnoreLint] references a rule ID not in the registry. |
| `operation.id-naming-inconsistent` | 3 | operationId doesn't follow the configured operation_id_case convention. |
| `operation.summary-equals-description` | 3 | Operation summary and description are identical (redundant). |
| `parameter.name-naming-inconsistent` | 3 | Parameter name doesn't follow the configured parameter_name_case convention. |
| `path.segment-naming-inconsistent` | 3 | URL path segment doesn't follow the configured path_segment_case convention. |
| `path.trailing-slash-inconsistent` | 3 | Trailing-slash usage is inconsistent across paths. |
| `response.status-unconventional` | 3 | Response uses a status code that is unusual for the HTTP method. |
| `scope.overly-broad` | 3 | Operation requires a scope that is broader than the resource warrants. |
| `tag.name-naming-inconsistent` | 3 | Tag name doesn't follow the configured tag_case convention. |
| `tag.undeclared-at-root` | 3 | Operation uses a tag not declared in the document-level tags array. |
| `deprecated.no-replacement` | 4 | Deprecated operation/field has no x-replacement or suggested alternative. |
| `deprecated.no-sunset-date` | 4 | Deprecated operation has no x-sunset date. |
| `info.metadata-incomplete` | 4 | The document info is missing contact and/or license. |
| `parameter.example-missing` | 4 | Parameter has no example. |
| `request-body.example-missing` | 4 | requestBody has no example. |
| `response.example-missing` | 4 | Response media type has no example. |
| `schema.constraints-missing` | 4 | A string has no maxLength, an array no maxItems, or a number no bounds. |
| `schema.example-missing` | 4 | Schema property has no example value. |
<!-- END: lint-rule-catalog -->
