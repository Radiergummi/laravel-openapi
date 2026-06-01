<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Attributes\Spec;

/** Controller whose #[Spec] list resolves to no declared spec — route is orphaned. */
#[Spec('nowhere')]
final class SpecRouteOrphanedController
{
    public function handle(): void {}
}
