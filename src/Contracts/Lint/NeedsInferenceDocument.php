<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Lint;

/**
 * Marks a lint {@see Rule} that needs an inference-only view of the spec: the schemas and operations
 * the generator would produce without hand-authored annotations, available via
 * {@see \Radiergummi\OpenApi\Lint\LintContext::$inference}. The runner retains that view off the
 * single primary generation, only when at least one active rule declares this interface.
 *
 * @internal Not a stable extension point; the inference-only view is an implementation detail of the
 *           bundled migration rules.
 */
interface NeedsInferenceDocument {}
