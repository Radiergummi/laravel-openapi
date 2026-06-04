# Linting

`openapi:lint` generates the spec and reports documentation gaps and
convention violations.

```bash
php artisan openapi:lint            # default: level 1 (broken + degraded)
php artisan openapi:lint --level=max
```

For the tree-walk internals (needed only when writing a custom rule), see
[Architecture → Lint subsystem](architecture.md#lint-subsystem).

## Severity levels

Each rule carries a numeric level. The scale is an open-ended gradient of
decreasing severity (lower = more severe), modelled on PHPStan. Levels are
not fixed categories, and finer levels may be added later. The `--level` flag
(or `config/openapi.lint.level`) sets the threshold; only rules at or below
it run.

| Level | Name | Meaning |
|---|---|---|
| 0 | Broken | A conformant OpenAPI validator rejects the document, or a major consumer (Scalar, codegen) fails outright. |
| 1 | Degraded | The document parses, but the violation makes part of it incorrect: it misrepresents the API, drops information, or misbehaves in tooling. |
| 2 | Underspecified | Correct but incomplete. A component that should carry detail is missing it. |
| 3 | Inconsistent | Complete and correct, but violates a naming/structure convention or a hygiene meta-rule. |
| 4 | Improvable | Optional polish whose absence costs nothing concrete. |

Pass `--level=max` to run every rule. The default threshold is level 1
(broken + degraded).

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

## Auto-fixing findings

Some findings have one correct, mechanical fix — a duplicate attribute, an
attribute that does nothing. `--fix` applies those directly to the PHP source
that triggered them, then reports whatever is left for you to resolve by hand:

```bash
# Apply every fixable finding, then report the rest
php artisan openapi:lint --fix

# CI-safe dry run: write nothing, exit 1 if any fix is pending (like `pint --test`)
php artisan openapi:lint --check
```

`--fix` and `--check` compose with `--only`, `--skip`, `--level`, `--path`, and
`--spec`, so you can scope a fix run to one rule or one slice of routes. Exit
codes follow the usual convention: `0` when nothing remains (or nothing was
pending, for `--check`), `1` when unfixed findings remain or a fix is pending.

It edits PHP source only — never the generated JSON/YAML, and never method
bodies or business logic. After a `--fix` run it prints the files it touched;
run your formatter over them (e.g. `vendor/bin/pint --dirty`) to match your
project style. A finding is only auto-fixable when its owning rule opts in
(by implementing `Lint\Fix\FixableRule`); everything else is reported as before.

Currently fixable (all removals of a redundant or no-op attribute):
`tag.duplicate`, `queryparam.duplicate`, `response.duplicate-status`,
`link.duplicate-name`, and `field.no-effect`.

## Suppress a finding

Use the `#[OpenApi\IgnoreLint]` attribute. Each instance suppresses exactly
one rule; stack the attribute for several. Always pass a `reason`:

```php
use Radiergummi\OpenApi\Attributes as OpenApi;

#[OpenApi\IgnoreLint('response.no-error', reason: 'Internal-only endpoint, errors are handled by the framework')]
public function internal(): JsonResponse { … }
```

Scope follows the annotated symbol:

- **Class**: silences the rule for every operation in the controller.
- **Method**: silences it for that action only.
- **Property**: silences `field.*` findings for that property. Place it on
  the Data-class property.

> [!WARNING]
> `spec.invalid` can never be suppressed. Run with `--no-suppress` to ignore
> all directives.

Meta-rules enforce directive hygiene:

- `meta.no-suppression-reason`: directive has no `reason` parameter.
- `meta.suppression-stale`: directive did not suppress any finding.
- `meta.too-many-suppressions`: a symbol carries an excessive number of
  directives.

### Where can `#[IgnoreLint]` go?

The attribute can target controllers, controller methods, and the payload classes a plugin
teaches Core about. For class-level placements, the directive suppresses findings on every
property of the component schema that class produces.

| Scope    | Controller | Controller method | FormRequest | Spatie `Data` | `JsonResource` |
|----------|------------|-------------------|-------------|---------------|----------------|
| Class    | ✓          | —                 | ✓           | ✓             | ✓              |
| Method   | —          | ✓                 | —           | —             | —              |
| Property | —          | —                 | ✓ (1)       | ✓             | —              |

(1) FormRequest properties: place `#[IgnoreLint]` on a typed property if the request DTO is
declared with promoted constructor properties or class fields. For the rules-array idiom,
property-level suppressions are not available — use class-level scope instead.

`JsonResource` reaches the collector through the component-schema registry post-generation,
so `#[IgnoreLint]` on a `JsonResource` subclass works without the class appearing as a
constructor parameter.

#### Limitation — nested-Data composition without `$ref`

Property-scope suppressions resolve through the **component schema's owning class**. When a
Data class composes another Data class as a typed property, the nested type is normally
emitted as a `$ref` to its own component schema — that schema's `sourceClass` points at the
nested class, and a property-scope `#[IgnoreLint]` on the nested class's property suppresses
findings as expected.

The exception is when the nested type is **inlined** into the parent's schema (no `$ref` is
generated). In that case findings on the inlined branch are stamped with the **parent's**
source class, not the nested class's. A property-scope directive on the nested class's field
will not match.

