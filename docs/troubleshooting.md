# Troubleshooting

Indexed by symptom. Each entry names the cause and the smallest change that
fixes it.

## First step: read the generation log

`openapi:generate` writes warnings to the Laravel log for anything it
couldn't introspect. Skim them first:

- `[OpenAPI] Response schema introspection failed for …`: the return type
  couldn't be resolved. Add `#[ResponseResource(YourResource::class)]` or type
  the return.
- `[OpenAPI] Schema introspection failed for FormRequest …`: the request's
  `rules()` threw (often because it calls `auth()->user()` or another
  container-only helper at boot). The body schema falls back to a bare object.
- `[OpenAPI] Skipping validation rule extraction for …Data: …`: same, for a
  Data class. The endpoint still appears with a type-only schema (no
  rule-derived constraints).
- `[OpenAPI] … reflection failure …`: a plugin response resolver hit a
  missing class. Check the FQCN names a real class.

## My endpoint doesn't appear in the generated spec

Check, in order:

1. `php artisan route:list` lists the route. If not, it isn't registered.
2. The controller is a real class with a real method. Closure routes are
   supported but carry only what a closure can declare (no `@throws`, often
   no return type).
3. No configured `RouteFilter` excludes it. Bundled filters skip Nova,
   Telescope, Ignition, Horizon, Pulse, and (when installed) Laravel Passport,
   Cashier, and the broadcasting channel-auth routes. Inspect
   `config/openapi.filters`.
4. The action carries no applicable `#[OpenApi\Hide]`. If
   `openapi.visibility.default = 'hidden'`, it must carry an applicable
   `#[OpenApi\Expose]`.
5. Run `php artisan openapi:clear`, then regenerate. A stale cache can mask
   new routes.

## One route shows up as six near-identical operations

