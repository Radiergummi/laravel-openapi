<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Core\Routing\Fixtures;

#[Alpha('class-1')]
#[Alpha('class-2')]
#[Beta]
final class AttributedController
{
    #[Alpha('action-1')]
    public function action(): void {}

    public function bareAction(): void {}
}
