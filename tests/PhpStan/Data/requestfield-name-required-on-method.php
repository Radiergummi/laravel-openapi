<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan\Data;

use Radiergummi\OpenApi\Attributes\RequestField;

final class RequestFieldNameRequiredOnMethodFixture
{
    // OK: name is provided by named argument
    #[RequestField(type: 'string')]
    public string $fromProperty;

    // OK: name is provided as a positional first argument

    public function __construct(
        #[RequestField(type: 'string')]
        public string $fromParam = '',
    ) {}

    // BAD: name is absent (null)

    #[RequestField(name: 'domain', type: 'string')]
    public function withNamedArg(): void {}

    // BAD: name is an empty string — caught by the same check

    #[RequestField('domain', type: 'string')]
    public function withPositionalArg(): void {}

    // OK: name derived from the property

    #[RequestField(type: 'string')]
    public function withoutName(): void {}

    // OK: name derived from the promoted constructor parameter

    #[RequestField(name: '', type: 'string')]
    public function withEmptyName(): void {}
}
