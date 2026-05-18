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

use function array_unique;
use function array_values;
use function explode;
use function str_starts_with;
use function substr;

/**
 * Builds the OAuth 2.0 security schemes for the OpenAPI document and derives per-operation
 * `security` requirements from route middleware.
 *
 * Two schemes are emitted (`oauth2` / `oauth2ClientCredentials`), both referencing the same
 * Passport scope catalogue. Every non-public route emits both as OR alternatives.
 *
 * Routes with `auth:api` but no `scope:*` middleware emit an empty scope list — meaning "a valid
 * token is required but no specific scope is checked". This is distinct from `security: []`
 * (public). Routes with neither auth nor scope middleware emit `security: []`.
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
     * @return list<array<string, list<string>>>
     */
    public function forRoute(Route $route): array
    {
        $middleware = $this->expandGroups(
            $route->gatherMiddleware(),
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
                foreach ($this->expandGroups($groups[$entry], $groups, $depth + 1) as $expanded) {
                    $result[] = $expanded;
                }
            } else {
                $result[] = $entry;
            }
        }

        return $result;
    }

    /**
     * @param list<string> $scopes
     *
     * @return list<array<string, list<string>>>
     */
    public function requirementForScopes(array $scopes): array
    {
        return [
            [self::SCHEME_AUTHORIZATION_CODE => $scopes],
            [self::SCHEME_CLIENT_CREDENTIALS => $scopes],
        ];
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
