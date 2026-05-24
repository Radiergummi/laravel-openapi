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
| `exception_responses` | Maps exception FQCNs to `['status', 'description']`. Checked after `#[ExceptionResponse]` attributes. |
| `middleware_responses` | Toggles for 401/403/429 responses derived from `auth`/`scope`/`throttle` middleware. |
| `security_schemes` | Custom OpenAPI security schemes. Merged with the Passport-derived defaults; config wins on key collision. See [Recipes → Declare custom security schemes](recipes.md#declare-custom-security-schemes). |
| `security_default_scheme` | Default scheme name targeted by scope-only `#[Security]` requirements when no `scheme:` is passed. |
| `plugins` | Ordered list of `Plugin` class-strings. Ships with `SpatieDataPlugin` and `ApiResourcesPlugin` enabled; `QueryBuilderPlugin` and `FractalPlugin` shipped commented out. |
| `request_payload_indirection` | Base classes whose constructors are also scanned for Data-class parameters. See [Request bodies → Indirect request payloads](request-bodies.md#indirect-request-payloads). |
| `visibility` | `default` accepts `'public'` (every route documented unless `#[Hide]`) or `'hidden'` (every route excluded unless `#[Expose]`). See [Recipes → Switch between public-default and hidden-default visibility](recipes.md#switch-between-public-default-and-hidden-default-visibility). |
| `routes` | Spec/playground route registration. See below. |
| `filters` | Route-exclusion filters. Ships with filters that exclude Nova, Telescope, Ignition, and (when present) Passport routes. |

## Routes

```php
'routes' => [
    'enabled' => true,           // set false to register no routes at all
    'prefix' => 'api',
    'middleware' => ['web'],
    'spec' => [
        'enabled' => true,        // always on by default
        'uri' => 'openapi.yaml',  // GET /api/openapi.yaml
    ],
    'playground' => [
        'enabled' => env('APP_ENV') === 'local', // local-only by default
        'uri' => 'docs',           // GET /api/docs
    ],
],
```

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