Workaround: place the directive at class scope on the parent (or the nested class — both
suppress every property of their component schema). For the inlined-composition case the
parent-class-scope form is the form that takes effect.

## Style conventions (naming rules)

Naming rules read their expected case convention from `config/openapi.lint.style`:

| Config key | Default | Affected rule |
|---|---|---|
| `operation_id_case` | `dot` | `operation.id-naming-inconsistent` |
| `property_name_case` | `camel` | `field.name-naming-inconsistent` |
| `path_segment_case` | `kebab` | `path.segment-naming-inconsistent` |
| `path_parameter_case` | `camel` | `parameter.name-naming-inconsistent` (path parameters) |
| `query_parameter_case` | `snake` | `parameter.name-naming-inconsistent` (query parameters) |
| `tag_case` | `pascal` | `tag.name-naming-inconsistent` |
| `header_case` | `train` | `header.name-naming-inconsistent` |

Supported case values: `dot`, `kebab`, `snake`, `camel`, `pascal`, `train`,
`screaming_snake`.

### Notes on the defaults

- **`operation_id_case = dot`** accepts kebab-case segments inside the
  dot-separated identifier, so `auth.resolve-account` and
  `api.v0.projects.list-active` pass alongside strict lowercase-dot
  identifiers like `api.v0.projects.index`. Names like `get_mcp` or
  `apiV0ProjectsIndex` still fail.
- **`path_parameter_case = camel`** matches Laravel's route-binding idiom —
  the parameter name comes from the controller method's variable
  (`$deviceId` → `{deviceId}`).
- **`query_parameter_case = snake`** matches the JSON:API convention
  (`per_page`, `sort_by`).
- **`path_segment_case = kebab`** accepts a short file-extension tail on a
  segment: a tail of `.<ext>` where `<ext>` matches `^[a-z0-9]{1,8}$` and
  the head conforms to the configured case. Examples that pass:
  `/api/openapi.yaml`, `/feed.atom`, `/sitemap.xml`,
  `/reports/quarterly.pdf`. Examples that still fail: `/api/Some.yaml`
  (uppercase head), `/api/file.YAML` (uppercase tail),
  `/api/name.somelongextension` (tail longer than 8 chars).
- **`component_name_case = pascal`** (the default for component schema
  names) covers the basename-collision disambiguator's output: when two
  payload classes share a basename, the registry produces a PascalCase
  concatenation such as `UsersContactInfoRequest`, which the default rule
  passes without further configuration.

## Static checks (PHPStan)

A bundled PHPStan extension catches a subset of attribute misuses at edit time —
the cases that are decidable from the AST alone, before the spec is generated.
Anything that needs a booted rule registry, route information, or generated
spec state stays in `openapi:lint`.

The extension auto-registers when `phpstan/extension-installer` is present.
Otherwise add it manually:

```neon
# phpstan.neon
includes:
    - vendor/radiergummi/laravel-openapi/extension.neon
```

PHPStan identifiers cannot contain dashes, so the static identifiers use
camelCase. Where a static rule has a runtime counterpart, that counterpart's
ID is listed below — the static one fires first, the runtime one is the
backstop for cases the static analysis can't see (e.g. environment-scoped
visibility).

