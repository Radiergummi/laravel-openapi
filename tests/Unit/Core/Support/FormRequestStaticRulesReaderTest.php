<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Plugins\Core\Support\FormRequestStaticRulesReader;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic\BareReturnBeyondWindowFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic\BareReturnFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic\BeyondBackstopFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic\ComputedRulesFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic\ConditionalTweakFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic\DynamicElementFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic\DynamicRulesFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic\MultiReturnFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic\ReassignedFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic\RebindFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic\UnconditionalTweakFormRequest;
use Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic\VariableReturnBeyondWindowFormRequest;
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

it('recovers a bare literal returned past the first ten statements', function (): void {
    expect(readStaticRules(BareReturnBeyondWindowFormRequest::class))->toBe([
        'name' => 'required|string',
        'email' => ['required', 'email'],
    ]);
});

it('recovers a variable literal assigned and returned across more than ten statements', function (): void {
    expect(readStaticRules(VariableReturnBeyondWindowFormRequest::class))->toBe([
        'action' => 'sometimes|string',
        'ids' => 'required|array',
    ]);
});

it('returns null when the return sits past the hundred-statement backstop', function (): void {
    expect(readStaticRules(BeyondBackstopFormRequest::class))->toBeNull();
});

it('refuses a $rules rebound by a foreach target', function (): void {
    expect(readStaticRules(RebindFormRequest::class))->toBeNull();
});

it('refuses a $rules assigned more than once', function (): void {
    expect(readStaticRules(ReassignedFormRequest::class))->toBeNull();
});

it('keeps the base literal when an unconditional $rules[...] = write adds a field', function (): void {
    // An additive element write leaves the base entries never-wrong, so the base subset is kept.
    expect(readStaticRules(UnconditionalTweakFormRequest::class))->toBe([
        'action' => 'sometimes|string',
        'ids' => 'required|array',
    ]);
});
