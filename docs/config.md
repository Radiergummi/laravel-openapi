# Configuration reference

Publish the config file with:

```bash
php artisan vendor:publish --tag=openapi-config
```

This writes `config/openapi.php` to your application. Every entry is commented;
this page is the at-a-glance summary.

## Top-level keys

| Key | Purpose |
|---|---|
| `info` | Populates the top-level `info` object (`title`, `version`, `description`, etc.). |
| `servers` | List of `OA\Server` entries. Default uses `APP_URL`. |
| `tags` | Document-level tag descriptions keyed by tag name. |
| `output_path` | Absolute path the `openapi:generate` command writes to and the spec route serves from. Defaults to `storage_path('openapi.yaml')`. |
| `operation_id_strategy` | How each operation's `operationId` is derived: `'route-name'` (default — route name, or `{method}_{path}` for unnamed routes) or `'method-path'` (always `{method}_{path}`, ignoring route names). Output is always sanitised to satisfy `operation.id-invalid-chars`. Unknown values fall back to `'route-name'`. |
| `exception_responses` | Maps exception FQCNs (or short names) to `['status', 'description']`. See [precedence](#exception-response-precedence) below. |
| `middleware_responses` | 401/403/429 responses keyed by Laravel middleware alias (`auth`/`scope`/`can`/`throttle`) — only fills status codes no `@throws`-derived response already supplied. |
| `error_envelope` | Envelope preset wrapping inferred 4xx/5xx response bodies: `'none'` (default — status + description, no body schema), `'laravel'`, `'rfc7807'`, or `'json-api'`; or a custom `ErrorResponseResolver` class-string. See [Recipes → Choosing an error envelope](recipes.md#choosing-an-error-envelope). |
| `security_schemes` | Custom OpenAPI security schemes. Merged with the auto-derived defaults (Passport's OAuth2 flows; a `sanctum` http/bearer scheme when any route uses `auth:sanctum`); config wins on key collision. See [Recipes → Declare custom security schemes](recipes.md#declare-custom-security-schemes). |
| `security_default_scheme` | Default scheme name targeted by scope-only `#[Security]` requirements when no `scheme:` is passed. |
| `security_middleware_map` | Map of custom guard-middleware name → a scheme declared in `security_schemes`. A route carrying a mapped middleware emits that scheme's per-operation requirement (taking precedence over the auto-derived `auth:*`/`scope:*` resolution for that route), so project-specific guards don't make a protected endpoint look public. |
| `plugins` | Ordered list of `Plugin` class-strings. Ships with `SpatieDataPlugin` and `ApiResourcesPlugin` enabled; `QueryBuilderPlugin` and `FractalPlugin` shipped commented out. |
| `request_payload_indirection` | Base classes whose constructors are also scanned for Data-class parameters. See [Request bodies → Indirect request payloads](request-bodies.md#indirect-request-payloads). |
| `examples` | Example synthesis: `synthesise` (default `true`) toggles Faker-generated examples for fields with no authored example; `faker_seed` makes them deterministic. |
| `visibility` | `default` accepts `'public'` (every route documented unless `#[Hide]`) or `'hidden'` (every route excluded unless `#[Expose]`). See [Recipes → Switch between public-default and hidden-default visibility](recipes.md#switch-between-public-default-and-hidden-default-visibility). |
| `overrides` | Spec-only per-route operation field overrides, keyed by route name or URI glob. See [Operation overrides](#operation-overrides) below. |
| `routes` | Spec/playground route registration. See below. |
| `filters` | Route-exclusion filters. Ships with filters that exclude the library's own spec/playground routes plus routes from Nova, Telescope, Ignition, Horizon, Pulse, and (when installed) Passport, Cashier, and the broadcasting channel-auth endpoints. Remove `SkipSelfRoutes` to document the library's `/api/openapi.yaml` and `/api/docs` endpoints in your spec. |

## Exception-response precedence

For each `@throws` declaration the generator resolves the response in this order (highest wins):

1. `#[ExceptionResponse(...)]` attribute on the exception class
2. `exception_responses` config — exact FQCN match
3. `exception_responses` config — short-name match (basename)

If none match, the `throws.unmapped` lint rule fires.

`middleware_responses` is independent and runs *after* the per-`@throws` resolution above: it
adds 401/403/429 derived from the route's middleware stack only for status codes no
`@throws`-derived response already filled. An explicit `@throws AuthenticationException` therefore
wins over the `auth` middleware entry.

## Routes

```php
'routes' => [
    'enabled' => true,           // set false to register no routes at all
    'prefix' => 'api',           // spec + playground mount here
    'middleware' => ['web'],
    'spec' => [
        'enabled' => true,        // always on by default
        'uri' => 'openapi.yaml',  // GET /api/openapi.yaml
    ],
    'playground' => [
        'enabled' => env('APP_ENV') === 'local', // local-only by default
        'uri' => 'docs',           // GET /api/docs
        'renderer' => 'scalar',    // 'scalar' (default) or 'swagger-ui'
    ],
],
```

The playground `renderer` chooses which UI the `/api/docs` route serves:
`'scalar'` (the default) or `'swagger-ui'`. Both are loaded from a CDN and
pointed at the same spec endpoint, so the switch is config-only — no asset
publishing. Swagger UI is offered for teams (e.g. migrating from
`darkaonline/l5-swagger`) already standardised on its layout and "Try it out"
affordances. Any unrecognised value falls back to Scalar. In multi-spec mode the
renderer is global; each spec's playground still points at its own document.

Because the spec and playground mount under `prefix` (default `api`), they appear
in `php artisan route:list --path=api` alongside your own API routes. This is
cosmetic only — the `SkipSelfRoutes` filter already keeps them out of the
generated document. Set `prefix` to a dedicated segment (e.g. `_openapi`) if you
prefer they not show up under your `api` listing.

## Operation overrides

`overrides` is a spec-only escape hatch for setting operation-level fields directly from config —
no controller edits. It exists for the cases convention can't reach: vendor packages ship
controllers you can't annotate, a legacy route needs `deprecated: true` without a code commit, or a
release correction (a renamed `operationId`, an extra tag) shouldn't require a PR against
application code. A late pipeline stage mutates the emitted document and never touches the host app.

Each entry maps a **route name** or a **URI glob** to a field-array:

```php
'overrides' => [
    'users.show' => [
        'operationId' => 'getCurrentUser',
        'tags'        => ['Identity'],
        'deprecated'  => true,
    ],
    'api/v1/legacy/*' => [
        'x-internal' => true,
    ],
],
```

**Allowed fields:** `operationId`, `summary`, `description`, `tags`, `deprecated`, and any `x-*`
vendor extension (emitted under the operation's `x` object, e.g. `x-internal: true`). The set is
write-only — there is no field-removal semantics, and nested response/parameter structures are out
of scope. A non-allowlisted field key is skipped and reported by the `overrides.unknown-field`
lint rule.

**Matching** is per operation (URI + method). A key is treated as a route name if a route with
exactly that name exists; otherwise it is a URI glob, where `*` matches any run of characters
*including* `/`. When several keys match one operation, fields merge in ascending precedence so the
most specific source wins per field:

1. URI globs, ordered by specificity (count of literal non-`*` characters); ties broken by
   declaration order, later key winning.
2. The exact route-name key, applied last (highest precedence).

A key matching neither a route name nor any URI is reported by the `overrides.unused` lint rule.

**Precedence against other sources.** Overrides beat plugin contributions and convention-derived
values (attributes, docblocks, route names). A code-based
[`transformDocument()`](extensions.md) callback still runs last and retains the final word.

## Lint keys

| Key | Purpose |
|---|---|
| `lint.level` | Default severity level when `--level` is not passed to `openapi:lint`. |
| `lint.enabled_rules` | `null` = all rules at or below the level. A non-null array is an explicit allowlist. |
| `lint.disabled_rules` | Always-off rules regardless of level. `spec.invalid` cannot be disabled. |
| `lint.severity_overrides` | Per-rule level remap: `'rule.id' => level`. `spec.invalid` is exempt. |
| `lint.style` | Per-convention case expectations for naming rules. See [Linting → Style conventions](linting.md#style-conventions-naming-rules). |
| `lint.baseline` | Path to a baseline file; `null` disables the baseline feature. |
| `lint.rules` | Extra custom rule class-strings appended to the registry. |

See [Linting](linting.md) for the full lint surface, severity scale, and rule
catalog.
