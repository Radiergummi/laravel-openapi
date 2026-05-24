# Troubleshooting

Indexed by symptom. Each entry names the cause and the smallest change that
fixes it.

## First step: read the generation log

`php artisan openapi:generate` writes warnings to the Laravel log for anything
an extractor couldn't introspect. Skim them before anything else:

- `[OpenAPI] Response schema introspection failed for …` — the primary-response
  resolver could not resolve a return type. Add
  `#[ResponseResource(YourResource::class)]` or type the return.
- `[OpenAPI] Schema introspection failed for FormRequest …` — the FormRequest's
  `rules()` threw (often because it calls `auth()->user()` or another
  container-only helper during boot). The body schema falls back to a bare
  object.
- `[OpenAPI] Skipping validation rule extraction for …Data: …` — same story
  for a Data class. The endpoint still appears, but the schema is type-only
  with no rule-derived constraints.
- `[OpenAPI] … reflection failure …` — a plugin response resolver hit a
  missing class between attribute resolution and schema build. Check the FQCN
  names a real class.

## My endpoint doesn't appear in the generated spec

Check, in order:

1. `php artisan route:list` shows the route. If not, it isn't registered — the
   generator only sees what Laravel sees.
2. The route's controller is a real class with a real method. Closure routes
   are supported, but only carry whatever an inline closure can declare — no
   `@throws` PHPDoc, often no return type.
3. The route isn't excluded by a configured `RouteFilter`. The shipped filters
   skip Nova, Telescope, Ignition, and (when present) Laravel Passport routes.
   Inspect `config/openapi.filters` and remove any filter you don't want.
4. The action does not carry `#[OpenApi\Hide]` (unconditionally or in the
   current `APP_ENV`), and if `openapi.visibility.default` is `'hidden'`, the
   action carries an applicable `#[OpenApi\Expose]`.
5. `php artisan openapi:clear` then regenerate — a stale cached spec masks
   new routes.

## Request body is empty (`request.empty` lint finding)

The action does not type-hint a request DTO the generator recognises. Either:

- Type-hint a Spatie `Data` subclass (or a `FormRequest`) directly on the
  action signature, or
