<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Inclusion;

use Radiergummi\OpenApi\Core\Attributes\Expose;
use Radiergummi\OpenApi\Core\Attributes\Hide;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Core\Routing\Filters\RouteFilter;
use Radiergummi\OpenApi\Core\Spec\SpecDefinition;
use Radiergummi\OpenApi\Core\Spec\SpecMatcher;
use Radiergummi\OpenApi\Core\Spec\SpecResolver;
use Radiergummi\OpenApi\Core\Visibility\VisibilityResolver;

use function array_keys;
use function array_map;
use function array_values;
use function implode;
use function in_array;

/**
 * Single source of truth for the four-rule decision "does route R belong in spec X?".
 *
 * Used by the generator (reads `->included`), by `openapi:why` and `openapi:generate --explain`
 * (read `->trace`), and by the pre-build lint rules (resolve the same decision from the
 * descriptor list without building the spec).
 *
 * The four rules, evaluated in order:
 *
 * 1. Global filters (`config('openapi.filters')`): if any `RouteFilter::shouldSkip()` returns
 *    true, the route is excluded from every spec.
 * 2. Spec membership: if `#[Spec]` is present, the route is in spec X iff X is in the list;
 *    otherwise the spec's `match` config must match the route.
 * 3. Visibility: the route is excluded when `#[Hide]` applies in the current environment.
 * 4. Visibility default + `#[Expose]`: included when `visibility.default = 'public'` OR an
 *    `#[Expose]` resolves true for the current environment.
 */
final readonly class InclusionEvaluator
{
    /**
     * @param list<RouteFilter> $globalFilters
     */
    public function __construct(
        private array $globalFilters,
        private SpecMatcher $matcher,
        private SpecResolver $specResolver,
        private VisibilityResolver $visibility,
    ) {}

    /**
     * Fast spec-independent global-filter check.
     *
     * Used by callers that need to discard vendor routes (Telescope/Nova/Ignition/Passport
     * and any user-configured filter) before per-spec evaluation runs — the lint pipeline
     * iterates descriptors once for pre-build rules and the tree walk, regardless of which
     * specs they end up in, so it filters here rather than running {@see decide()} once per
     * (route × spec) just to drop the same routes from every spec.
     */
    public function passesGlobalFilters(ActionDescriptor $descriptor): bool
    {
        return !array_any(
            $this->globalFilters,
            static fn(RouteFilter $filter): bool => $filter->shouldSkip($descriptor->route),
        );
    }

    public function decide(
        ActionDescriptor $descriptor,
        SpecDefinition $spec,
        string $environment,
    ): InclusionDecision {
        $trace = [];

        // region 1. Global exclusion

        foreach ($this->globalFilters as $filter) {
            $skip = $filter->shouldSkip($descriptor->route);
            $name = $filter::class;
            $trace[] = new TraceEntry(
                stage: 'global-filter',
                name: $name,
                passed: !$skip,
                reason: $skip ? 'shouldSkip = true' : 'shouldSkip = false',
            );

            if ($skip) {
                return new InclusionDecision(
                    false,
                    $trace,
                    "excluded by global filter {$name}",
                    SkipReason::GlobalFilter,
                );
            }
        }

        // endregion

        // region 2. Spec membership

        $explicit = $this->specResolver->resolve($descriptor->controller, $descriptor->method);

        if ($explicit !== null) {
            $isMember = in_array($spec->name, $explicit, true);
            $trace[] = new TraceEntry(
                stage: 'spec-attribute',
                name: '#[Spec]',
                passed: $isMember,
                reason: $isMember
                    ? "attribute lists '{$spec->name}'"
                    : 'attribute lists [' . implode(',', $explicit) . "]; '{$spec->name}' not present",
            );

            if (!$isMember) {
                return new InclusionDecision(
                    false,
                    $trace,
                    "not in #[Spec] list for {$spec->name}",
                    SkipReason::SpecMembership,
                );
            }
        } else {
            // Empty match block: catch-all for the implicit default spec, no-match for any
            // named spec (the default spec exists to catch routes not pinned elsewhere by
            // #[Spec]; a named spec without `match` is a misconfiguration surfaced by
            // SpecConfigOrphaned).
            $matched = $spec->match === []
                ? $spec->name === 'default'
                : $this->matcher->matches(
                    uri: $descriptor->route->uri(),
                    middleware: array_values(
                        array_map(
                            static fn(mixed $entry): string => (string) $entry,
                            $descriptor->route->gatherMiddleware(),
                        ),
                    ),
                    controller: $descriptor->controller?->getName(),
                    match: $spec->match,
                );

            $reason = match (true) {
                $spec->match === [] && $spec->name === 'default'
                => 'default spec has no match config — catch-all',
                $spec->match === []
                => "named spec '{$spec->name}' has no match config — matches nothing",
                $matched
                => 'match config matched',
                default
                => 'match config did not match',
            };

            $trace[] = new TraceEntry(
                stage: 'spec-match',
                name: $spec->match === [] ? '(no match config)' : implode(',', array_keys($spec->match)),
                passed: $matched,
                reason: $reason,
            );

            if (!$matched) {
                return new InclusionDecision(false, $trace, $reason, SkipReason::SpecMembership);
            }
        }

        // endregion

        // region 3. + 4. Visibility (Hide / Expose / default)

        $visible = $this->visibility->isVisible(
            hides: $descriptor->attributeInstances(Hide::class),
            exposes: $descriptor->attributeInstances(Expose::class),
            environment: $environment,
        );

        $trace[] = new TraceEntry(
            stage: 'visibility',
            name: $environment,
            passed: $visible,
            reason: $visible ? 'visible in environment' : 'hidden in environment',
        );

        return new InclusionDecision(
            included: $visible,
            trace: $trace,
            summary: $visible ? "included in {$spec->name}" : "hidden in environment {$environment}",
            reason: $visible ? null : SkipReason::Visibility,
        );
        // endregion
    }
}
