<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Core\Attributes\Link;

class DuplicateLinkNameController
{
    #[Link(name: 'GetProject', operationId: 'projects.show')]
    #[Link(name: 'GetProject', operationId: 'projects.single')]
    public function withDuplicateLinks(): void {}

    #[Link(name: 'GetProject', operationId: 'projects.show')]
    #[Link(name: 'ListProjects', operationId: 'projects.index')]
    public function withUniqueLinks(): void {}
}
