<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Radiergummi\OpenApi\Core\Routing\Filters\SkipIgnitionRoutes;
use Radiergummi\OpenApi\Core\Routing\Filters\SkipNovaRoutes;
use Radiergummi\OpenApi\Core\Routing\Filters\SkipPassportRoutes;
use Radiergummi\OpenApi\Core\Routing\Filters\SkipTelescopeRoutes;
use Radiergummi\OpenApi\Plugins\ApiResources\ApiResourcesPlugin;
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
    | `@throws` annotations to OpenAPI response definitions. The generator
    | matches by short name first, then by FQCN, so importing the exception
    | in the controller is enough — no need to repeat the namespace here.
    |
    | Each entry: ['status' => int, 'description' => string].
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
    | Toggles for the responses derived from a route's middleware stack:
    | - `auth` → emits 401 when `auth:api` / `auth` middleware is present.
    | - `scope` → emits 403 when one or more `scope:*` / `scopes:*` middleware is present.
    | - `throttle` → emits 429 when any `throttle*` middleware is present.
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

        // null = every rule at or below the active level. A non-null list is an
        // explicit allowlist (still bounded by the active level).
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

        // Project style conventions consumed by convention rules.
        'style' => [
            // operationId casing: 'dot' | 'kebab' | 'snake' | 'camel' | 'pascal' | 'train' | 'screaming_snake'
            'operation_id_case' => 'dot',

            // Schema property wire-name casing: 'camel' | 'snake' | 'kebab' | 'pascal' | 'dot' | 'train' | 'screaming_snake'
            'property_name_case' => 'camel',

            // URL path segment casing: 'kebab' | 'snake'
            'path_segment_case' => 'kebab',

            // Parameter name casing (path + query): 'snake' | 'camel' | 'kebab' | 'dot' | 'pascal' | 'train' | 'screaming_snake'
            'parameter_name_case' => 'snake',

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
    | Routes
    |--------------------------------------------------------------------------
    |
    | The service provider mounts each route only when its toggle is enabled.
    | `spec` serves the generated document; `playground` serves the Scalar UI.
    |
    */

    'routes' => [
        'enabled' => true,
        'prefix' => 'api',
        'middleware' => ['web'],
        'spec' => ['enabled' => true, 'uri' => 'openapi.yaml'],
        'playground' => ['enabled' => env('APP_ENV') === 'local', 'uri' => 'docs'],
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
    | The four shipped filters exclude routes from common dev/admin/auth
    | packages (Nova, Telescope, Ignition, Passport). Each one tolerates its
    | package being absent: with no config present it simply matches nothing.
    |
    */

    'filters' => [
        SkipNovaRoutes::class,
        SkipTelescopeRoutes::class,
        SkipIgnitionRoutes::class,
        SkipPassportRoutes::class,
    ],

];
