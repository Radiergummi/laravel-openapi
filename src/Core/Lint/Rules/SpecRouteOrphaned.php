<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Attributes\Spec as SpecAttribute;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\FindingLocation;
use Radiergummi\OpenApi\Core\Lint\FindingsCollector;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\PreBuildRule;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Core\Spec\SpecRegistry;

use function array_intersect;
use function array_map;

/**
 * Reports routes whose #[Spec] list resolves to no declared spec — i.e. every
 * name they reference is unknown. Such routes are silently excluded from every
 * spec at generation time.
 */
final readonly class SpecRouteOrphaned implements Rule, PreBuildRule
{
    public const string ID = 'spec.route-orphaned';

    #[Override]
    public function id(): string
    {
        return self::ID;
    }

    #[Override]
    public function level(): int
    {
        return 0;
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
        $known = array_map(static fn($s) => $s->name, $specs->all());

        foreach ($descriptors as $descriptor) {
            $all = $this->collectSpecNames($descriptor);

            if ($all === []) {
                continue;
            }

            if (array_intersect($all, $known) === []) {
                $findings->emit(new Finding(
                    ruleId: self::ID,
                    level: $this->level(),
                    message: 'Route is pinned to specs that do not exist; it will not appear anywhere.',
                    location: FindingLocation::fromDescriptor($descriptor),
                    fixHint: 'Fix the #[Spec] argument(s) or declare the spec in config.',
                ));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function collectSpecNames(ActionDescriptor $descriptor): array
    {
        $names = [];

        foreach ($descriptor->actionAttributes(SpecAttribute::class) as $attr) {
            /** @var SpecAttribute $instance */
            $instance = $attr->newInstance();

            foreach ($instance->names as $name) {
                $names[] = $name;
            }
        }

        foreach ($descriptor->controllerAttributes(SpecAttribute::class) as $attr) {
            /** @var SpecAttribute $instance */
            $instance = $attr->newInstance();

            foreach ($instance->names as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
