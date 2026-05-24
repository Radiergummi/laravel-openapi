<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Attributes\Expose;
use Radiergummi\OpenApi\Core\Attributes\Hide;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\RouteRule;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Core\Visibility\VisibilityMode;
use Radiergummi\OpenApi\Core\Visibility\VisibilityResolver;

/**
 * Reports unconditional #[Expose] in public-default visibility mode and
 * unconditional #[Hide] in hidden-default visibility mode — attributes that
 * have no effect under the active default. Env-scoped variants are never
 * flagged because their effect can flip across environments.
 */
final readonly class VisibilityAttributeNoOp implements Rule, RouteRule
{
    public function __construct(private VisibilityResolver $visibility) {}

    #[Override]
    public function description(): string
    {
        return 'Unconditional visibility attribute that has no effect under the active default.';
    }

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkRoute(ActionDescriptor $descriptor, LintContext $context): iterable
    {
        $mode = $this->visibility->defaultMode();

        if ($mode === VisibilityMode::Public) {
            // Public default: an unconditional #[Expose] does nothing if no #[Hide]
            // is around to override.
            if ($this->collectInstances($descriptor, Hide::class) !== []) {
                return;
            }

            foreach ($this->collectInstances($descriptor, Expose::class) as $expose) {
                if ($expose->only === null && $expose->except === null) {
                    yield new Finding(
                        ruleId: $this->id(),
                        level: $this->level(),
                        message: '#[Expose] has no effect in public-default visibility mode.',
                        fixHint: "Remove the attribute, or set `config('openapi.visibility.default') = 'hidden'`.",
                    );

                    return;
                }
            }

            return;
        }

        // Hidden default: an unconditional #[Hide] does nothing if no #[Expose]
        // is around to neutralize.
        if ($this->collectInstances($descriptor, Expose::class) !== []) {
            return;
        }

        foreach ($this->collectInstances($descriptor, Hide::class) as $hide) {
            if ($hide->only === null && $hide->except === null) {
                yield new Finding(
                    ruleId: $this->id(),
                    level: $this->level(),
                    message: '#[Hide] has no effect in hidden-default visibility mode (routes are already hidden by default).',
                    fixHint: "Remove the attribute, or set `config('openapi.visibility.default') = 'public'`.",
                );

                return;
            }
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function collectInstances(ActionDescriptor $descriptor, string $class): array
    {
        $out = [];

        foreach ($descriptor->actionAttributes($class) as $attr) {
            $out[] = $attr->newInstance();
        }

        foreach ($descriptor->controllerAttributes($class) as $attr) {
            $out[] = $attr->newInstance();
        }

        return $out;
    }

    #[Override]
    public function id(): string
    {
        return 'visibility.attribute-no-op';
    }

    #[Override]
    public function level(): int
    {
        return 2;
    }
}
