<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Lint;

/**
 * A {@see Rule} that only runs under `openapi:lint --migrate`.
 *
 * Migration rules drive a one-off, mechanical clean-up of a codebase as it moves onto inference —
 * deciding which hand-authored annotations the generator now reproduces on its own. That decision
 * is intrinsically expensive (it runs the generator a second time to build an inference-only
 * control document), so these rules stay inert on ordinary lint runs and activate only when the
 * user is explicitly migrating.
 */
interface MigrationRule extends Rule {}
