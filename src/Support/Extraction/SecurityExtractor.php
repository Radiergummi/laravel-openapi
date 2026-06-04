<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Laravel\Passport\Passport;
use OpenApi\Annotations as OA;

use function array_any;
use function array_filter;
use function array_key_first;
use function array_unique;
use function array_values;
use function class_exists;
use function config;
use function explode;
use function in_array;
use function is_array;
use function is_string;
use function str_starts_with;
use function substr;

/**
 * Builds the security schemes for the OpenAPI document and derives per-operation `security`
 * requirements from route middleware.
 *
 * Three sources contribute to the scheme catalogue:
 *
 * 1. The `openapi.security_schemes` config map. Each entry maps a scheme name to the OAS 3.1
 *    security-scheme shape; the array is passed through to {@see OA\SecurityScheme} unchanged.
 * 2. Passport's `oauth2` / `oauth2ClientCredentials` Authorization-Code and Client-Credentials
 *    flows, auto-derived when Laravel Passport is installed and its routes are registered.
 * 3. A Sanctum `sanctum` http/bearer scheme, auto-derived when any discovered route carries the
 *    `auth:sanctum` middleware token. Detection keys off the token, not `class_exists`, because
 *    Sanctum ships by default in fresh Laravel apps and is not always used for API auth.
 *
 * Config entries win on name collision. When no source yields a scheme the catalogue is
 * empty — the generator still emits a valid document; operations simply reference whatever
 * scheme names callers point at via `#[Security(scheme:)]`.
 *
 * Per-operation requirements (`forRoute()`) come from `auth:*` / `scope:*` / `scopes:*`
 * middleware. Routes with `auth:api` but no `scope:*` middleware emit an empty scope list —
 * meaning "a valid token is required but no specific scope is checked". This is distinct from
 * `security: []` (public). Routes with neither auth nor scope middleware emit `security: []`.
 * A route that *is* authed but for which no scheme can be derived (e.g. `auth:web` with Passport
 * absent and no config scheme) returns `null` so the caller omits `security` entirely — emitting
 * `[]` there would mislabel a protected route as public.
 *
 * swagger-php: `OA\Operation::$security` is a plain `array` of associative arrays
 * `['schemeName' => ['scope']]` — there is no `OA\SecurityRequirement` class.
 */
#[Scoped]
final class SecurityExtractor
{
    use DetectsAuthMiddleware;

    private const string SCHEME_AUTHORIZATION_CODE = 'oauth2';

    private const string SCHEME_CLIENT_CREDENTIALS = 'oauth2ClientCredentials';

    private const string SCHEME_SANCTUM = 'sanctum';

    private const int MAX_GROUP_EXPANSION_DEPTH = 10;

    /**
     * Lazily computed and reused across `forRoute()` / `requirementForScopes()` / `buildSchemes()`
     * calls within a single generation run. The extractor is bound as a scoped singleton, so the
     * cache is reset between requests under Octane. None of the inputs change during a run:
     * Passport's class presence is a static fact, the router's middleware groups are sealed by
     * the time generation starts, and the config catalogue is read once per process.
     */
    private ?bool $passportAvailable = null;

    /**
     * Whether any discovered route carries the `auth:sanctum` middleware token — the only
     * reliable signal that Sanctum is used for API auth. Detection is deliberately not
     * `class_exists(Sanctum::class)`: Sanctum ships by default in fresh Laravel apps, so class
     * presence would register an unreferenced scheme in the majority of apps that don't use it.
     * Lazily computed and cached for the run, like {@see $passportAvailable}.
     */
    private ?bool $sanctumInUse = null;

    /** @var ?array<string, array<int, string>> */
    private ?array $middlewareGroups = null;

    /** @var ?array<string, OA\SecurityScheme> */
    private ?array $configSchemesCache = null;

    /** @var ?array<string, string> */
    private ?array $middlewareSchemeMapCache = null;

    /** @var ?list<string> */
    private ?array $defaultSchemeNames = null;

