<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Attributes\Operation;

class StreamingFixtureController
{
    #[Operation(streaming: true)]
    public function stream(): void {}

    #[Operation(streaming: false)]
    public function nonStreaming(): void {}

    public function noAttribute(): void {}
}
