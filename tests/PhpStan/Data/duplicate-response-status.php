<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan\Data;

use Radiergummi\OpenApi\Attributes\Response;

final class DuplicateResponseStatusFixture
{
    #[Response(status: 404, description: 'Not found')]
    #[Response(status: 422, description: 'Validation failed')]
    public function distinctStatuses(): void {}

    #[Response(status: 404, description: 'Not found')]
    #[Response(status: 404, description: 'Also not found')]
    public function duplicateStatus(): void {}

    #[Response(status: 500, description: 'A')]
    #[Response(status: 500, description: 'B')]
    #[Response(status: 500, description: 'C')]
    public function tripleDuplicate(): void {}

    #[Response(404, 'Positional A')]
    #[Response(404, 'Positional B')]
    public function positionalDuplicateStatus(): void {}

    #[Response(404, 'Positional')]
    #[Response(status: 404, description: 'Named')]
    public function mixedPositionalNamedDuplicate(): void {}
}
