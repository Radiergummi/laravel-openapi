<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Extractors;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Laravel\Passport\Passport;
use OpenApi\Annotations as OA;

use function array_key_first;
use function array_unique;
use function array_values;
use function class_exists;
use function config;
use function explode;
use function is_array;
use function is_string;
use function str_starts_with;
use function substr;

/**
 * Builds the security schemes for the OpenAPI document and derives per-operation `security`
 * requirements from route middleware.
 *
 * Two sources contribute to the scheme catalogue:
 *
 * 1. The `openapi.security_schemes` config map. Each entry maps a scheme name to the OAS 3.1
 *    security-scheme shape; the array is passed through to {@see OA\SecurityScheme} unchanged.
 * 2. Passport's `oauth2` / `oauth2ClientCredentials` Authorization-Code and Client-Credentials
 *    flows, auto-derived when Laravel Passport is installed and its routes are registered.
 *
 * Config entries win on name collision. When neither source yields a scheme the catalogue is
 * empty — the generator still emits a valid document; operations simply reference whatever
 * scheme names callers point at via `#[Security(scheme:)]`.
 *
 * Per-operation requirements (`forRoute()`) come from `auth:*` / `scope:*` / `scopes:*`
 * middleware. Routes with `auth:api` but no `scope:*` middleware emit an empty scope list —
 * meaning "a valid token is required but no specific scope is checked". This is distinct from
 * `security: []` (public). Routes with neither auth nor scope middleware emit `security: []`.
 *
 * swagger-php: `OA\Operation::$security` is a plain `array` of associative arrays
 * `['schemeName' => ['scope']]` — there is no `OA\SecurityRequirement` class.
 */
final class SecurityExtractor
{
    use DetectsAuthMiddleware;

    private const string SCHEME_AUTHORIZATION_CODE = 'oauth2';

    private const string SCHEME_CLIENT_CREDENTIALS = 'oauth2ClientCredentials';

    private const int MAX_GROUP_EXPANSION_DEPTH = 10;

    /**
     * Lazily computed and reused across `forRoute()` / `requirementForScopes()` / `buildSchemes()`
     * calls within a single generation run. The extractor is bound as a scoped singleton, so the
     * cache is reset between requests under Octane. None of the inputs change during a run:
     * Passport's class presence is a static fact, the router's middleware groups are sealed by
     * the time generation starts, and the config catalogue is read once per process.
     */
    private ?bool $passportAvailable = null;

    /** @var ?array<string, array<int, string>> */
    private ?array $middlewareGroups = null;

    /** @var ?array<string, OA\SecurityScheme> */
    private ?array $configSchemesCache = null;

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

        foreach ($this->configSchemes() as $name => $scheme) {
            $schemes[$name] = $scheme;
        }

        return array_values($schemes);
    }

    /**
     * @return list<array<string, list<string>>>
     */
    public function forRoute(Route $route): array
    {
        $middleware = $this->expandGroups(
            array_values($route->gatherMiddleware()),
            $this->middlewareGroups(),
        );
        $scopes = $this->extractScopes($middleware);
        $hasAuth = $this->hasAuthMiddleware($middleware);

        if ($scopes === [] && !$hasAuth) {
            return [];
        }

        return $this->requirementForScopes($scopes);
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

        if ($this->passportAvailable()) {
            return $this->defaultSchemeNames = [
                self::SCHEME_AUTHORIZATION_CODE,
                self::SCHEME_CLIENT_CREDENTIALS,
            ];
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
            }
        }

        return array_values(array_unique($scopes));
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
     * @return array<string, array<int, string>>
     */
    private function middlewareGroups(): array
    {
        return $this->middlewareGroups ??= $this->router->getMiddlewareGroups();
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
}
