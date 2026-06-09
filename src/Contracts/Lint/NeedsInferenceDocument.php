<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Lint;

use Radiergummi\OpenApi\Contracts\Generator\SpecStage;

/**
 * Marks a lint {@see Rule} that decides findings by comparing the generated document against an
 * **inference-only** view of the same spec — the document the generator would produce with certain
 * stages excluded.
 *
 * The runner builds that view once per spec, at a safe boundary (between generating the document
 * under lint and walking it), and hands it to the rule through
 * {@see \Radiergummi\OpenApi\Lint\LintContext::$inference}. A rule must never drive
 * generation itself; declaring this capability is how it asks the runner to do it. The view is
 * built only when at least one active rule declares the need, so ordinary lint runs pay nothing.
 *
 * @internal Not a stable extension point; the inference-only view is an implementation detail of the
 *           bundled migration rules.
 */
interface NeedsInferenceDocument
{
    /**
     * The stages to exclude when building this rule's inference-only view. The runner unions the
     * exclusions across all active rules, so a true inference-only baseline drops every declared
     * harvesting stage at once.
     *
     * @return list<class-string<SpecStage>>
     */
    public function excludedStages(): array;
}
