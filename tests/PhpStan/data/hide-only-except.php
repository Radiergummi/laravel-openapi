<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan\Data;

use Radiergummi\OpenApi\Attributes\Hide;

final class HideOnlyExceptFixture
{
    #[Hide]
    public function unconditional(): void {}

    #[Hide(only: ['production'])]
    public function onlyProduction(): void {}

    #[Hide(except: ['local'])]
    public function exceptLocal(): void {}

    #[Hide(only: ['production'], except: ['local'])]
    public function both(): void {}
}
