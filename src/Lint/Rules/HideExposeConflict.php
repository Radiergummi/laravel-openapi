<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Attributes\Expose;
use Radiergummi\OpenApi\Attributes\Hide;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Visitors\RouteRule;
use Radiergummi\OpenApi\Routing\ActionDescriptor;

use function array_filter;
use function sprintf;

/**
 * Reports routes that carry both #[Hide] and #[Expose] attributes whose env scopes overlap in the
 * current environment. Hide always wins on conflict; the attributes are still flagged so the
 * author can disambiguate.
 */
final class HideExposeConflict implements Rule, RouteRule
{
    public string $id = 'visibility.hide-expose-conflict';
    public Severity $severity = Severity::Degraded;
    public string $description = 'Route carries overlapping #[Hide] and #[Expose] in the current environment.';


    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkRoute(ActionDescriptor $descriptor, LintContext $context): iterable
    {
        $env = app()->environment();

        $hides = $descriptor->attributeInstances(Hide::class);
        $exposes = $descriptor->attributeInstances(Expose::class);

        if ($hides === [] || $exposes === []) {
            return;
        }

        $hideMatches = array_filter($hides, static fn(Hide $hide): bool => $hide->appliesIn($env));
        $exposeMatches = array_filter($exposes, static fn(Expose $expose): bool => $expose->appliesIn($env));

        if ($hideMatches === [] || $exposeMatches === []) {
            return;
        }

        yield new Finding(
            ruleId: $this->id,
            severity: $this->severity,
            message: sprintf(
                'Route carries both #[Hide] and #[Expose] attributes that apply in environment "%s". #[Hide] wins.',
                $env,
            ),
            fixHint: 'Remove either #[Hide] or #[Expose], or narrow their `only`/`except` lists so they do not overlap in this environment.',
        );
    }


}
