<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\MethodBody\ShadowingScope;

use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use Radiergummi\OpenApi\Support\MethodBody\UnqualifiedHelperCall;

uses()->group('openapi');

/** A same-namespace function that shadows a would-be global helper of the same short name. */
function shadowingHelper(): void {}

it('resolves a fully-qualified name to the global helper', function (): void {
    expect(UnqualifiedHelperCall::resolvesToGlobalHelper(new FullyQualified('response')))->toBeTrue();
});

it('resolves an unqualified name with no same-namespace function to the global helper', function (): void {
    $name = new Name('abort');
    $name->setAttribute('namespacedName', new Name('App\\Http\\Controllers\\abort'));

    expect(UnqualifiedHelperCall::resolvesToGlobalHelper($name))->toBeTrue();
});

it('refuses an unqualified name shadowed by a same-namespace function', function (): void {
    $name = new Name('shadowingHelper');
    $name->setAttribute('namespacedName', new Name(__NAMESPACE__ . '\\shadowingHelper'));

    // Reference the local function so it is autoloaded and function_exists() sees it.
    shadowingHelper();

    expect(UnqualifiedHelperCall::resolvesToGlobalHelper($name))->toBeFalse();
});

it('treats an unqualified name without a namespacedName attribute as the global helper', function (): void {
    expect(UnqualifiedHelperCall::resolvesToGlobalHelper(new Name('response')))->toBeTrue();
});
