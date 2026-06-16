<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Lint;

use Radiergummi\OpenApi\Contracts\Generator\SpecStage;

/**
 * Marks a lint {@see Rule} that needs an inference-only view of the spec: the document the
 * generator would produce with certain stages excluded, available via
 * {@see \Radiergummi\OpenApi\Lint\LintContext::$inference}. The runner builds it once per spec,
 * only when at least one active rule declares this interface.
 *
 * @internal Not a stable extension point; the inference-only view is an implementation detail of the
 *           bundled migration rules.
 */
interface NeedsInferenceDocument
{
    /**
     * Stages to exclude when building the inference-only view.
     * The runner unions exclusions across all active rules.
     *
     * @return list<class-string<SpecStage>>
     */
    public function excludedStages(): array;
}