| Static identifier | Catches | Runtime counterpart |
|---|---|---|
| `openapi.link.bothOperationTargets` | `#[Link]` sets both `operationId` and `operationRef`. | `link.both-operation-id-and-ref` |
| `openapi.link.missingOperationTarget` | `#[Link]` sets neither `operationId` nor `operationRef`. | `link.neither-operation-id-nor-ref` |
| `openapi.example.bothValueAndFile` | `#[Example]` / `#[ResponseExample]` sets both `value` and `file`. | — (constructor throws at reflection time) |
| `openapi.example.missingValueOrFile` | `#[Example]` / `#[ResponseExample]` sets neither `value` nor `file`. | — |
| `openapi.expose.onlyAndExcept` | `#[Expose]` sets both `only` and `except`. | — |
| `openapi.hide.onlyAndExcept` | `#[Hide]` sets both `only` and `except`. | — |
| `openapi.visibility.hideExposeConflict` | Unconditional `#[Hide]` and `#[Expose]` on the same target. | `visibility.hide-expose-conflict` (env-aware) |
| `openapi.security.publicAndSecuredConflict` | `#[PublicEndpoint]` with `#[Security]` on the same target. | `publicendpoint.contradicts-middleware` (middleware-aware) |
| `openapi.response.duplicateStatus` | Two `#[Response]` attributes with the same status on one operation. | `response.duplicate-status` |
| `openapi.responseHeader.duplicate` | Two `#[ResponseHeader]` attributes with the same name/status. | — |
| `openapi.response.refAndSchema` | `#[Response]` sets both `ref` and `schema` (schema wins; `ref` is silently dropped). | — |
| `openapi.field.rangeOrdering` | Field attribute has `min* > max*` for `minimum`/`maximum`, `minLength`/`maxLength`, or `minItems`/`maxItems` (literal numerics only). | — |
| `openapi.queryParam.requiredWithDefault` | `#[QueryParam(required: true, default: …)]` — a default makes the parameter implicitly optional, contradicting `required: true`. | — |
| `openapi.exceptionResponse.nonThrowable` | `#[ExceptionResponse]` is attached to a class that doesn't implement `Throwable` — the standard-responses extractor only consults the attribute when resolving `@throws` FQCNs, so it is silently ignored elsewhere. | — |

The extension also ships two PHPStan type aliases — `OpenApiPrimitiveType`
and `HttpStatusCode` — used by the attribute PHPDocs so consumer PHPStan
runs flag misspelled `type:` literals (`'int'` vs `'integer'`) and
out-of-range status codes at the call site.

## Adding a custom rule

1. Implement `Radiergummi\OpenApi\Lint\Rules\Rule` and one or more
   visitor interfaces from `Core/Lint/Rules/Visitors/`.
2. Add the class to `config/openapi.lint.rules`, or register it from a plugin
   via `$registry->addRule(YourRule::class)`. See
   [Plugin authoring](plugin-authoring.md).

## Rule catalog

