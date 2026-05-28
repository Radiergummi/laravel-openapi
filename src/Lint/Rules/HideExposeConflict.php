<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

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

use function array_filter;
use function in_array;
use function sprintf;

/**
 * Reports routes that carry both #[Hide] and #[Expose] attributes whose env scopes overlap in the
 * current environment. Hide always wins on conflict; the attributes are still flagged so the
 * author can disambiguate.
 */
final class HideExposeConflict implements Rule, RouteRule
{
    #[Override]
    public function description(): string
    {
        return 'Route carries overlapping #[Hide] and #[Expose] in the current environment.';
    }

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkRoute(ActionDescriptor $descriptor, LintContext $context): iterable
    {
        $env = app()->environment();

        $hides = $this->collectInstances($descriptor, Hide::class);
        $exposes = $this->collectInstances($descriptor, Expose::class);

        if ($hides === [] || $exposes === []) {
            return;
        }

        $hideMatches = array_filter(
            $hides,
            fn(Hide $hide): bool => $this->scopeMatches($hide->only, $hide->except, $env),
        );
        $exposeMatches = array_filter(
            $exposes,
            fn(Expose $expose): bool => $this->scopeMatches($expose->only, $expose->except, $env),
        );

        if ($hideMatches === [] || $exposeMatches === []) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'Route carries both #[Hide] and #[Expose] attributes that apply in environment "%s". #[Hide] wins.',
                $env,
            ),
            fixHint: 'Remove either #[Hide] or #[Expose], or narrow their `only`/`except` lists so they do not overlap in this environment.',
        );
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

    /**
     * @param null|list<string> $only
     * @param null|list<string> $except
     */
    private function scopeMatches(?array $only, ?array $except, string $env): bool
    {
        if ($only === null && $except === null) {
            return true;
        }

        if ($only !== null) {
            return in_array($env, $only, true);
        }

        return !in_array($env, $except ?? [], true);
    }

    #[Override]
    public function id(): string
    {
        return 'visibility.hide-expose-conflict';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }
}
