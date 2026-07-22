<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Plugins\Core\Support\InlineJsonCallReader;
use Radiergummi\OpenApi\Plugins\Core\Support\SameClassResponseHelperReader;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Tests\Fixtures\SameClassHelperController;

uses()->group('openapi');

function sameClassHelperReader(): SameClassResponseHelperReader
{
    $scanner = new MethodBodyScanner();

    return new SameClassResponseHelperReader($scanner, new InlineJsonCallReader());
}

it('reads a body-less helper on an ancestor for its default status', function (): void {
    // empty() is declared on the base controller, not the subclass under test.
    $result = sameClassHelperReader()->read(SameClassHelperController::class, 'empty', []);

    expect($result->status)->toBe(204)
        ->and($result->note)->toBeNull();
});

it('reads the per-construction body-less shapes', function (string $method): void {
    $result = sameClassHelperReader()->read(SameClassHelperController::class, $method, []);

    expect($result->status)->toBe(204)
        ->and($result->note)->toBeNull();
})->with([
    'make with empty content' => 'makeEmptyPositional',
    'new Response' => 'newResponseNoContent',
    'response()->noContent()' => 'noContentHelper',
    'new JsonResponse through a whitelisted header chain' => 'jsonResponseWithHeaders',
]);

it('refuses when the body is reached through a variable', function (string $method): void {
    $result = sameClassHelperReader()->read(SameClassHelperController::class, $method, []);

    expect($result->status)->toBeNull()
        ->and($result->note)->toContain('variable');
})->with([
    'assigned then mutated with setData' => 'cached',
    'assigned once, no mutation' => 'assignedNoContent',
]);

it('refuses a delegation to another same-class helper without hopping', function (): void {
    $result = sameClassHelperReader()->read(SameClassHelperController::class, 'acceptedDirect', []);

    expect($result->status)->toBeNull()
        ->and($result->note)->toContain('delegates');
});

it('refuses a body-mutating trailing chain in the helper body', function (): void {
    $result = sameClassHelperReader()->read(SameClassHelperController::class, 'bodyMutatingChain', []);

    expect($result->status)->toBeNull()
        ->and($result->note)->toContain('body');
});

it('carries the derived non-2xx status through for the caller to reject', function (): void {
    // The reader derives the status; the 2xx policy lives with the resolver, so 500 comes back.
    $result = sameClassHelperReader()->read(SameClassHelperController::class, 'serverError', []);

    expect($result->status)->toBe(500)
        ->and($result->note)->toBeNull();
});

it('skips a helper that branches (no single unconditional return)', function (): void {
    $result = sameClassHelperReader()->read(SameClassHelperController::class, 'multiStatus', []);

    expect($result->status)->toBeNull()
        ->and($result->note)->toBeNull();
});

it('skips a body-bearing helper', function (string $method): void {
    $result = sameClassHelperReader()->read(SameClassHelperController::class, $method, []);

    expect($result->status)->toBeNull()
        ->and($result->note)->toBeNull();
})->with([
    'positional make(204) documents a body' => 'positionalMake',
    'json() with a data argument' => 'ok',
]);

it('skips a helper that does not exist', function (): void {
    $result = sameClassHelperReader()->read(SameClassHelperController::class, 'noSuchHelper', []);

    expect($result->status)->toBeNull()
        ->and($result->note)->toBeNull();
});

it('skips a helper declared in a vendor class', function (): void {
    // callAction is inherited from the framework Controller, whose file lives under /vendor/.
    $result = sameClassHelperReader()->read(SameClassHelperController::class, 'callAction', []);

    expect($result->status)->toBeNull()
        ->and($result->note)->toBeNull();
});
