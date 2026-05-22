# Multi-spec

Multi-spec lets one Laravel application produce several independent OpenAPI documents from a single set of routes.

## Concept

A **spec** is one generated OpenAPI document. Without a `'specs'` key in your config there is always one spec — the **default** — and the package behaves exactly as it always has.

Add a `'specs'` map to produce additional named documents alongside the default. Each named spec gets its own generated file, its own HTTP endpoint, and its own Scalar playground. Routes are distributed across specs by config-driven matching or by explicit `#[Spec]` attributes.

The root config keys (`info`, `servers`, `tags`, `output_path`) define the default spec. Named specs inherit these values and may override any of them.

## When to use it

- **API versioning** — `v1` and `v2` docs served at separate URLs while routes live in the same app.
- **Audience splits** — a `public` spec and an `internal` spec, where internal routes are gated by different middleware and not served over HTTP.
- **Domain splits** — a `storefront` spec and an `admin` spec partitioned by controller namespace.

## Inclusion rule

A route is included in a spec when **all four** conditions hold:

| Step | Rule |
|---|---|
| 1 — global filter | No `RouteFilter` in `config('openapi.filters')` returns `shouldSkip() = true`. |
| 2 — spec membership | Either the route has no `#[Spec]` and the spec's `match` config matches, or the route has `#[Spec]` and the spec name is in its list. |
| 3 — not hidden | The route is not `#[Hide]`-d for the current environment. |
| 4 — visible | `visibility.default = 'public'`, or the route carries `#[Expose]` for the current environment. |

The `default` spec has no `match` config by default, so it matches every route — it is the catch-all. Named specs have independent `match` configs; a route may satisfy several at once and appear in multiple specs.

## Config reference

The `'specs'` map sits at the top level of `config/openapi.php`. Each key names a spec.

```php
// config/openapi.php

return [
    // Root keys define the implicit 'default' spec.
    'info'        => ['title' => 'My API', 'version' => '1.0'],
    'servers'     => [['url' => env('APP_URL')]],
    'tags'        => [],
    'output_path' => storage_path('openapi.yaml'),
    'routes'      => [
        'enabled'    => true,
        'prefix'     => 'api',
        'middleware' => ['web'],
        'spec'       => ['enabled' => true, 'uri' => 'openapi.yaml'],
        'playground' => ['enabled' => env('APP_ENV') === 'local', 'uri' => 'docs'],
    ],

    // Optional — omit for single-spec mode.
    'specs' => [

        // 'default' may be listed explicitly to add a match constraint on the
        // default spec or to override one of its root-key values. All keys you
        // don't list fall back to the root values above.
        // 'default' => ['match' => ['prefix' => 'api/v2/*']],

        'v1' => [
            // deep-merged over root info; unset keys are inherited
            'info'  => ['version' => '1.x'],

            // match: AND across keys, OR within a key's list
            'match' => ['prefix' => 'api/v1/*'],

            // Defaults when absent (replace 'v1' with the spec name):
            // 'output_path'    => storage_path('openapi-v1.yaml'),
            // 'route_uri'      => 'openapi-v1.yaml',   // false/null → not mounted
            // 'playground_uri' => 'docs/v1',           // false/null → not mounted
        ],

        'partner' => [
            'info'    => ['title' => 'Partner API', 'version' => '2025-01'],
            'servers' => [['url' => 'https://partners.example.com']],  // replaces root
            'match'   => ['middleware' => 'auth:partner'],
        ],

        'internal' => [
            'match'          => ['namespace' => 'App\\Http\\Controllers\\Internal\\'],
            'route_uri'      => false,   // not accessible over HTTP
            'playground_uri' => false,
        ],
    ],
];
```

### Per-spec keys

| Key | Default | Notes |
|---|---|---|
| `info` | root `info` | Deep-merged; per-spec keys win. |
| `servers` | root `servers` | Replaced wholesale. Absent → inherit root. |
| `tags` | root `tags` | Replaced wholesale. Absent → inherit root. |
| `match.prefix` | — | `string\|string[]`. URI glob(s) via `fnmatch()`. OR within the list. |
| `match.middleware` | — | `string\|string[]`. Matches literally or by prefix before `:` (`'auth'` matches `'auth:api'`). OR within the list. |
| `match.namespace` | — | `string\|string[]`. Controller FQCN prefix(es). OR within the list. AND with the other match keys. |
| `output_path` | `storage_path("openapi-{name}.yaml")` | Absolute file path. |
| `route_uri` | `"openapi-{name}.yaml"` | URI served under the routes prefix. `false` or `null` to not mount. |
| `playground_uri` | `"docs/{name}"` | URI for the Scalar playground. `false` or `null` to not mount. |

A missing or empty `match` block matches every route — useful only for the `default` spec or for a deliberate catch-all.

The global `filters` key in `config/openapi.php` applies to every spec. There is no per-spec filter.

## The `#[Spec]` attribute

Use `#[Spec]` on a controller class or action method to pin the route to specific specs regardless of `match` config.

```php
use Radiergummi\OpenApi\Core\Attributes\Spec;

#[Spec('v1')]               // always in v1, never in v2 or others
class FlightController { … }

class BookingController {
    #[Spec(['v1', 'v2'])]   // this action appears in both
    public function index() { … }
}
```

| Form | Effective specs |
|---|---|
| no `#[Spec]` | determined by `match` config |
| `#[Spec]` / `#[Spec(null)]` | `['default']` |
| `#[Spec('v1')]` | `['v1']` |
| `#[Spec(['v1', 'v2'])]` | `['v1', 'v2']` |
| `#[Spec('v1')]` on class, `#[Spec('v2')]` on method | method wins — `['v2']` |
| `#[Spec('v1'), Spec('v2')]` on the same target | union — `['v1', 'v2']` |

