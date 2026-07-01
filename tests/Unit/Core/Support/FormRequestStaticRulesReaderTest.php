<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Plugins\Core\Support\FormRequestStaticRulesReader;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic\BareReturnFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic\ComputedRulesFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic\ConditionalTweakFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic\DynamicElementFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic\DynamicRulesFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic\MultiReturnFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic\VariableReturnFormRequest;

uses()->group('openapi');

/**
 * @param class-string $formRequest
 *
 * @return null|array<string, array<int, mixed>|string>
 */
function readStaticRules(string $formRequest): ?array
{
    $reader = new FormRequestStaticRulesReader(new MethodBodyScanner());

    return $reader->read(new ReflectionMethod($formRequest, 'rules'));
}

it('recovers a literal assigned to a variable and then returned', function (): void {
    expect(readStaticRules(VariableReturnFormRequest::class))->toBe([
        'action' => 'sometimes|string',
        'ids' => 'required|array',
        'send_email' => 'sometimes|boolean',
    ]);
});

it('recovers a bare return array literal', function (): void {
    expect(readStaticRules(BareReturnFormRequest::class))->toBe([
        'name' => 'required|string',
        'email' => ['required', 'email'],
    ]);
});

it('recovers the base literal and ignores a conditional $rules[...] tweak', function (): void {
    expect(readStaticRules(ConditionalTweakFormRequest::class))->toBe([
        'action' => 'sometimes|string',
        'ids' => 'required|array',
    ]);
});

it('keeps literal rule elements and drops a dynamic one in a field', function (): void {
    expect(readStaticRules(DynamicElementFormRequest::class))->toBe([
        'foo' => ['required'],
        'bar' => 'string',
    ]);
});

it('returns null when the body returns a computed value with no literal', function (): void {
    expect(readStaticRules(DynamicRulesFormRequest::class))->toBeNull();
});

it('returns null when the body has more than one return', function (): void {
    expect(readStaticRules(MultiReturnFormRequest::class))->toBeNull();
});

it('returns null when the returned literal is computed at call time', function (): void {
    expect(readStaticRules(ComputedRulesFormRequest::class))->toBeNull();
});
