<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Illuminate\Container\Attributes\Config;
use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Lint\Visitors\PreBuildRule;
use Radiergummi\OpenApi\Support\Generator\OverrideMatcher;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;

use function implode;
use function is_array;

/**
 * Flags `openapi.overrides` field keys that are not in the allowlist
 * ({@see OverrideMatcher::ALLOWED_FIELDS} plus any `x-*` extension). Such fields are silently
 * skipped at apply time, so without this rule a typo'd field key would be a silent no-op.
 */
final readonly class OverridesUnknownField implements PreBuildRule, Rule
{
    public const string ID = 'overrides.unknown-field';

    /**
     * @param array<string, array<string, mixed>> $overrides
     */
    public function __construct(
        #[Config('openapi.overrides')]
        private array $overrides = [],
    ) {}

    #[Override]
    public function id(): string
    {
        return self::ID;
    }

    #[Override]
    public function description(): string
    {
        return 'Override block sets a field outside the allowlist '
            . '(operationId, summary, description, tags, deprecated, x-*).';
    }

    #[Override]
    public function checkConfiguration(
        SpecRegistry $specs,
        array $descriptors,
        FindingsCollector $findings,
    ): void {
        foreach ($this->overrides as $key => $block) {
            if (!is_array($block)) {
                continue;
            }

            foreach ($block as $field => $_value) {
                if (OverrideMatcher::isAllowedField((string) $field)) {
                    continue;
                }

                $findings->emit(
                    new Finding(
                        ruleId: self::ID,
                        level: $this->level(),
                        message: "Override '{$key}' sets unknown field '{$field}'.",
                        fixHint: 'Allowed: ' . implode(', ', OverrideMatcher::ALLOWED_FIELDS) . ', x-*',
                    ),
                );
            }
        }
    }

    #[Override]
    public function level(): int
    {
        return 3;
    }
}
