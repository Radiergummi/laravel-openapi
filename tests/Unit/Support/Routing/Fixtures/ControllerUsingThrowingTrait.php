<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Routing\Fixtures;

use Radiergummi\OpenApi\Tests\Unit\Support\Routing\Fixtures\ThrowingTrait\ThrowingTrait;

final class ControllerUsingThrowingTrait
{
    use ThrowingTrait;
}
