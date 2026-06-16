<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use Attribute;

/**
 * Attaches a JSON file's contents as the example for a response: the terse "one file, one
 * response" convenience over a `file:`-based {@see ResponseExample}.
 *
 * Targets the auto-derived primary response by default; `$status` targets a specific declared
 * response. Repeatable. Skipped silently when the matching response has no content and the status
 * is conventionally bodyless (204/205/304), and when `$status` names no declared response.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class ResponseExampleFile
{
    /**
     * @param non-empty-string $file   Path to the JSON file, relative to the project root.
     * @param null|int         $status Target response status; null targets the primary response.
     */
    public function __construct(
        public string $file,
        public ?int $status = null,
    ) {}
}
