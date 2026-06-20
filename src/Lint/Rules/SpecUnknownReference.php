<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Lint\Visitors\PreBuildRule;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Radiergummi\OpenApi\Support\Spec\SpecResolver;

/**
 * Reports `#[Spec(name:)]` arguments that do not match any spec in `config('openapi.specs')`.
 * Uses {@see SpecResolver} to apply the same method-shadows-class precedence as generation,
 * so overridden class-level attributes are not flagged.
 */
final class SpecUnknownReference implements Rule, PreBuildRule
{
    public string $id = self::ID;
    public Severity $severity = Severity::Broken;
    public string $description = "#[Spec(name:)] references a spec name not declared in config('openapi.specs').";

    public const string ID = 'spec.unknown-reference';

    public function __construct(private readonly SpecResolver $resolver) {}



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
                            severity: $this->severity,
                            message: "Spec name '{$name}' referenced by #[Spec] is not declared in config('openapi.specs').",
                            location: FindingLocation::fromDescriptor($descriptor),
                            fixHint: "Add '{$name}' to config('openapi.specs') or remove the attribute argument.",
                        ),
                    );
                }
            }
        }
    }

}
