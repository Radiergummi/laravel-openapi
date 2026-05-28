<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan\Data;

use Radiergummi\OpenApi\Attributes\Example;
use Radiergummi\OpenApi\Attributes\ResponseExample;

final class ExampleValueOrFileFixture
{
    #[Example(name: 'inline', value: ['id' => 1])]
    public function validInline(): array
    {
        return [];
    }

    #[Example(name: 'fromFile', file: 'examples/widget.json')]
    public function validFromFile(): array
    {
        return [];
    }

    #[Example(name: 'neither')]
    public function neither(): array
    {
        return [];
    }

    #[Example(name: 'both', value: ['id' => 1], file: 'examples/widget.json')]
    public function both(): array
    {
        return [];
    }

    #[ResponseExample(status: 200, name: 'rxNeither')]
    public function rxNeither(): array
    {
        return [];
    }

    #[ResponseExample(status: 200, name: 'rxBoth', value: ['id' => 1], file: 'examples/widget.json')]
    public function rxBoth(): array
    {
        return [];
    }

    #[Example(name: 'explicitNull', value: null)]
    public function explicitNullValue(): array
    {
        return [];
    }
}
