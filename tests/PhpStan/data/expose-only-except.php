<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan\Data;

use Radiergummi\OpenApi\Attributes\Expose;

final class ExposeOnlyExceptFixture
{
    #[Expose]
    public function unconditional(): void {}

    #[Expose(only: ['production'])]
    public function onlyProduction(): void {}

    #[Expose(except: ['local'])]
    public function exceptLocal(): void {}

    #[Expose(only: ['production'], except: ['local'])]
    public function both(): void {}
}
