<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Lint\Visitors\PreBuildRule;
use Radiergummi\OpenApi\Support\Generator\OverrideMatcher;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;

/**
 * Flags `openapi.overrides` keys that match no route name, path URI, or webhook name.
 * Delegates to {@see OverrideMatcher::unusedKeys()} to avoid duplicating match logic.
 */
final readonly class OverridesUnused implements PreBuildRule, Rule
{
    public const string ID = 'overrides.unused';

    public function __construct(private OverrideMatcher $matcher) {}

    #[Override]
    public function id(): string
    {
        return self::ID;
    }

    #[Override]
    public function description(): string
    {
        return 'Override key matches no route name, path URI, or webhook name.';
    }

    #[Override]
    public function checkConfiguration(
        SpecRegistry $specs,
        array $descriptors,
        FindingsCollector $findings,
    ): void {
        $routes = [];

        foreach ($descriptors as $descriptor) {
            $routes[] = [
                'name' => $descriptor->route->getName(),
                'uri' => $descriptor->route->uri(),
                // Webhook operations are matched by name, not URI; use the shared derivation.
                'webhook' => OverrideMatcher::webhookKeyFor($descriptor),
            ];
        }

        foreach ($this->matcher->unusedKeys($routes) as $key) {
            $findings->emit(
                new Finding(
                    ruleId: self::ID,
                    severity: $this->severity(),
                    message: "Override key '{$key}' matched no route name, path URI, or webhook name.",
                    fixHint: 'Fix the route name/glob, or remove the override entry.',
                ),
            );
        }
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Inconsistent;
    }
}
