<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Attributes\Response;
use Radiergummi\OpenApi\Attributes\ResponseResource;
use stdClass;

class ReturnTypeNudgeController
{
    public function untyped(string $value) {}

    public function mixedReturn(): mixed
    {
        return null;
    }

    public function voidReturn(): void {}

    public function typedArray(): array
    {
        return [];
    }

    #[Response(status: 200, description: 'OK')]
    public function withResponseAttribute() {}

    #[ResponseResource(class: stdClass::class)]
    public function withResponseResourceAttribute() {}
}