    public function __construct(private readonly Router $router) {}

    /**
     * @return list<OA\SecurityScheme>
     */
    public function buildSchemes(): array
    {
        /** @var array<string, OA\SecurityScheme> $schemes */
        $schemes = [];

        foreach ($this->passportSchemes() as $scheme) {
            $schemes[$scheme->securityScheme] = $scheme;
        }

        foreach ($this->sanctumSchemes() as $scheme) {
            $schemes[$scheme->securityScheme] = $scheme;
        }

        foreach ($this->configSchemes() as $name => $scheme) {
            $schemes[$name] = $scheme;
        }

        return array_values($schemes);
    }

    /**
     * Build the two Passport-derived OAuth2 schemes. Returns an empty array if Passport is
     * not installed or its named routes are not registered.
     *
     * @return list<OA\SecurityScheme>
     */
    private function passportSchemes(): array
    {
        if (!$this->passportAvailable()) {
            return [];
        }

        $scopes = $this->allScopes();

        return [
            new OA\SecurityScheme([
                'securityScheme' => self::SCHEME_AUTHORIZATION_CODE,
                'type' => 'oauth2',
                'description' => 'OAuth 2.0 Authorization Code flow for interactive users.',
                'flows' => [
                    new OA\Flow([
                        'flow' => 'authorizationCode',
                        'authorizationUrl' => route('passport.authorizations.authorize'),
                        'tokenUrl' => route('passport.token'),
                        'refreshUrl' => route('passport.token.refresh'),
                        'scopes' => $scopes,
                    ]),
                ],
            ]),
            new OA\SecurityScheme([
                'securityScheme' => self::SCHEME_CLIENT_CREDENTIALS,
                'type' => 'oauth2',
                'description' => 'OAuth 2.0 Client Credentials flow for machine users (server-to-server).',
                'flows' => [
                    new OA\Flow([
                        'flow' => 'clientCredentials',
                        'tokenUrl' => route('passport.token'),
                        'scopes' => $scopes,
                    ]),
                ],
            ]),
        ];
    }

    private function passportAvailable(): bool
    {
        return $this->passportAvailable ??= (
            class_exists(Passport::class)
            && $this->router->has('passport.token')
            && $this->router->has('passport.token.refresh')
            && $this->router->has('passport.authorizations.authorize')
        );
    }

    /**
     * The Sanctum-derived bearer scheme, returned only when a route actually uses
     * `auth:sanctum`. Sanctum tokens are opaque `id|plaintext` strings, not JWTs, so no
     * `bearerFormat` is set.
     *
     * @return list<OA\SecurityScheme>
     */
    private function sanctumSchemes(): array
    {
        if (!$this->sanctumInUse()) {
            return [];
        }

        return [
            new OA\SecurityScheme([
                'securityScheme' => self::SCHEME_SANCTUM,
                'type' => 'http',
                'scheme' => 'bearer',
                'description' => 'Laravel Sanctum bearer token.',
            ]),
        ];
    }

    private function sanctumInUse(): bool
    {
        return $this->sanctumInUse ??= array_any(
            $this->router->getRoutes()->getRoutes(),
            fn(Route $route): bool => in_array('auth:sanctum', $this->expandedMiddlewareFor($route), true),
        );
    }

    /**
     * @return array<string, string>
     */
    private function allScopes(): array
    {
        $map = [];

        foreach (Passport::scopes() as $scope) {
            $map[$scope->id] = $scope->description;
        }

        return $map;
    }

    /**
     * Schemes registered via `openapi.security_schemes`. Each value is passed through to
     * {@see OA\SecurityScheme} verbatim; the map key becomes `securityScheme`.
     *
     * @return array<string, OA\SecurityScheme>
     */
    private function configSchemes(): array
    {
        if ($this->configSchemesCache !== null) {
            return $this->configSchemesCache;
        }

        /** @var mixed $raw */
        $raw = config('openapi.security_schemes', []);

        if (!is_array($raw)) {
            return $this->configSchemesCache = [];
        }

        $schemes = [];

        foreach ($raw as $name => $shape) {
            if (!is_string($name) || !is_array($shape)) {
                continue;
            }

            $schemes[$name] = new OA\SecurityScheme([
                'securityScheme' => $name,
                ...$shape,
            ]);
        }

        return $this->configSchemesCache = $schemes;
    }