When `#[Spec]` is present on a method, all class-level `#[Spec]` attributes are ignored for that action. When only class-level attributes are present, they apply to every action that doesn't carry its own.

When `#[Spec]` is present, `match` is bypassed for that route. Global filters and `#[Hide]`/`#[Expose]` still apply.

## Generating

```bash
# Generate every spec
php artisan openapi:generate

# Generate one spec
php artisan openapi:generate v1

# Write to stdout
php artisan openapi:generate v1 --output=-

# Show per-route inclusion decisions on stderr while generating
php artisan openapi:generate --explain
```

With multiple specs configured, `--output=` is only accepted when a single spec is also named.

## Linting

```bash
# Lint every spec (default)
php artisan openapi:lint

# Lint one spec
php artisan openapi:lint --spec=v1
```

Pre-build rules — which check `#[Spec]` consistency against your config — always run even when `--spec` narrows the per-spec pass.

The three pre-build rules are:

| Rule ID | Level | Detects |
|---|---|---|
| `spec.unknown-reference` | 0 | `#[Spec('foo')]` names a spec that isn't in config. |
| `spec.route-orphaned` | 0 | A route's `#[Spec]` list resolves to zero valid specs. |
| `spec.config-orphaned` | 3 | A configured spec ends up with zero routes. |

## Debugging — `openapi:why`

`openapi:why` traces the four-rule decision for one route across every configured spec.

```bash
php artisan openapi:why flights.index
php artisan openapi:why api/flights
php artisan openapi:why api/flights --for-env=production
```

The `--for-env` flag overrides `app()->environment()` for the `#[Hide]`/`#[Expose]` check without changing `APP_ENV`.

Example output:

```
Route: GET api/v1/flights
  controller: App\Http\Controllers\V1\FlightController
  middleware: api, auth:api
  environment: production

default:
    ✗ spec-match (no match config) — match config did not match
    → match config did not match for default

v1:
    ✓ global-filter SkipNovaRoutes — shouldSkip = false
    ✓ spec-match prefix — match config matched
    ✓ visibility production — visible in environment
    → included in v1

Result: included in [v1]
```

## Worked examples

### Example 1: v1/v2 versioning by URL prefix

```php
// config/openapi.php

'specs' => [
    'v1' => [
        'info'  => ['title' => 'My API', 'version' => '1.x'],
        'match' => ['prefix' => 'api/v1/*'],
    ],
    'v2' => [
        'info'  => ['title' => 'My API', 'version' => '2.x'],
        'match' => ['prefix' => 'api/v2/*'],
    ],
],
```

Routes under `api/v1/*` appear only in `openapi-v1.yaml`; routes under `api/v2/*` appear only in `openapi-v2.yaml`. The implicit `default` spec matches both (no match constraint) — serve it if you want a combined view, or add `'default' => ['route_uri' => false, 'playground_uri' => false]` to suppress its HTTP endpoints.

### Example 2: public / partner / internal audience split by middleware

```php
// config/openapi.php

'specs' => [
    'public' => [
        'info'  => ['title' => 'Public API'],
        'match' => ['middleware' => 'auth:api'],
    ],

    'partner' => [
        'info'    => ['title' => 'Partner API'],
        'servers' => [['url' => 'https://partners.example.com']],
        'match'   => ['middleware' => 'auth:partner'],
    ],

    'internal' => [
        'info'           => ['title' => 'Internal API'],
        'match'          => ['middleware' => 'auth:internal'],
        'route_uri'      => false,   // file only — not served over HTTP
        'playground_uri' => false,
    ],
],
```

```php
// routes/api.php

Route::middleware(['api', 'auth:api'])->group(function () {
    Route::get('/flights', [FlightController::class, 'index']);
});

Route::middleware(['api', 'auth:partner'])->group(function () {
    Route::get('/partner/flights', [Partner\FlightController::class, 'index']);
});

Route::middleware(['api', 'auth:internal'])->group(function () {
    Route::get('/internal/metrics', [Internal\MetricsController::class, 'index']);
});
```

Each group appears in exactly one spec. `internal` is generated to `storage_path('openapi-internal.yaml')` but not mounted as an HTTP route.

### Example 3: domain split by namespace, shared endpoint pinned to multiple specs

```php
// config/openapi.php

'specs' => [
    'storefront' => [
        'info'  => ['title' => 'Storefront API'],
        'match' => ['namespace' => 'App\\Http\\Controllers\\Storefront\\'],
    ],

    'admin' => [
        'info'  => ['title' => 'Admin API'],
        'match' => ['namespace' => 'App\\Http\\Controllers\\Admin\\'],
    ],
],
```

```php
// App\Http\Controllers\Storefront\ProductController — in 'storefront' only via namespace match

namespace App\Http\Controllers\Storefront;

class ProductController
{
    public function index(): ProductCollection { … }
}
```

```php
// App\Http\Controllers\Shared\SearchController — pinned explicitly to both specs

namespace App\Http\Controllers\Shared;

use Radiergummi\OpenApi\Core\Attributes\Spec;

#[Spec(['storefront', 'admin'])]
class SearchController
{
    public function __invoke(SearchRequest $request): SearchResults { … }
}
```

`SearchController` sits outside both namespace prefixes, so the `match` config would not pick it up. `#[Spec(['storefront', 'admin'])]` pins it explicitly. The `match` config is bypassed for this class; global filters and visibility still apply.
