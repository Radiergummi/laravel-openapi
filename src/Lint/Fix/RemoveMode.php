<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

/**
 * How {@see RemoveAttributeFixer} decides which matching attributes to delete.
 */
enum RemoveMode
{
    /** Keep the first matching attribute and delete every later duplicate. */
    case Dedupe;

    /** Delete every matching attribute on the member. */
    case RemoveAll;
}
