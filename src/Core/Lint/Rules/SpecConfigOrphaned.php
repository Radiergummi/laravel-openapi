<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Illuminate\Container\Attributes\Config;
use Override;
use Radiergummi\OpenApi\Core\Inclusion\InclusionEvaluator;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\FindingsCollector;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\PreBuildRule;
use Radiergummi\OpenApi\Core\Spec\SpecRegistry;

/**
 * Reports specs declared in config('openapi.specs') that match no routes. An empty spec produces
 * an invalid or useless document and usually indicates a misconfigured match filter.
 */
final readonly class SpecConfigOrphaned implements PreBuildRule, Rule
{
    public const string ID = 'spec.config-orphaned';

    public function __construct(
        private InclusionEvaluator $evaluator,
        #[Config('app.env')]
        private string $environment,
    ) {}

    #[Override]
    public function id(): string
    {
        return self::ID;
    }

    #[Override]
    public function description(): string
    {
        return "Spec is defined in config('openapi.specs') but matches no routes.";
    }

    #[Override]
    public function checkConfiguration(
        SpecRegistry $specs,
        array $descriptors,
        FindingsCollector $findings,
    ): void {
        foreach ($specs->all() as $spec) {
            $hasMatch = false;

            foreach ($descriptors as $descriptor) {
                if ($this->evaluator->decide($descriptor, $spec, $this->environment)->included) {
                    $hasMatch = true;

                    break;
                }
            }

            if (!$hasMatch) {
                $findings->emit(
                    new Finding(
                        ruleId: self::ID,
                        level: $this->level(),
                        message: "Spec '{$spec->name}' is defined in config but matches no routes.",
                        fixHint: "Adjust the spec's match config or remove the spec entry.",
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
