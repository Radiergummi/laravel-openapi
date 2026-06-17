# Multi-spec

Produce several independent OpenAPI documents from one Laravel application.

A **spec** is one generated OpenAPI document. Without a `'specs'` key in your
config, there is one spec (the **default**), and the package behaves as in
single-spec mode.

Add a `'specs'` map to produce additional named documents alongside the
default. Each named spec gets its own generated file, HTTP endpoint, and
Scalar playground. Routes are distributed across specs by config-driven
matching or by `#[Spec]` attributes.

Root config keys (`info`, `servers`, `tags`, `output_path`) define the
default spec. Named specs inherit these and may override any of them.

## When to use it

- **API versioning**: `v1` and `v2` documents served at separate URLs while
  routes live in the same app.
- **Audience splits**: `public` and `internal` documents gated by different
  middleware; the internal spec is served as a file only.
- **Domain splits**: `storefront` and `admin` partitioned by controller
  namespace.

## Inclusion rule

A route is included in a spec when all four conditions hold:

| Step | Rule |
|---|---|
| 1. Global filter | No `RouteFilter` in `config('openapi.filters')` returns `shouldSkip() = true`. |
| 2. Spec membership | Either the route has no `#[Spec]` and the spec's `match` config matches, or the route has `#[Spec]` and the spec name is in its list. |
| 3. Not hidden | The route is not `#[Hide]`-d for the current environment. |
| 4. Visible | `visibility.default = 'public'`, or the route carries `#[Expose]` for the current environment. |

The `default` spec has no `match` config and matches every route. Named specs
have independent `match` configs; a route may satisfy several and appear in
multiple specs.

## Config reference

The `'specs'` map sits at the top level of `config/openapi.php`. Each key
names a spec.

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

    // Optional. Omit for single-spec mode.
    'specs' => [

        // List 'default' explicitly to add a match constraint on it, or to
        // override one of its root-key values. Unlisted keys fall back to
        // the root values above.
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

A missing or empty `match` block matches every route. Useful only for the
`default` spec or as a deliberate catch-all.

The global `filters` key applies to every spec. There is no per-spec filter.

## The `#[Spec]` attribute

Pin a route to specific specs regardless of `match` config:

```php
use Radiergummi\OpenApi\Attributes\Spec;

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
| `#[Spec('v1')]` on class, `#[Spec('v2')]` on method | method wins: `['v2']` |
| `#[Spec('v1'), Spec('v2')]` on the same target | union: `['v1', 'v2']` |

Method-level `#[Spec]` overrides class-level. When only the class declares
`#[Spec]`, it applies to every action that doesn't carry its own.

`#[Spec]` bypasses `match` for the route. Global filters and `#[Hide]` /
`#[Expose]` still apply.

## Generating

```bash
php artisan openapi:generate              # every spec
php artisan openapi:generate v1           # one spec
php artisan openapi:generate v1 --output=-   # stdout
php artisan openapi:generate --explain    # per-route inclusion decisions on stderr
```

`--output=` requires a single spec name when multiple specs are configured.

## Linting

```bash
php artisan openapi:lint              # every spec
php artisan openapi:lint --spec=v1    # one spec
```

Pre-build rules check `#[Spec]` consistency against your config and always
run, even when `--spec` narrows the per-spec pass:

| Rule ID | Level | Detects |
|---|---|---|
| `spec.unknown-reference` | 0 | `#[Spec('foo')]` names a spec that isn't in config. |
| `spec.route-orphaned` | 0 | A route's `#[Spec]` list resolves to zero valid specs. |
| `spec.config-orphaned` | 3 | A configured spec ends up with zero routes. |

## Debugging with `openapi:why`

Traces the four-rule decision for one route across every configured spec:

```bash
php artisan openapi:why flights.index
php artisan openapi:why api/flights
php artisan openapi:why api/flights --for-env=production
```

`--for-env` overrides `app()->environment()` for the `#[Hide]` / `#[Expose]`
check without changing `APP_ENV`.

Example output:

```
Route: GET api/v1/flights
  controller: App\Http\Controllers\V1\FlightController
  middleware: api, auth:api
  environment: production

default:
    ✗ spec-match (no match config)—match config did not match
    → match config did not match for default

v1:
    ✓ global-filter SkipNovaRoutes—shouldSkip = false
    ✓ spec-match prefix—match config matched
    ✓ visibility production—visible in environment
    → included in v1

Result: included in [v1]
```

### Explaining derived fields with `--fields`

`openapi:why` answers *why a route is in a spec*. Pass `--fields` to also build the operation
and answer the other "why": *why does this field have this value?* It prints the source and
reason behind each derived `summary`, success `status`, and `tags`, mirroring the inclusion
trace one layer in:

```bash
php artisan openapi:why flights.store --fields
```

```
Fields:
    summary: Create Flight ← ResourceConventionResolver (store → POST)
    status:  201           ← ResourceConventionResolver (store → POST)
    tags:    Flights       ← controller-derived (controller short name)
```

When an authoring attribute beats a convention, the winner names its source and the superseded
convention is listed beneath it:

```
Fields:
    summary: Find one flight ← #[Summary] (method) (author override)
             (superseded: convention 'Show Flight')
    ...
```

This is read-only instrumentation: it records decisions the generator already makes and never
changes the generated document. Provenance currently covers `summary`, success status, and
`tags`; response schemas, parameters, and security are not yet traced.

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

Routes under `api/v1/*` appear only in `openapi-v1.yaml`; routes under
`api/v2/*` appear only in `openapi-v2.yaml`. The implicit `default` spec
matches both. Serve it for a combined view, or set
`'default' => ['route_uri' => false, 'playground_uri' => false]` to suppress
its HTTP endpoints.

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
        'route_uri'      => false,   // file only, not served over HTTP
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

Each group appears in exactly one spec. `internal` is written to
`storage_path('openapi-internal.yaml')` but never mounted as an HTTP route.

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
// App\Http\Controllers\Storefront\ProductController: in 'storefront' only via namespace match

namespace App\Http\Controllers\Storefront;

class ProductController
{
    public function index(): ProductCollection { … }
}
```

```php
// App\Http\Controllers\Shared\SearchController: pinned explicitly to both specs

namespace App\Http\Controllers\Shared;

use Radiergummi\OpenApi\Attributes\Spec;

#[Spec(['storefront', 'admin'])]
class SearchController
{
    public function __invoke(SearchRequest $request): SearchResults { … }
}
```

`SearchController` sits outside both namespace prefixes, so `match` config
wouldn't pick it up. `#[Spec(['storefront', 'admin'])]` pins it explicitly.
`match` is bypassed for this class; global filters and visibility still
apply.