Built-in rule IDs. Run `openapi:lint --list` for the live catalog including
plugin-registered rules.

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
| `path.parameter-undefined` | 0 | A declared path parameter doesn't appear in the path template. Both `{name}` and `{name?}` placeholders satisfy the rule regardless of the OAS `required` flag (Laravel's optional-segment URI syntax is the source of truth for placeholders). |
| `ref.broken` | 0 | A $ref points to a component that doesn't exist in the spec. |
| `response.description-missing` | 0 | Response has no description. OAS 3.1 requires description on every Response Object. |
| `response.duplicate-status` | 0 | Two responses on the same operation share the same status code. |
| `response.ref-unresolvable` | 0 | #[Response(ref:)] points to a class no registered schema resolver can resolve; the response is emitted without a body schema. |
| `schema.enum-type-mismatch` | 0 | Schema enum contains values that don't match the declared type. |
| `schema.required-without-property` | 0 | required names a field not in properties. |
| `security.scheme-undefined` | 0 | Operation references a security scheme not declared at the document level. |
| `server.invalid-url` | 0 | A servers[].url is malformed. |
| `server.variable-undeclared` | 0 | A server URL template uses a {var} with no matching variables entry. |
| `spec.invalid` | 0 | Spec fails swagger-php validation. Cannot be suppressed or remapped. |
| `spec.route-orphaned` | 0 | A route's #[Spec] list resolves to no defined specs. |
| `spec.unknown-reference` | 0 | #[Spec] references a spec not declared in config. |
| `webhook.name-duplicate` | 0 | Two webhooks share the same name. |
| `externaldocs.invalid-url` | 1 | externalDocs.url is not a valid URL. |
| `field.attribute-wrong-scope` | 1 | #[RequestField] on a URI parameter, or #[PathParam] on a Data-class property. |
| `field.conflicting-type` | 1 | Field declares conflicting type and format values. |
| `header.invalid-name` | 1 | Header name contains invalid characters. |
| `link.invalid-operation` | 1 | Link references an operationId that doesn't exist in the document. |
| `multipart.file-without-multipart` | 1 | Data class has a file property but the request body isn't multipart/form-data—produces an incorrect spec. |
| `operation.id-invalid-chars` | 1 | operationId is not a codegen-safe identifier. |
| `operation.id-missing` | 1 | Operation has no operationId. |
| `operation.security-missing` | 1 | Route enforces auth middleware but the operation declares no security, implying the endpoint is public. |
| `operation.tag-missing` | 1 | Operation has no tags. |
| `parameter.example-conflict` | 1 | A parameter sets both example and examples (mutually exclusive). |
| `parameter.query-array-no-explode` | 1 | Array query parameter is missing explode: true. |
| `publicendpoint.contradicts-middleware` | 1 | #[PublicEndpoint] is present but the route has auth/scope middleware. |
| `request-body.no-content` | 1 | A requestBody object has no media-type entries. |
| `request-body.on-get-or-delete` | 1 | GET or DELETE operation has a request body. |
| `resource.fields-undeclared` | 1 | An API Resource used as a response declares no #[ResourceField] attributes. Skipped when the resolved resource class is the abstract `Illuminate\Http\Resources\Json\JsonResource` base or any abstract subclass — anonymous `Model::toResource()` returns are passed silently, with `resource.response-ambiguous` handling the "no concrete resource class" signal. |
| `resource.response-ambiguous` | 1 | A resource collection response has no #[ResponseResource] naming its item class. |
| `response.no-error` | 1 | Operation has no error responses (4xx/5xx). |
| `schema.allof-type-conflict` | 1 | allOf members declare conflicting type values. |
| `schema.enum-empty` | 1 | A schema declares an empty enum (enum: []) and is unsatisfiable. |
| `schema.nullable-via-deprecated-keyword` | 1 | Schema uses the deprecated OpenAPI 3.0 nullable: true keyword instead of a type array. |
| `security.invalid-scope` | 1 | Operation requires a scope not declared in securitySchemes. |
| `streaming.no-content-type` | 1 | Streaming operation has no content-type: text/event-stream response. |
| `throws.transitive-missing` | 1 | An action's handler declares @throws exceptions not redeclared on the controller method. |
| `queryparam.duplicate` | 1 | Two #[QueryParam] attributes on the same controller method share the same name; the later one silently overrides the earlier (names must be unique per operation). |
| `visibility.hide-expose-conflict` | 1 | Route carries overlapping #[Hide] and #[Expose] in the current environment. |
| `enum.values-undocumented` | 2 | Enum field has no description explaining the allowed values. |
| `field.description-missing` | 2 | Schema property has no description. |
| `header.description-missing` | 2 | Response header has no description. |
| `info.description-missing` | 2 | The document info.description is empty. |
| `operation.description-missing` | 2 | Operation has no description (beyond the summary). |
| `parameter.description-missing` | 2 | Parameter has no description. |
| `request-body.description-missing` | 2 | requestBody has no description. |
| `request.empty` | 2 | POST/PUT/PATCH action has no resolvable request-body schema. Add a Data class or FormRequest. |
| `request-body.schema-degraded` | 1 | A FormRequest threw during introspection; its request body schema is a placeholder and does not reflect the real validation rules. |
| `errors.resolver-failed` | 2 | A registered `ErrorResponseResolver` threw while building an error response; the extractor caught the throw and the chain continued, but the offending resolver should be fixed. |
| `resource.field-type-missing` | 2 | A #[ResourceField] is declared without a resolvable type. |
| `response.no-success` | 2 | Operation has no 2xx response. |
| `response.success-empty-body` | 2 | A 2xx response (other than 204/205/304) declares no body schema. Likely a void-return controller. |
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
| `operation.return-type-missing` | 3 | Action has no typed return value or response attribute, so no response schema can be inferred. |
| `operation.summary-equals-description` | 3 | Operation summary and description are identical (redundant). |
| `overrides.unknown-field` | 3 | An `openapi.overrides` block sets a field outside the allowlist (operationId, summary, description, tags, deprecated, x-*). |
| `overrides.unused` | 3 | An `openapi.overrides` key matches no route name and no route URI. |
| `parameter.name-naming-inconsistent` | 3 | Parameter name doesn't follow the configured `path_parameter_case` (path parameters) or `query_parameter_case` (query parameters) convention. |
| `path.segment-naming-inconsistent` | 3 | URL path segment doesn't follow the configured path_segment_case convention. |
| `path.trailing-slash-inconsistent` | 3 | Trailing-slash usage is inconsistent across paths. |
| `response.status-unconventional` | 3 | Response uses a status code that is unusual for the HTTP method. |
| `scope.overly-broad` | 3 | Operation requires a scope that is broader than the resource warrants. |
| `spec.config-orphaned` | 3 | A configured spec has zero assigned routes after evaluation. |
| `tag.duplicate` | 3 | The same #[Tag] is applied more than once to a controller method (redundant; tags are deduplicated in the output). |
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
