<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Spec;

use Radiergummi\OpenApi\Attributes\Spec;
use Radiergummi\OpenApi\Support\Spec\SpecResolver;
use ReflectionClass;
use ReflectionMethod;

beforeEach(function (): void {
    $this->resolver = new SpecResolver();
});

// Fixtures — declared at module level so reflection sees them.

#[Spec('v1')]
final class FxClassOnly
{
    public function handle(): void {}
}

final class FxMethodOnly
{
    #[Spec('v2')]
    public function handle(): void {}
}

#[Spec('v1')]
final class FxBoth
{
    #[Spec('v2')]
    public function handle(): void {}
}

final class FxRepeatable
{
    #[Spec('v1')]
    #[Spec('v2')]
    public function handle(): void {}
}

final class FxNone
{
    public function handle(): void {}
}

it('returns class-level names when only the class carries #[Spec]', function (): void {
    $method = new ReflectionMethod(FxClassOnly::class, 'handle');
    $class = new ReflectionClass(FxClassOnly::class);

    expect($this->resolver->resolve($class, $method))->toBe(['v1']);
});

it('returns method-level names when only the method carries #[Spec]', function (): void {
    $method = new ReflectionMethod(FxMethodOnly::class, 'handle');
    $class = new ReflectionClass(FxMethodOnly::class);

    expect($this->resolver->resolve($class, $method))->toBe(['v2']);
});

it('method wins over class when both carry #[Spec]', function (): void {
    $method = new ReflectionMethod(FxBoth::class, 'handle');
    $class = new ReflectionClass(FxBoth::class);

    expect($this->resolver->resolve($class, $method))->toBe(['v2']);
});

it('unions repeated #[Spec] attributes on the same target', function (): void {
    $method = new ReflectionMethod(FxRepeatable::class, 'handle');
    $class = new ReflectionClass(FxRepeatable::class);

    expect($this->resolver->resolve($class, $method))->toBe(['v1', 'v2']);
});

it('returns null when neither class nor method carries #[Spec]', function (): void {
    $method = new ReflectionMethod(FxNone::class, 'handle');
    $class = new ReflectionClass(FxNone::class);

    expect($this->resolver->resolve($class, $method))->toBeNull();
});

it('handles a null class reflector (closure routes)', function (): void {
    $method = new ReflectionMethod(FxClassOnly::class, 'handle');

    expect($this->resolver->resolve(null, $method))->toBe(
        ['v1'],
    );  // method's class wins via $method->getDeclaringClass()
});

it('handles a null method reflector', function (): void {
    $class = new ReflectionClass(FxClassOnly::class);

    expect($this->resolver->resolve($class, null))->toBe(['v1']);
});

it('returns null when both reflectors are null', function (): void {
    expect($this->resolver->resolve(null, null))->toBeNull();
});
