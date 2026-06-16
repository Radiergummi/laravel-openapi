<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Laravel\Passport\Passport;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Support\Routing\RouteMiddlewareGatherer;

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
 * Builds security schemes (Passport, Sanctum, config) and derives per-operation `security`
 * requirements from route middleware. Config entries win on name collision.
 *
 * `forRoute()` returns `[]` (public), `null` (authenticated but no derivable scheme, so
 * `operation.security-missing` can fire), or a populated list. Sanctum detection keys off
 * `auth:sanctum` usage rather than `class_exists` to avoid registering an unused scheme.
 *
 * @internal
 */
#[Scoped]
final class SecurityExtractor
{
    use DetectsAuthMiddleware;

    private const string SCHEME_AUTHORIZATION_CODE = 'oauth2';

    private const string SCHEME_CLIENT_CREDENTIALS = 'oauth2ClientCredentials';

    private const string SCHEME_SANCTUM = 'sanctum';

    private const int MAX_GROUP_EXPANSION_DEPTH = 10;

    /** Lazily computed; reset between Octane requests via the scoped lifecycle. */
    private ?bool $passportAvailable = null;

    private ?bool $sanctumInUse = null;

    /** @var ?array<string, array<int, string>> */
    private ?array $middlewareGroups = null;

    /** @var ?array<string, OA\SecurityScheme> */
    private ?array $configSchemesCache = null;

    /** @var ?array<string, string> */
    private ?array $middlewareSchemeMapCache = null;

    /** @var ?list<string> */
    private ?array $defaultSchemeNames = null;

    public function __construct(
        private readonly Router $router,
        private readonly RouteMiddlewareGatherer $middlewareGatherer,
    ) {}

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
     * Sanctum tokens are opaque `id|plaintext` strings, not JWTs, so no `bearerFormat` is set.
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
            fn(Route $route): bool
                => in_array(
                    'auth:sanctum',
                    $this->expandedMiddlewareFor($route),
                    true,
                ),
        );
    }

    /**
     * @return list<string>
     */
    private function expandedMiddlewareFor(Route $route): array
    {
        return $this->expandGroups(
            array_values($this->middlewareGatherer->middlewareFor($route)),
            $this->middlewareGroups(),
        );
    }

    /**
     * Depth cap guards against cyclic group definitions.
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
            // Closure middleware cannot name a group or map to a scheme; skip it.
            if (!is_string($entry)) {
                continue;
            }

            if (isset($groups[$entry])) {
                foreach (
                    $this->expandGroups(
                        array_values($groups[$entry]),
                        $groups,
                        $depth + 1,
                    ) as $expanded
                ) {
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
     * @return null|list<array<string, list<string>>> null = authenticated but no derivable scheme
     */
    public function forRoute(Route $route): ?array
    {
        $middleware = $this->expandedMiddlewareFor($route);
        $allOfScopes = $this->extractScopes($middleware);
        $anyOfScopes = $this->extractAnyOfScopes($middleware);
        $hasAuth = $this->hasAuthMiddleware($middleware);
        $mappedSchemes = $this->mappedSchemeNames($middleware);

        $hasScopes = $allOfScopes !== [] || $anyOfScopes !== [];

        if (!$hasScopes && !$hasAuth && $mappedSchemes === []) {
            return [];
        }

        // Explicit middleware map takes precedence over auto-derived defaults.
        if ($mappedSchemes !== []) {
            return $this->buildRequirement(
                $mappedSchemes,
                $allOfScopes,
                $anyOfScopes,
            );
        }

        $requirement = $this->buildRequirement(
            $this->defaultSchemeNames(),
            $allOfScopes,
            $anyOfScopes,
        );

        // null omits `security` (distinct from `[]` = public) so operation.security-missing fires.
        return $requirement === [] ? null : $requirement;
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
                foreach (explode(',', substr($entry, 7)) as $scope) {
                    $scopes[] = $scope;
                }
            } elseif (str_starts_with($entry, 'abilities:')) {
                foreach (explode(',', substr($entry, 10)) as $scope) {
                    $scopes[] = $scope;
                }
            }
        }

        return $this->cleanScopes($scopes);
    }

    /**
     * @param list<string> $scopes
     *
     * @return list<string>
     */
    private function cleanScopes(array $scopes): array
    {
        return array_values(array_unique(array_filter($scopes, static fn(string $s): bool => $s !== '')));
    }

    /**
     * Any-of scopes from Sanctum `ability:` (each becomes an OR alternative).
     *
     * @param list<string> $middleware
     *
     * @return list<string>
     */
    private function extractAnyOfScopes(array $middleware): array
    {
        $scopes = [];

        foreach ($middleware as $entry) {
            if (str_starts_with($entry, 'ability:')) {
                foreach (explode(',', substr($entry, 8)) as $s) {
                    $scopes[] = $s;
                }
            }
        }

        return $this->cleanScopes($scopes);
    }

    /**
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

        $map = array_filter(
            $raw,
            static fn(mixed $scheme, mixed $middleware): bool
                => is_string($middleware) && is_string($scheme) && $scheme !== '',
            ARRAY_FILTER_USE_BOTH,
        );

        return $this->middlewareSchemeMapCache = $map;
    }

    /**
     * `$allOf` uses AND semantics; each `$anyOf` scope becomes its own OR-alternative.
     *
     * @param list<string> $schemeNames
     * @param list<string> $allOf
     * @param list<string> $anyOf
     *
     * @return list<array<string, list<string>>>
     */
    private function buildRequirement(array $schemeNames, array $allOf, array $anyOf): array
    {
        $requirement = [];

        foreach ($schemeNames as $name) {
            if ($anyOf === []) {
                $requirement[] = [$name => $allOf];

                continue;
            }

            foreach ($anyOf as $scope) {
                $requirement[] = [$name => array_values(array_unique([...$allOf, $scope]))];
            }
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

    /**
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
}
