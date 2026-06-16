<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Attributes\Expose;
use Radiergummi\OpenApi\Attributes\Hide;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Visitors\RouteRule;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Visibility\VisibilityMode;
use Radiergummi\OpenApi\Support\Visibility\VisibilityResolver;

/**
 * Reports unconditional #[Expose] in public-default visibility mode and
 * unconditional #[Hide] in hidden-default visibility mode: attributes that
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
        $mode = $this->visibility->defaultMode;

        if ($mode === VisibilityMode::Public) {
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

        foreach ($descriptor->actionAttributes($class) as $attribute) {
            $out[] = $attribute->newInstance();
        }

        foreach ($descriptor->controllerAttributes($class) as $attribute) {
            $out[] = $attribute->newInstance();
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
