<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

/**
 * How {@see RemoveAttributeFixer} decides which matching attributes to delete.
 */
enum RemoveMode
{
    /**
     * Keep the first attribute whose discriminator matches the finding's value and delete every
     * later duplicate (e.g. a repeated `#[Tag('users')]`).
     */
    case Dedupe;

    /**
     * Delete every matching attribute on the member (e.g. a `#[RequestField]` that has no effect).
     */
    case RemoveAll;
}
