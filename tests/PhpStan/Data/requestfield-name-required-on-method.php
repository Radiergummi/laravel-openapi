<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan\Data;

use Radiergummi\OpenApi\Attributes\RequestField;

final class RequestFieldNameRequiredOnMethodFixture
{
    // OK: name is provided by named argument
    #[RequestField(name: 'domain', type: 'string')]
    public function withNamedArg(): void {}

    // OK: name is provided as a positional first argument
    #[RequestField('domain', type: 'string')]
    public function withPositionalArg(): void {}

    // BAD: name is absent (null)
    #[RequestField(type: 'string')]
    public function withoutName(): void {}

    // BAD: name is an empty string — caught by the same check
    #[RequestField(name: '', type: 'string')]
    public function withEmptyName(): void {}

    // OK: name derived from the property
    #[RequestField(type: 'string')]
    public string $fromProperty;

    // OK: name derived from the promoted constructor parameter
    public function __construct(
        #[RequestField(type: 'string')]
        public string $fromParam = '',
    ) {}
}
