<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan\Data;

use Radiergummi\OpenApi\Attributes\PublicEndpoint;
use Radiergummi\OpenApi\Attributes\Security;

final class PublicEndpointSecurityConflictFixture
{
    #[PublicEndpoint]
    public function publicOnly(): void {}

    #[Security(['admin'])]
    public function securedOnly(): void {}

    #[PublicEndpoint]
    #[Security(['admin'])]
    public function conflicting(): void {}
}