The route is registered for **all HTTP verbs** — `Route::any(...)` or Spatie's
`#[Any(...)]`. Such a route genuinely matches every method, so the generator
emits one operation per verb: GET, POST, PUT, PATCH, DELETE, and OPTIONS (HEAD
is dropped — it's implicit for GET). All six share the one built operation
(same summary, body, responses) and differ only by `operationId`
(`{route-name}.{method}`). This is correct: the document reflects what the
route actually accepts.

If the action only handles one verb (the common case for a webhook receiver
`any`-routed by habit), narrow the route to match — this is also better routing
hygiene:

```php
Route::post('api/git-hooks', GitHookController::class);   // not Route::any(...)
// or, with Spatie route attributes:
#[Post('api/git-hooks')]                                  // not #[Any(...)]
```

There is no per-verb suppression — the generator documents the route as
registered. If a catch-all route should not appear at all, hide the whole route
with `#[OpenApi\Hide]` (see [the visibility note above](#my-endpoint-doesnt-appear-in-the-generated-spec)).

## Request body is empty (`request.empty` lint finding)

The action does not type-hint a request DTO the generator recognises. Either:

- Type-hint a Spatie `Data` subclass (or a `FormRequest`) directly on the
  action signature, or
- Type-hint an indirection object (e.g., a Domain Action) and list its base
  class in `config/openapi.request_payload_indirection` so the generator
  descends into it. See
  [Request bodies → Indirect request payloads](request-bodies.md#indirect-request-payloads).

## Why doesn't my inline `$request->validate(...)` produce a request body?

Inline `validate()` calls **are** read (a bounded body scan over the first 10
statements — see [Auto-derivation](auto-derivation.md)), so first check the
generation log: a note names the action and the reason whenever the scan
degrades. Common causes:

- **The rules are built dynamically** (merged arrays, method calls, conditionals).
  Only a literal rules array — or a literal `$rules` property / zero-argument
  `rules()` method — is read; anything else degrades with a note.
- **The call sits inside a conditional** (`if`, closure) or beyond the first 10
  statements — conditional validation is not the canonical request shape.
- **The route is GET/HEAD** — recovered keys become query parameters, not a
  request body; on DELETE they are skipped by design.

Fixes, in preference order:

- Move the rules into a `FormRequest` and type-hint it.
- Move them into a `Data` class and type-hint it.
- Declare the body with `#[OpenApi\RequestBody]` and an explicit schema.

The same applies to `response()->json([...])` literals (response side) and
`$request->query(...)` reads (query parameters): straight-line literal shapes
are inferred; dynamic shapes degrade with a generation-log note and an
attribute escape hatch (`#[Response]`, `#[QueryParam]`).

## Response is a bare `200 OK` with no schema

In order of likelihood:

- **No return type on the action.** Add one. A typed `JsonResource`,
  `ResourceCollection`, `Data`, `DataCollection<…>`, or paginator is
  documented automatically.
- **The response is built by hand and isn't a straight-line literal.** A
  `return response()->json([...])` with a literal array is inferred; a variable
  body, a non-literal status, or a `json()` that only runs conditionally
  degrades with a generation-log note — `#[Response]` is the escape hatch.
- **Paginator without an item type.** `LengthAwarePaginator`, `Paginator`,
  and `CursorPaginator` don't carry the item generic in PHP. Add a
  `@return LengthAwarePaginator<FlightData>` PHPDoc tag. This is the one
  PHPDoc generic the generator reads.
- **`#[ResponseResource]` names a class that isn't a resolvable resource.**
  The generator logs a warning. The named class must be a `JsonResource`
  subclass, a `Data` subclass, or a resource recognised by an enabled plugin.

## Why is this status / summary / tag what it is?

When a derived field surprises you — *"why is this `201`?"*, *"where did this
`List Flights` summary come from?"*, *"why did my `#[Summary]` not win?"* — ask
the tool instead of reading the package source:

```bash
php artisan openapi:why flights.store --fields
```

It builds the operation and prints the source and reason behind every derived
field it produced — `summary`, `description`, success `status`, `tags`,
`deprecated`, `operationId`, `externalDocs`, and `security` — with an authoring
attribute shown winning over the convention it superseded. See
[Explaining derived fields with `--fields`](multi-spec.md#explaining-derived-fields-with---fields).

## I changed a Data/FormRequest and the spec still shows the old shape

Run `php artisan openapi:clear` to drop the cached spec. Until you regenerate
or clear, the playground serves the cached YAML.

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
`#[OpenApi\IgnoreLint('rule.id', reason: '...')]`. See
[Linting → Suppress a finding](linting.md#suppress-a-finding). The meta-rule
`meta.no-suppression-reason` enforces the `reason:` argument.

## A custom `Rule` object yields no constraint (`rule.unknown` finding)

Built-in Laravel rule classes (`Password`, `File`, `ImageFile`, `Dimensions`,
`In`, `Enum`) are recognised. Anything else, including project-local `Rule`
implementations, is dropped, and the field falls back to type-only. Inject
the missing constraint via the
[schema transformer hook](extensions.md#schema-transformer).

## `#[RequestField]` is on a URI param (or `#[PathParam]` on a Data property)

`field.attribute-wrong-scope` (level 1) catches this. The four
`FieldAttribute` subclasses are scoped. Pick the matching one:

- `#[RequestField]` for request-body fields (Data property or `FormRequest`
  `PARAM_*` constant).
- `#[ResponseField]` for response fields (response class constant or
  property).
- `#[PathParam]` for a controller action parameter representing a URI
  segment.
- `#[QueryParam]` for ad-hoc query parameters (class or method).

## Two component schemas with the same basename collide

The generator disambiguates by prepending parent namespace segments (skipping
generic ones like `Http`, `Data`, `V0`). A compound key like
`Projects.CreateProjectData` in `components.schemas` is the resolved name.
That's expected. `component.name-naming-inconsistent` (level 3) flags the
result if it violates the configured `component_name_case`.

> [!NOTE]
> There is no authoring attribute to force a component name. Rename the class
> (or move it to a less ambiguous namespace) if the auto-derived key is wrong.

## `#[Security(['scope'])]` isn't using my custom scheme

Scope-only `#[Security]` defaults to the Passport-derived `oauth2` /
`oauth2ClientCredentials` pair when Passport is installed. To use a different
scheme:

- Declare it under `openapi.security_schemes`. See
  [Recipes → Declare custom security schemes](recipes.md#declare-custom-security-schemes).
- Pass `scheme: 'name'` on every `#[Security]`, or set
  `openapi.security_default_scheme` once to apply it project-wide.

Resolution order: attribute `scheme:` → `security_default_scheme` → Passport
pair → first declared scheme → empty.

## A scope is rejected by `security.invalid-scope`

The scope appears on `#[Security]` (or is derived from `scope:*` middleware)
but isn't listed under the scheme's `flows.*.scopes` map in
`openapi.security_schemes`. Add it there, or target a scheme that declares it.

## Octane / concurrent generation runs produce mixed output

The pipeline is bound as scoped singletons. Octane resets scoped bindings
between requests; each concurrent run gets fresh state.

> [!WARNING]
> If you see mixed output, confirm no host service provider has downgraded a
> package binding to a regular `singleton()`. That shares mutable per-run
> state across requests.

## Generation succeeds but the playground shows nothing

- `GET /api/openapi.yaml` returns the raw spec. If it's empty or stale,
  regenerate with `openapi:generate`.
- The playground is registered only when
  `openapi.routes.playground.enabled` is true. The default is `APP_ENV ===
  'local'`; in other environments the spec route stays but the playground
  does not.

## The spec is valid but `openapi:lint` reports findings I want to defer

Three options:

1. Pass `--level=N` to raise the threshold (`--level=0` = broken only).
2. Add the rule ID to `openapi.lint.disabled_rules` to switch it off
   project-wide.
3. Use `#[OpenApi\IgnoreLint]` per-symbol with a `reason`. Stale suppressions
   are flagged by `meta.suppression-stale`.
