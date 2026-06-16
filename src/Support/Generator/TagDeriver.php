<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use Illuminate\Support\Str;
use Radiergummi\OpenApi\Routing\ActionDescriptor;

use function array_values;
use function explode;
use function preg_replace;

/**
 * Derives an OpenAPI tag from Laravel routing conventions.
 *
 * Sources in order: controller short name minus `Controller`, pluralised (`PostController` →
 * `Posts`); StudlyCased last route-prefix segment; `General`.
 *
 * @internal
 */
final readonly class TagDeriver
{
    private const string FALLBACK_TAG = 'General';

    public function derive(ActionDescriptor $descriptor): string
    {
        $fromController = $descriptor->controller !== null
            ? $this->fromControllerName($descriptor->controller->getShortName())
            : null;

        return $fromController
            ?? $this->fromRoutePrefix($descriptor->route->getPrefix())
            ?? self::FALLBACK_TAG;
    }

    private function fromControllerName(string $shortName): ?string
    {
        $base = preg_replace('/Controller$/', '', $shortName);

        if ($base === null || $base === '') {
            return null;
        }

        return Str::plural($base);
    }

    private function fromRoutePrefix(?string $prefix): ?string
    {
        if ($prefix === null || $prefix === '') {
            return null;
        }

        $segments = array_values(
            array_filter(explode('/', $prefix), static fn(string $segment): bool => $segment !== ''),
        );

        if ($segments === []) {
            return null;
        }

        return Str::studly($segments[count($segments) - 1]);
    }
}
