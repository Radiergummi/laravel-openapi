<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Spec;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Support\Inclusion\InclusionEvaluator;

/**
 * Immutable description of one OpenAPI specification produced by the generator.
 *
 * Built by {@see SpecRegistry} from `config('openapi.specs')` + root config keys. Consumed by
 * {@see OpenApiGenerator}, {@see InclusionEvaluator}, and the HTTP / CLI surfaces.
 *
 * `routeUri` / `playgroundUri` may be `null` to opt out of HTTP serving entirely (config sets
 * the entry to `false` or `null`).
 *
 * @internal
 */
final readonly class SpecDefinition
{
    /**
     * @param list<OA\Server>      $servers
     * @param list<OA\Tag>         $tags
     * @param array<string, mixed> $match   Raw match config (prefix/middleware/namespace).
     */
    public function __construct(
        public string $name,
        public OA\Info $info,
        public array $servers,
        public array $tags,
        public array $match,
        public string $outputPath,
        public ?string $routeUri,
        public ?string $playgroundUri,
    ) {}

    public function isDefault(): bool
    {
        return $this->name === 'default';
    }

    public function servesOverHttp(): bool
    {
        return $this->routeUri !== null;
    }

    public function specRouteName(): string
    {
        return self::specRouteNameFor($this->name);
    }

    /**
     * Route name for a spec's YAML endpoint. Static so the service provider can mount routes
     * during boot without resolving {@see SpecRegistry}.
     */
    public static function specRouteNameFor(string $specName): string
    {
        return $specName === 'default' ? 'openapi.spec' : 'openapi.spec.' . $specName;
    }

    public function playgroundRouteName(): string
    {
        return self::playgroundRouteNameFor($this->name);
    }

    public static function playgroundRouteNameFor(string $specName): string
    {
        return $specName === 'default' ? 'openapi.playground' : 'openapi.playground.' . $specName;
    }
}
