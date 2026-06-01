<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Routing\Fixtures\ThrowingTrait;

trait ThrowingTrait
{
    /**
     * @throws TraitOnlyException
     */
    public function methodFromTrait(): void {}
}
