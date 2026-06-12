<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Output;

/**
 * The destination kind for a lint output target: the two console streams, or a file on disk.
 *
 * @internal
 */
enum OutputChannel: string
{
    case Stdout = 'stdout';
    case Stderr = 'stderr';
    case File = 'file';
}
