<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Routing;

/**
 * A parsed documentation comment.
 */
final class DocComment
{
    public bool $empty {
        get => $this->summary === null && $this->description === null;
    }

    public function __construct(
        public readonly ?string $summary = null,
        public readonly ?string $description = null,
    ) {}
}
