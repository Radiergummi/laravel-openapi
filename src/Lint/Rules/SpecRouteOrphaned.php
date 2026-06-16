<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Lint\Visitors\PreBuildRule;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Radiergummi\OpenApi\Support\Spec\SpecResolver;

use function array_intersect;
use function array_map;

/**
 * Reports routes whose effective #[Spec] list resolves to no declared spec. Such routes are
 * silently excluded from every spec at generation time.
 *
 * Uses {@see SpecResolver} so the route's effective name set matches what the
 * generator sees (method-level #[Spec] shadows class-level).
 */
final readonly class SpecRouteOrphaned implements Rule, PreBuildRule
{
    public const string ID = 'spec.route-orphaned';

    public function __construct(private SpecResolver $resolver) {}

    #[Override]
    public function id(): string
    {
        return self::ID;
    }

    #[Override]
    public function description(): string
    {
        return 'Route is pinned via #[Spec] to specs that do not exist and will not appear in any document.';
    }

    #[Override]
    public function checkConfiguration(
        SpecRegistry $specs,
        array $descriptors,
        FindingsCollector $findings,
    ): void {
        $known = array_map(static fn(\Radiergummi\OpenApi\Support\Spec\SpecDefinition $s): string => $s->name, $specs->all());

        foreach ($descriptors as $descriptor) {
            $names = $this->resolver->resolve($descriptor->controller, $descriptor->method);

            if ($names === null || $names === []) {
                continue;
            }

            if (array_intersect($names, $known) === []) {
                $findings->emit(
                    new Finding(
                        ruleId: self::ID,
                        level: $this->level(),
                        message: 'Route is pinned to specs that do not exist; it will not appear anywhere.',
                        location: FindingLocation::fromDescriptor($descriptor),
                        fixHint: 'Fix the #[Spec] argument(s) or declare the spec in config.',
                    ),
                );
            }
        }
    }

    #[Override]
    public function level(): int
    {
        return 0;
    }
}
