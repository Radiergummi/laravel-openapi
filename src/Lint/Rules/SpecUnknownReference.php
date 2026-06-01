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

/**
 * Reports every #[Spec(name:)] argument that does not match any spec declared
 * in config('openapi.specs'). These references are silently dropped at generation
 * time; the rule makes them visible at lint time.
 *
 * Uses {@see SpecResolver} so the same method-shadows-class precedence the generator
 * applies at runtime is also applied here — a class-level #[Spec] that the method
 * overrides is not flagged, because it never reaches generation.
 */
final readonly class SpecUnknownReference implements Rule, PreBuildRule
{
    public const string ID = 'spec.unknown-reference';

    public function __construct(private SpecResolver $resolver) {}

    #[Override]
    public function id(): string
    {
        return self::ID;
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
            $names = $this->resolver->resolve($descriptor->controller, $descriptor->method) ?? [];

            foreach ($names as $name) {
                if (!$specs->has($name)) {
                    $findings->emit(
                        new Finding(
                            ruleId: self::ID,
                            level: $this->level(),
                            message: "Spec name '{$name}' referenced by #[Spec] is not declared in config('openapi.specs').",
                            location: FindingLocation::fromDescriptor($descriptor),
                            fixHint: "Add '{$name}' to config('openapi.specs') or remove the attribute argument.",
                        ),
                    );
                }
            }
        }
    }

    #[Override]
    public function level(): int
    {
        return 0;
    }
}
