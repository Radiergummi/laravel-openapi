<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan\Data;

use Radiergummi\OpenApi\Attributes\Link;

final class LinkOperationTargetFixture
{
    #[Link(name: 'ValidById', operationId: 'projects.show')]
    public function validById(): array
    {
        return [];
    }

    #[Link(name: 'ValidByRef', operationRef: '#/paths/~1projects/get')]
    public function validByRef(): array
    {
        return [];
    }

    #[Link(name: 'Neither')]
    public function neither(): array
    {
        return [];
    }

    #[Link(name: 'Both', operationId: 'projects.show', operationRef: '#/paths/~1projects/get')]
    public function both(): array
    {
        return [];
    }

    #[Link(name: 'ExplicitNull', operationId: null)]
    public function explicitNull(): array
    {
        return [];
    }
}