- Type-hint an indirection object (e.g. a Domain Action) and list its base
  class in `config/openapi.request_payload_indirection` so
  `PayloadParameterScanner` descends into it (see
  [Request bodies → Indirect request payloads](request-bodies.md#indirect-request-payloads)).

## Why doesn't my inline `$request->validate(...)` produce a request body?

This is the [OAPI-017](known-gaps.md#oapi-017--no-method-body-inference)
method-body inference gap. The generator reads *signatures* only — it never
parses controller method bodies, so inline `validate()`, `request()->validate()`,
and ad-hoc `response()->json([…])` calls are invisible. The fix is one of:

- Move the inline rules into a `FormRequest` and type-hint it on the action.
- Move them into a `Data` class and type-hint that.
- Or declare the body with `#[OpenApi\RequestBody]` + an explicit schema for
  the case the validation lives only at the call site.

## Response is a bare `200 OK` with no schema

Causes, in order of likelihood:

- **No return type on the action.** Add one. A typed `JsonResource`,
  `ResourceCollection`, `Data`, `DataCollection<…>`, or a paginator return is
  documented automatically.
- **Paginator with no item type.** `LengthAwarePaginator`, `Paginator`, and
  `CursorPaginator` do not carry the item generic in PHP types. Add a
  `@return LengthAwarePaginator<FlightData>` PHPDoc tag — the generator reads
  that single PHPDoc generic, no other body inference happens.
- **`#[ResponseResource]` names a class that isn't a resolvable response
  resource.** The generator logs a warning during generation. The named class
  must be a `JsonResource` subclass, a `Data` subclass, or another resource
  recognised by an enabled plugin.

## I changed a Data/FormRequest and the spec still shows the old shape

`php artisan openapi:clear` drops the cached spec. The generator writes a YAML
file on the configured path; until you regenerate (or clear), the playground
serves the cached document.

## Lint reports a finding I don't understand

Every rule has a description. Print the live catalog with descriptions:

```bash
php artisan openapi:lint --list
```

Filter to a single rule:

```bash
php artisan openapi:lint --only=field.attribute-wrong-scope
```

To silence a rule on one symbol after deciding the finding is acceptable, use
`#[OpenApi\IgnoreLint('rule.id', reason: '...')]` — see
[Linting → Suppress a finding](linting.md#suppress-a-finding). The meta-rule
`meta.no-suppression-reason` enforces the `reason:` argument.

## A custom `Rule` object yields no constraint (`rule.unknown` finding)

`ValidationRulesToSchema` handles the built-in Laravel rule classes
(`Password`, `File`, `ImageFile`, `Dimensions`, `In`, `Enum`). Any other
`Rule` object — including project-local implementations — is silently dropped
and the field falls back to type-only. Use the
[schema transformer hook](extensions.md#schema-transformer) to inject the
missing constraint.

## `#[RequestField]` is on a URI param (or `#[PathParam]` on a Data property)

`field.attribute-wrong-scope` (SpatieData plugin, level 1) catches this. The
four `FieldAttribute` subclasses are scoped — pick the one whose target
matches:

- `#[RequestField]` — request-body fields (Data property / `FormRequest`
  `PARAM_*` constant).
- `#[ResponseField]` — response fields (response class constant / property).
- `#[PathParam]` — controller action parameter for a URI segment.
- `#[QueryParam]` — class or method, ad-hoc query parameter.

## Two component schemas with the same basename collide

`ComponentSchemaRegistry` disambiguates automatically by prepending parent
namespace segments (skipping generic ones like `Http`, `Data`, `V0`). A
compound name like `Projects.CreateProjectData` in `components.schemas` means
a collision was resolved — that's expected. The lint rule
`component.name-naming-inconsistent` (level 3) reports the resulting name if
it violates the configured `component_name_case` convention.

> [!NOTE]
> There is no authoring attribute to force a specific component name. Rename
> the class (or move it to a less ambiguous namespace) if the auto-derived
> key is wrong.

## `#[Security(['scope'])]` isn't using my custom scheme

By default, scope-only `#[Security]` requirements target the Passport-derived
`oauth2` / `oauth2ClientCredentials` pair when Passport is installed. For apps
with a different scheme:

- Declare it under `openapi.security_schemes` (see
  [Recipes → Declare custom security schemes](recipes.md#declare-custom-security-schemes)).
- Either pass `scheme: 'name'` on every `#[Security]` instance, or set
  `openapi.security_default_scheme` once to apply it project-wide.

**Resolution order:** attribute `scheme:` → `security_default_scheme` →
Passport pair → first declared scheme → empty.

## A scope is rejected by `security.invalid-scope`

The scope appears on `#[Security]` (or is derived from `scope:*` middleware)
but is not listed under the targeted scheme's `flows.*.scopes` map in
`openapi.security_schemes`. Add the scope there, or change the requirement
to a scheme that declares it.

## Octane / concurrent generation runs produce mixed output

The pipeline classes are bound as **scoped** singletons in
`OpenApiServiceProvider`. Octane resets scoped bindings between requests, so
concurrent generation runs each get fresh `ComponentSchemaRegistry` and
`ExampleFileLoader` instances.

> [!WARNING]
> If you see mixed output, confirm you haven't downgraded any of the package's
> bindings to a regular `singleton()` in a host service provider — that would
> share mutable per-run state across requests.

## Generation succeeds but the playground (Scalar) shows nothing

- `GET /api/openapi.yaml` returns the raw spec — open it directly. If it's
  empty or stale, regenerate (`php artisan openapi:generate`).
- The playground route is registered only when
  `openapi.routes.playground.enabled` is true. The default is
  `APP_ENV === 'local'`; in other environments the spec route stays but the
  playground does not.

## The spec is valid but `php artisan openapi:lint` reports findings I want to defer

Three options:

1. Pass `--level=N` to raise the threshold (`--level=0` = broken only).
2. Add the rule ID to `openapi.lint.disabled_rules` to switch it off
   project-wide.
3. Use `#[OpenApi\IgnoreLint]` per-symbol with a `reason`. Stale suppressions
   are flagged by `meta.suppression-stale`.
