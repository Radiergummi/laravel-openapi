<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Attributes\Spec;

/** Controller whose class-level #[Spec] references an undeclared spec name. */
#[Spec('ghost')]
final class SpecUnknownRefController
{
    public function handle(): void {}
}
