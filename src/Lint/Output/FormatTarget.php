<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Output;

use Radiergummi\OpenApi\Lint\LinterOutputFormat;

/**
 * One resolved `--format` entry: a format paired with the destination it writes to.
 *
 * @internal
 */
final readonly class FormatTarget
{
    public function __construct(
        public LinterOutputFormat $format,
        public OutputTarget $target,
    ) {}
}