    /**
     * @return null|list<array<string, list<string>>> `null` = authed route, no derivable scheme
     *                                                (caller omits `security`); see class docblock
     */
    public function forRoute(Route $route): ?array
    {
        $middleware = $this->expandedMiddlewareFor($route);
        $scopes = $this->extractScopes($middleware);
        $hasAuth = $this->hasAuthMiddleware($middleware);
        $mappedSchemes = $this->mappedSchemeNames($middleware);

        if ($scopes === [] && !$hasAuth && $mappedSchemes === []) {
            return [];
        }

        // An explicit `openapi.security_middleware_map` entry fully describes how this route
        // authenticates, so it takes precedence over the auto-derived default scheme(s) for this
        // route. Each mapped scheme carries the route's scopes.
        if ($mappedSchemes !== []) {
            $requirement = [];

            foreach ($mappedSchemes as $name) {
                $requirement[] = [$name => $scopes];
            }

            return $requirement;
        }

        $requirement = $this->requirementForScopes($scopes);

        // No derivable scheme for an authed/scoped route: return null to omit `security`
        // (not `[]`, which means public), so `operation.security-missing` can fire.
        return $requirement === [] ? null : $requirement;
    }

    /**
     * Gathers a route's middleware and expands any group names to their members.
     *
     * @return list<string>
     */
    private function expandedMiddlewareFor(Route $route): array
    {
        return $this->expandGroups(array_values($route->gatherMiddleware()), $this->middlewareGroups());
    }

