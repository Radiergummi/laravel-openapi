<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use RuntimeException;

/**
 * Thrown when destructive fixes would write to files that are not in a clean, git-tracked state, so
 * the changes could not be trivially reverted. Pass `--allow-dirty` to override.
 *
 * @internal
 */
final class DirtyWorkingTreeException extends RuntimeException {}
