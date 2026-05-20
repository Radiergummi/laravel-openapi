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
final readonly class SecurityExtractor
{
    use DetectsAuthMiddleware;

    private const string SCHEME_AUTHORIZATION_CODE = 'oauth2';

    private const string SCHEME_CLIENT_CREDENTIALS = 'oauth2ClientCredentials';

    private const int MAX_GROUP_EXPANSION_DEPTH = 10;

    public function __construct(private Router $router) {}

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
            $this->router->getMiddlewareGroups(),
        );
        $scopes = $this->extractScopes($middleware);
        $hasAuth = $this->hasAuthMiddleware($middleware);

        if ($scopes === [] && !$hasAuth) {
            return [];
        }

        return $this->requirementForScopes($scopes);
    }

    /**
     * Build the per-operation `security` block targeting a specific scheme (when given) or
     * the project default (Passport's pair if available, otherwise the first config-declared
     * scheme).
     *
     * @param list<string> $scopes
     *
     * @return list<array<string, list<string>>>
     */
    public function requirementForScopes(array $scopes, ?string $scheme = null): array
    {
        if ($scheme !== null) {
            return [[$scheme => $scopes]];
        }

        if ($this->passportAvailable()) {
            return [
                [self::SCHEME_AUTHORIZATION_CODE => $scopes],
                [self::SCHEME_CLIENT_CREDENTIALS => $scopes],
            ];
        }

        $defaultScheme = array_key_first($this->configSchemes());

        if ($defaultScheme !== null) {
            return [[$defaultScheme => $scopes]];
        }

        return [];
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
        /** @var mixed $raw */
        $raw = config('openapi.security_schemes', []);

        if (!is_array($raw)) {
            return [];
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

        return $schemes;
    }

    private function passportAvailable(): bool
    {
        return class_exists(Passport::class)
            && $this->router->has('passport.token')
            && $this->router->has('passport.token.refresh')
            && $this->router->has('passport.authorizations.authorize');
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