    /**
     * Recursively expands middleware group names in $middleware against $groups.
     * Entries that are not group keys are left untouched. A depth cap guards against
     * cyclic group definitions.
     *
     * @param list<string>            $middleware
     * @param array<string, string[]> $groups
     *
     * @return list<string>
     */
    private function expandGroups(array $middleware, array $groups, int $depth = 0): array
    {
        if ($depth >= self::MAX_GROUP_EXPANSION_DEPTH) {
            return $middleware;
        }

        $result = [];

        foreach ($middleware as $entry) {
            // Routes may carry closure (or other non-string) middleware, which
            // can neither name a group nor map to a security scheme. Skip it
            // rather than crashing on the non-string array key.
            if (!is_string($entry)) {
                continue;
            }

            if (isset($groups[$entry])) {
                foreach ($this->expandGroups(array_values($groups[$entry]), $groups, $depth + 1) as $expanded) {
                    $result[] = $expanded;
                }
            } else {
                $result[] = $entry;
            }
        }

        return $result;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function middlewareGroups(): array
    {
        return $this->middlewareGroups ??= $this->router->getMiddlewareGroups();
    }

    /**
     * @param list<string> $middleware
     *
     * @return list<string>
     */
    private function extractScopes(array $middleware): array
    {
        $scopes = [];

        foreach ($middleware as $entry) {
            if (str_starts_with($entry, 'scope:')) {
                $scopes[] = substr($entry, 6);
            } elseif (str_starts_with($entry, 'scopes:')) {
                foreach (explode(',', substr($entry, 7)) as $s) {
                    $scopes[] = $s;
                }
            } elseif (str_starts_with($entry, 'abilities:')) {
                // Sanctum's `abilities:` middleware — the Sanctum analogue of Passport scopes.
                foreach (explode(',', substr($entry, 10)) as $s) {
                    $scopes[] = $s;
                }
            } elseif (str_starts_with($entry, 'ability:')) {
                foreach (explode(',', substr($entry, 8)) as $s) {
                    $scopes[] = $s;
                }
            }
        }

        // Drop empty segments from argument-less or trailing-comma tokens (e.g. `ability:`,
        // `scopes:read,`) so they never leak an empty-string scope into the requirement.
        return array_values(array_unique(array_filter($scopes, static fn(string $s): bool => $s !== '')));
    }

    /**
     * Scheme names mapped from a route's middleware via `openapi.security_middleware_map`.
     * Matches the full middleware token (including any `guard` parameter) against the map keys.
     *
     * @param list<string> $middleware
     *
     * @return list<string>
     */
    private function mappedSchemeNames(array $middleware): array
    {
        $map = $this->middlewareSchemeMap();

        if ($map === []) {
            return [];
        }

        $names = [];

        foreach ($middleware as $entry) {
            if (isset($map[$entry])) {
                $names[] = $map[$entry];
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * The `openapi.security_middleware_map` config: middleware name → an already-declared
     * scheme name. Non-string keys/values and empty scheme names are dropped.
     *
     * @return array<string, string>
     */
    private function middlewareSchemeMap(): array
    {
        if ($this->middlewareSchemeMapCache !== null) {
            return $this->middlewareSchemeMapCache;
        }

        /** @var mixed $raw */
        $raw = config('openapi.security_middleware_map', []);

        if (!is_array($raw)) {
            return $this->middlewareSchemeMapCache = [];
        }

        $map = [];

        foreach ($raw as $middleware => $scheme) {
            if (is_string($middleware) && is_string($scheme) && $scheme !== '') {
                $map[$middleware] = $scheme;
            }
        }

        return $this->middlewareSchemeMapCache = $map;
    }

    /**
     * Build the per-operation `security` block targeting a specific scheme (when given) or the
     * project default. Default resolution order, threaded through one single lookup:
     *
     * 1. Explicit `scheme:` argument — wins.
     * 2. `openapi.security_default_scheme` config (string or list of strings) — each entry becomes
     *    one OR-alternative.
     * 3. Passport's `oauth2` + `oauth2ClientCredentials` pair, if Passport is installed and its
     *    routes are registered.
     * 4. The first scheme declared in `openapi.security_schemes`.
     * 5. `[]` (empty requirement).
     *
     * @param list<string> $scopes
     *
     * @return list<array<string, list<string>>>
     */
    public function requirementForScopes(array $scopes, ?string $scheme = null): array
    {
        $names = $scheme !== null ? [$scheme] : $this->defaultSchemeNames();

        $requirement = [];

        foreach ($names as $name) {
            $requirement[] = [$name => $scopes];
        }

        return $requirement;
    }

    /**
     * @return list<string>
     */
    private function defaultSchemeNames(): array
    {
        if ($this->defaultSchemeNames !== null) {
            return $this->defaultSchemeNames;
        }

        $configured = $this->configuredDefaultSchemeNames();

        if ($configured !== []) {
            return $this->defaultSchemeNames = $configured;
        }

        $autoDerived = [];

        if ($this->passportAvailable()) {
            $autoDerived[] = self::SCHEME_AUTHORIZATION_CODE;
            $autoDerived[] = self::SCHEME_CLIENT_CREDENTIALS;
        }

        if ($this->sanctumInUse()) {
            $autoDerived[] = self::SCHEME_SANCTUM;
        }

        if ($autoDerived !== []) {
            return $this->defaultSchemeNames = $autoDerived;
        }

        $first = array_key_first($this->configSchemes());

        return $this->defaultSchemeNames = is_string($first) ? [$first] : [];
    }

    /**
     * @return list<string>
     */
    private function configuredDefaultSchemeNames(): array
    {
        /** @var mixed $raw */
        $raw = config('openapi.security_default_scheme');

        if (is_string($raw) && $raw !== '') {
            return [$raw];
        }

        if (!is_array($raw)) {
            return [];
        }

        $names = [];

        foreach ($raw as $value) {
            if (is_string($value) && $value !== '') {
                $names[] = $value;
            }
        }

        return $names;
    }
}
