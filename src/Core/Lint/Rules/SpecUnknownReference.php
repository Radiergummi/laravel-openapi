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

/**
 * Reports every #[Spec(name:)] argument that does not match any spec declared
 * in config('openapi.specs'). These references are silently dropped at generation
 * time; the rule makes them visible at lint time.
 */
final readonly class SpecUnknownReference implements Rule, PreBuildRule
{
    public const string ID = 'spec.unknown-reference';

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
        return "#[Spec(name:)] references a spec name not declared in config('openapi.specs').";
    }

    #[Override]
    public function checkConfiguration(
        SpecRegistry $specs,
        array $descriptors,
        FindingsCollector $findings,
    ): void {
        foreach ($descriptors as $descriptor) {
            foreach ($this->collectSpecNames($descriptor) as $name) {
                if (!$specs->has($name)) {
                    $findings->emit(new Finding(
                        ruleId: self::ID,
                        level: $this->level(),
                        message: "Spec name '{$name}' referenced by #[Spec] is not declared in config('openapi.specs').",
                        location: FindingLocation::fromDescriptor($descriptor),
                        fixHint: "Add '{$name}' to config('openapi.specs') or remove the attribute argument.",
                    ));
                }
            }
        }
    }

    /**
     * @return iterable<string>
     */
    private function collectSpecNames(ActionDescriptor $descriptor): iterable
    {
        foreach ($descriptor->actionAttributes(SpecAttribute::class) as $attr) {
            /** @var SpecAttribute $instance */
            $instance = $attr->newInstance();

            foreach ($instance->names as $name) {
                yield $name;
            }
        }

        foreach ($descriptor->controllerAttributes(SpecAttribute::class) as $attr) {
            /** @var SpecAttribute $instance */
            $instance = $attr->newInstance();

            foreach ($instance->names as $name) {
                yield $name;
            }
        }
    }
}
