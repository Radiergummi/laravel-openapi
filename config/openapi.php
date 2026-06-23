<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Radiergummi\OpenApi\Plugins\ApiResources\ApiResourcesPlugin;
use Radiergummi\OpenApi\Plugins\Core\RouteFilters\SkipBroadcastingRoutes;
use Radiergummi\OpenApi\Plugins\Core\RouteFilters\SkipCashierRoutes;
use Radiergummi\OpenApi\Plugins\Core\RouteFilters\SkipHorizonRoutes;
use Radiergummi\OpenApi\Plugins\Core\RouteFilters\SkipIgnitionRoutes;
use Radiergummi\OpenApi\Plugins\Core\RouteFilters\SkipNovaRoutes;
use Radiergummi\OpenApi\Plugins\Core\RouteFilters\SkipPassportRoutes;
use Radiergummi\OpenApi\Plugins\Core\RouteFilters\SkipPulseRoutes;
use Radiergummi\OpenApi\Plugins\Core\RouteFilters\SkipSelfRoutes;
use Radiergummi\OpenApi\Plugins\Core\RouteFilters\SkipTelescopeRoutes;
use Radiergummi\OpenApi\Plugins\SpatieData\SpatieDataPlugin;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

return [

    /*
    |--------------------------------------------------------------------------
    | Document Info
    |--------------------------------------------------------------------------
    |
    | Populates the top-level `info` object of the generated OpenAPI document.
    | Any keys present here are passed through to swagger-php's OA\Info — see
    | https://spec.openapis.org/oas/v3.1.0#info-object for the full schema.
    |
    */

    'info' => [
        'title' => 'API',
        'version' => env('API_VERSION', '1.0.0'),
        'description' => 'HTTP API.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Servers
    |--------------------------------------------------------------------------
    |
    | Each entry becomes one OA\Server in the generated document. The default
    | uses the application's APP_URL so a single image can advertise its
    | environment without re-baking the spec.
    |
    */

    'servers' => [
        [
            'url' => env('APP_URL', 'http://localhost'),
            'description' => 'Current environment',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tag Catalogue
    |--------------------------------------------------------------------------
    |
    | Operations are tagged automatically based on their controller namespace.
    | Use this list to *describe* those tags at the document level so Scalar &
    | other UIs can render group descriptions and external links.
    |
    | The map is keyed by tag name; the value accepts `description` and
    | `externalDocs` (`url`, `description`). Operations may emit tags that
    | aren't listed here — those remain undocumented at the top level but are
    | still functional.
    |
    */

    'tags' => [
        // 'Projects'  => ['description' => 'Sourcing project management.'],
        // 'Companies' => ['description' => 'Supplier discovery and profiles.'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Exception → Response Map
    |--------------------------------------------------------------------------
    |
    | Maps exception class names (short OR fully-qualified) that appear in
    | `@throws` annotations to OpenAPI response definitions. Lookup order is
    | FQCN exact match first, short-name (basename) match second.
    |
    | Each entry: ['status' => int, 'description' => string].
    |
    | Resolution precedence for any `@throws` (highest wins):
    |   1. #[ExceptionResponse(...)] attribute on the exception class
    |   2. This map by FQCN
    |   3. This map by short name (basename)
    | If none match, the `throws.unmapped` lint rule fires.
    |
    | `middleware_responses` below is independent: it adds 401/403/429 from
    | the route's middleware stack only for status codes no @throws-derived
    | response already filled.
    |
    */

    'exception_responses' => [
        ModelNotFoundException::class => ['status' => 404, 'description' => 'Resource not found'],
        NotFoundHttpException::class => ['status' => 404, 'description' => 'Not found'],
        MultipleRecordsFoundException::class => ['status' => 500, 'description' => 'Multiple records matched'],
        AuthenticationException::class => ['status' => 401, 'description' => 'Unauthenticated'],
        AuthorizationException::class => ['status' => 403, 'description' => 'Forbidden'],
        AccessDeniedHttpException::class => ['status' => 403, 'description' => 'Forbidden'],
        ValidationException::class => ['status' => 422, 'description' => 'Validation failed'],
        ThrottleRequestsException::class => ['status' => 429, 'description' => 'Too many requests'],
        TooManyRequestsHttpException::class => ['status' => 429, 'description' => 'Too many requests'],
        MethodNotAllowedHttpException::class => ['status' => 405, 'description' => 'Method not allowed'],
        BadRequestHttpException::class => ['status' => 400, 'description' => 'Bad request'],
        ConflictHttpException::class => ['status' => 409, 'description' => 'Conflict'],
        UnprocessableEntityHttpException::class => ['status' => 422, 'description' => 'Unprocessable entity'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Standard Middleware Responses
    |--------------------------------------------------------------------------
    |
    | Keyed by Laravel middleware alias (not exception class — see
    | `exception_responses` above for that). These entries describe responses
    | derived from a route's middleware stack:
    | - `auth` → emits 401 when `auth:api` / `auth` middleware is present.
    | - `scope` → emits 403 when one or more `scope:*` / `scopes:*` middleware is present.
    | - `can` → emits 403 when a `can:*` authorization middleware is present.
    | - `throttle` → emits 429 when any `throttle*` middleware is present.
    |
    | A middleware-derived response is only added for status codes no
    | @throws-derived response already supplied — so an explicit
    | @throws AuthenticationException wins over the `auth` entry.
    |
    | Responses are also unique per status code: when two middleware kinds map
    | to the same status (e.g., `scope` and `can` both default to 403), a route
    | carrying both documents a single 403 — the first-detected kind's
    | description wins. Give them distinct statuses here if you need both.
    |
    */

    'middleware_responses' => [
        'auth' => [
            'status'      => 401,
            'description' => 'Unauthenticated',
            'exception'   => AuthenticationException::class,
        ],
        'scope' => [
            'status'      => 403,
            'description' => 'Insufficient scope',
            // No canonical scope exception ships with Laravel core; Passport's
            // MissingScopeException is the conventional choice when Passport is installed.
            // 'exception' => \Laravel\Passport\Exceptions\MissingScopeException::class,
        ],
        'can' => [
            'status'      => 403,
            'description' => 'Forbidden',
            'exception'   => AuthorizationException::class,
        ],
        'throttle' => [
            'status'      => 429,
            'description' => 'Too many requests',
            'exception'   => ThrottleRequestsException::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Schemes
    |--------------------------------------------------------------------------
    |
    | Each entry registers one OpenAPI security scheme. The map key is the
    | scheme name referenced by `#[Security(scheme: '...')]` (and by operation
    | `security:` blocks); the value is the OAS 3.1 security-scheme shape —
    | passed through to swagger-php's `OA\SecurityScheme` constructor unchanged.
    | See https://spec.openapis.org/oas/v3.1.0#security-scheme-object.
    |
    | These entries are MERGED with the Passport-derived `oauth2` and
    | `oauth2ClientCredentials` schemes when Laravel Passport is installed.
    | Config entries take precedence on name collision, so an `'oauth2' => […]`
    | entry here replaces the Passport-derived one.
    |
    */

    'security_schemes' => [
        // Example bearer-JWT scheme:
        //
        // 'bearer' => [
        //     'type'         => 'http',
        //     'scheme'       => 'bearer',
        //     'bearerFormat' => 'JWT',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Security Scheme
    |--------------------------------------------------------------------------
    |
    | Names the scheme that `#[Security(['scope'])]` (without an explicit
    | `scheme:` argument) and middleware-derived `forRoute()` should target.
    |
    | When null (the default) the resolution falls back to:
    |   1. The Passport-derived `oauth2` + `oauth2ClientCredentials` pair, if
    |      Passport is installed and its routes are registered. Both flows
    |      are emitted as OR alternatives — historic Passport behaviour.
    |   2. The first scheme declared in `security_schemes` above.
    |   3. An empty `security: []` requirement otherwise.
    |
    | Set this to a string (or list of strings) to override the default for
    | mixed-scheme projects — useful when Passport is installed but operations
    | should advertise a custom bearer/API-key scheme by default. Each name
    | becomes one OR-alternative in the requirement.
    |
    */

    'security_default_scheme' => null,

    /*
    |--------------------------------------------------------------------------
    | Custom Auth-Middleware → Scheme Map
    |--------------------------------------------------------------------------
    |
    | Maps a guard-middleware name to the name of a scheme declared in
    | `security_schemes` above. Routes carrying `auth:sanctum` / `auth:api` are
    | already understood; use this for project-specific guard middleware the
    | generator can't recognise on its own, so a protected endpoint doesn't look
    | public in the document.
    |
    | The match is on the full middleware token (including any `guard` argument).
    | A mapped entry fully describes the route's auth, so it takes precedence over
    | the auto-derived `auth:*` / `scope:*` scheme resolution for that route (any
    | `scope:*` / `abilities:*` scopes still attach to the mapped scheme). Routes
    | with no matching entry are unaffected. The scheme itself must be defined in
    | `security_schemes`.
    |
    |   'security_middleware_map' => [
    |       'auth:partner' => 'partner',
    |       'verify-api-key' => 'apiKey',
    |   ],
    |
    */

    'security_middleware_map' => [],

    /*
    |--------------------------------------------------------------------------
    | Visibility
    |--------------------------------------------------------------------------
    |
    | `default` controls which routes appear in the generated document when no
    | attribute is present.
    |
    | - 'public' (default): every discovered route is exposed unless a
    |   #[Hide] attribute applies in the current environment.
    | - 'hidden': every discovered route is hidden unless a #[Expose]
    |   attribute applies in the current environment.
    |
    | #[Hide] always wins on conflict. The `visibility.hide-expose-conflict`
    | lint rule reports overlapping attributes; `visibility.attribute-no-op`
    | reports attributes that have no effect under the current default.
    |
    */

    'visibility' => [
        'default' => 'public',
    ],

    /*
    |--------------------------------------------------------------------------
    | Named Specs (Multi-Spec)
    |--------------------------------------------------------------------------
    |
    | Define additional named OpenAPI specs alongside the implicit 'default' spec
    | (whose settings come from the root keys above). Each entry partitions routes
    | by URL prefix, middleware tokens, or controller namespace; #[Spec('name')]
    | on a route overrides match-based assignment.
    |
    | Defaults for named specs:
    |   - output_path    storage_path("openapi-{name}.yaml")
    |   - route_uri      "openapi-{name}.yaml"   (false / null to not mount)
    |   - playground_uri "docs/{name}"           (false / null to not mount)
    |
    | Omit this key entirely for single-spec mode. See docs/multi-spec.md.
    |
    */

    // 'specs' => [
    //     'v1' => [
    //         'info'  => ['version' => '1.x'],
    //         'match' => [
    //             'prefix' => 'api/v1/*',
    //             // 'middleware' => 'auth:partner',
    //             // 'namespace'  => 'App\\Http\\Controllers\\V1\\',
    //         ],
    //     ],
    // ],

    /*
    |--------------------------------------------------------------------------
    | Lint Configuration
    |--------------------------------------------------------------------------
    |
    | Controls which rules run during `openapi:lint` and at what severity.
    |
    */

    'lint' => [
        // Default severity level when --level is not passed.
        'level' => 1,

        // Rule allowlist.
        //   null  → run every registered rule whose level is ≤ the active level (the default).
        //   array → run only the listed rule IDs, still bounded by the active level.
        // Use `disabled_rules` below to subtract from the active set instead of replacing it.
        'enabled_rules' => null,

        // Always-off rules, regardless of level. `spec.invalid` cannot be disabled.
        'disabled_rules' => [
            // Noisy by design — opt in explicitly.
            'schema.constraints-missing',
        ],

        // Per-rule severity remap: rule-id => level. Applies to lint only.
        // Lets a project downgrade or upgrade any rule's reported severity and
        // inclusion threshold without editing the rule class or disabling it.
        // `spec.invalid` is exempt and cannot be remapped.
        'severity_overrides' => [],

        // Documentation-coverage gate. When either value is non-null, `openapi:lint` becomes
        // gate-driven: it exits non-zero only when coverage drops below `min_coverage` or the
        // in-scope finding count exceeds `max_findings` — findings alone no longer fail the
        // command. CLI flags (--min-coverage / --max-findings) override these. Null disables the
        // respective gate. Coverage is operation-level (route × verb) and binary.
        'min_coverage' => null,
        'max_findings' => null,

        // Project style conventions consumed by convention rules.
        'style' => [
            // operationId casing: 'dot' | 'kebab' | 'snake' | 'camel' | 'pascal' | 'train' | 'screaming_snake'
            'operation_id_case' => 'dot',

            // Schema property wire-name casing: 'camel' | 'snake' | 'kebab' | 'pascal' | 'dot' | 'train' | 'screaming_snake'
            'property_name_case' => 'camel',

            // URL path segment casing: 'kebab' | 'snake'
            'path_segment_case' => 'kebab',

            // Path parameter name casing (e.g., `{deviceId}`): 'camel' | 'snake' | 'kebab' | 'dot' | 'pascal' | 'train' | 'screaming_snake'
            'path_parameter_case' => 'camel',

            // Query parameter name casing (e.g., `per_page`): 'snake' | 'camel' | 'kebab' | 'dot' | 'pascal' | 'train' | 'screaming_snake'
            'query_parameter_case' => 'snake',

            // Tag name casing: 'pascal' | 'camel' | 'kebab' | 'snake' | 'dot' | 'train' | 'screaming_snake'
            'tag_case'    => 'pascal',

            // Response header name casing: 'train' | 'pascal' | 'camel' | 'kebab' | 'snake' | 'dot' | 'screaming_snake'
            'header_case' => 'train',

            // Component schema name casing: 'pascal' | 'camel' | 'snake' | 'kebab' | 'dot' | 'train' | 'screaming_snake'
            'component_name_case' => 'pascal',
        ],

        // Baseline file path; null disables the baseline feature.
        'baseline' => null,

        // Extra custom Rule classes, appended to the registry.
        'rules' => [
            // App\OpenApi\Rules\MyCustomRule::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Payload Indirection
    |--------------------------------------------------------------------------
    |
    | Base class-strings whose constructors are also scanned when searching for
    | a request-payload class on a controller method. The scanner walks one
    | level deep: if a method parameter is a subclass of any entry here, the
    | constructor of that class is reflected and its parameters are appended to
    | the candidate list (after any direct method-parameter candidates).
    |
    | Set to an empty array to disable indirection entirely.
    |
    */

    'request_payload_indirection' => [
        // App\Domain\Action::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Envelope
    |--------------------------------------------------------------------------
    |
    | Controls the shape of 4xx/5xx response bodies in the generated document.
    |
    | Built-in presets:
    |   'none'     — no body schema (status code + description only)
    |   'laravel'  — Laravel's default JSON error shape: { message: string }
    |   'rfc7807'  — RFC 7807 Problem Details: { type, title, status, detail, instance }
    |   'json-api' — JSON:API error envelope: { errors: [{ status, title, detail }] }
    |
    | Pass a fully-qualified class name implementing ErrorResponseResolver for a
    | custom envelope. Unknown preset names fail at boot with a clear message.
    |
    */

    'error_envelope' => 'none',

    /*
    |--------------------------------------------------------------------------
    | operationId Strategy
    |--------------------------------------------------------------------------
    |
    | Controls how each operation's `operationId` is derived. Whatever strategy
    | is selected, the result is always sanitised to the codegen-safe identifier
    | shape enforced by the `operation.id-invalid-chars` lint rule.
    |
    |   'route-name' (default) — use the route name (with a `.{method}` suffix
    |       for multi-verb routes); unnamed routes fall back to `{method}_{path}`.
    |   'method-path'          — always `{method}_{path}`, ignoring route names.
    |       Useful when route names aren't stable or meaningful as method names
    |       in a generated client.
    |
    | Unknown values fall back to 'route-name'.
    |
    */

    'operation_id_strategy' => 'route-name',

    /*
    |--------------------------------------------------------------------------
    | Operation Overrides
    |--------------------------------------------------------------------------
    |
    | A config-keyed escape hatch for setting operation-level fields of the
    | emitted document per route — without touching controller code. Keyed by
    | exact route name or URI glob ('*' matches any run of characters,
    | including '/'). Each value is a field-array.
    |
    | Allowed fields: operationId, summary, description, tags, deprecated, and
    | any 'x-*' vendor extension. Other keys are skipped and reported by the
    | 'overrides.unknown-field' lint rule. Keys matching no route are reported
    | by 'overrides.unused'.
    |
    | Overrides beat plugin contributions and convention-derived values; a
    | code-based transformDocument() callback still wins. Set-only — there is no
    | field-removal semantics.
    |
    |   'overrides' => [
    |       'users.show' => [
    |           'operationId' => 'getCurrentUser',
    |           'tags'        => ['Identity'],
    |           'deprecated'  => true,
    |       ],
    |       'api/v1/legacy/*' => [
    |           'x-internal' => true,
    |       ],
    |   ],
    |
    */

    'overrides' => [],

    /*
    |--------------------------------------------------------------------------
    | Plugins
    |--------------------------------------------------------------------------
    |
    | Plugin classes registered in declaration order.
    |
    */

    'plugins' => [
        // Requires `composer require spatie/laravel-data`. The plugin entry is harmless
        // without the package — `SpatieDataPlugin::register()` no-ops via a class_exists
        // guard so listing it here imposes no runtime dependency.
        SpatieDataPlugin::class,

        ApiResourcesPlugin::class,

        // Requires `composer require spatie/laravel-query-builder`. Uncomment to enable:
        // \Radiergummi\OpenApi\Plugins\QueryBuilder\QueryBuilderPlugin::class,

        // Requires either `composer require league/fractal` or
        // `composer require spatie/laravel-fractal` (which depends on league/fractal).
        // Uncomment to enable:
        // \Radiergummi\OpenApi\Plugins\Fractal\FractalPlugin::class,

        // Requires `composer require laravel/fortify`. Documents Fortify's headless core-auth
        // endpoints (login/register/password/profile) from a stock-contract table. Uncomment:
        // \Radiergummi\OpenApi\Plugins\Fortify\FortifyPlugin::class,

        // Harvests hand-authored swagger-php annotations (`#[OA\Schema]` / `@OA\Schema` and
        // operation-level `@OA`) into the generated document. Harvesting `@OA` PHPDoc annotations
        // additionally requires `composer require doctrine/annotations` (`#[OA\*]` attributes work
        // without it). Uncomment to enable:
        // \Radiergummi\OpenApi\Plugins\SwaggerPhp\SwaggerPhpPlugin::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Generated Document Path
    |--------------------------------------------------------------------------
    |
    | Absolute path the `openapi:generate` command writes to and the spec
    | route serves from.
    |
    */

    'output_path' => storage_path('openapi.yaml'),

    /*
    |--------------------------------------------------------------------------
    | Examples
    |--------------------------------------------------------------------------
    |
    | Controls auto-generated example values for request/response fields that
    | have no authored example (#[Example] attribute, example file, or inline
    | `Example:` directive in a description).
    |
    | - `synthesise`: master switch. When false, fields without an authored
    |   example emit none. Faker is never invoked.
    | - `faker_seed`: integer seed passed to Faker. Set to a fixed value so
    |   example output is deterministic across generation runs; set to null
    |   to use Faker's default (time-based) seed.
    |
    */

    'examples' => [
        'synthesise' => true,
        'faker_seed' => 1234,
    ],

    /*
    |--------------------------------------------------------------------------
    | Read migration columns
    |--------------------------------------------------------------------------
    |
    | When enabled, response schemas inferred from Eloquent models are enriched
    | with signals read statically from `database/migrations/*.php`: column
    | formats (uuid, ip, date, date-time), `maxLength`, unsigned `minimum`,
    | decimal `multipleOf`, enum members, nullability, defaults, and column
    | comments. These rank below `$casts`, `@property` tags, and authoring
    | attributes (they only fill fields those sources left undefined). Set to
    | false to skip migration parsing entirely.
    |
    */

    'read_migration_columns' => true,

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | The service provider mounts each route only when its toggle is enabled.
    | `spec` serves the generated document; `playground` serves the interactive
    | API reference. The playground `renderer` chooses the UI: `scalar` (default)
    | or `swagger-ui` — the latter for teams standardised on Swagger UI.
    |
    | The spec and playground routes mount under `prefix` (default `api`), so
    | they show up in `php artisan route:list --path=api` next to your own API
    | routes. That is cosmetic only — the `SkipSelfRoutes` filter already keeps
    | them out of the generated document. Set `prefix` to a dedicated segment
    | (e.g., `_openapi`) if you'd rather they not appear under your `api` listing.
    |
    */

    'routes' => [
        'enabled' => true,
        'prefix' => 'api', // also where the spec/playground mount; see note above
        'middleware' => ['web'],
        'spec' => ['enabled' => true, 'uri' => 'openapi.yaml'],
        'playground' => [
            'enabled' => env('APP_ENV') === 'local',
            'uri' => 'docs',
            'renderer' => 'scalar',
            // Explicit spec URL used by the playground renderer (the data-url / url attribute).
            // When null (the default), the URL is derived from the spec route automatically.
            // Set via env to point the playground at a CDN-hosted or proxy-corrected spec URL
            // when TrustProxies middleware cannot be used.
            'spec_url' => env('OPENAPI_PLAYGROUND_SPEC_URL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Filters
    |--------------------------------------------------------------------------
    |
    | Filters excluded from route discovery. Each entry is either an instance
    | or a container-resolvable class name implementing the RouteFilter
    | contract; returning true from `shouldSkip()` omits the route from the
    | generated document.
    |
    | The shipped filters exclude (1) the library's own spec/playground routes
    | and (2) routes from common dev/admin/auth/vendor packages (Nova,
    | Telescope, Ignition, Passport, Horizon, Pulse, Cashier, and the
    | broadcasting channel-auth endpoints). Each one tolerates its package being
    | absent: with no config present it simply matches nothing.
    |
    */

    'filters' => [
        // Default-on: the library's own spec/playground routes never appear in
        // a consumer's generated document. Remove this entry to document them.
        SkipSelfRoutes::class,
        SkipNovaRoutes::class,
        SkipTelescopeRoutes::class,
        SkipIgnitionRoutes::class,
        SkipPassportRoutes::class,
        SkipHorizonRoutes::class,
        SkipPulseRoutes::class,
        SkipCashierRoutes::class,
        SkipBroadcastingRoutes::class,
    ],

];
