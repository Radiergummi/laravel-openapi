<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Plugins\Core\Support\InlineValidationScanResult;
use Radiergummi\OpenApi\Plugins\Core\Support\InlineValidatorRulesReader;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Tests\Fixtures\InlineValidationFixtureController;
use Radiergummi\OpenApi\Tests\Fixtures\InlineValidationImpostorController;

uses()->group('openapi');

// region Helpers

/**
 * @param class-string $controller
 */
function readInlineValidationRules(
    string $method,
    string $controller = InlineValidationFixtureController::class,
): ?InlineValidationScanResult {
    $reader = new InlineValidatorRulesReader(new MethodBodyScanner());

    return $reader->read(new ReflectionMethod($controller, $method));
}

// endregion

// region Shape 1: $request->validate([...])

it('recovers rules from $request->validate([...])', function (): void {
    $result = readInlineValidationRules('store');

    expect($result)->not->toBeNull()
        ->and($result?->rules)->toBe([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email'],
            'age' => 'nullable|integer|min:18',
            'tags' => 'array',
            'tags.*' => 'string',
        ]);
});

it('reads trailing comments on rule entries as field descriptions', function (): void {
    $result = readInlineValidationRules('store');

    expect($result?->descriptions)->toBe([
        'name' => 'The display name.',
        'email' => 'The contact address.',
    ]);
});

// endregion

// region Shape 2: $this->validate($request, [...])

it('recovers rules from $this->validate($request, [...])', function (): void {
    $result = readInlineValidationRules('update');

    expect($result?->rules)->toBe(['title' => 'required|string']);
});

it('recovers rules from $this->validate(request(), [...]) — the Bagisto idiom', function (): void {
    $result = readInlineValidationRules('viaRequestHelper');

    expect($result?->rules)->toBe(['locale' => 'required|string']);
});

it('does not match a controller-own validate() helper taking non-request arguments', function (): void {
    $result = readInlineValidationRules('viaOwnValidateHelper', InlineValidationImpostorController::class);

    expect($result)->toBeNull();
});

// endregion

// region Shape 3: Validator::make($request->all(), [...])

it('recovers rules from Validator::make(), including a chained ->validate()', function (): void {
    $result = readInlineValidationRules('viaValidatorFacade');

    expect($result?->rules)->toBe(['amount' => 'required|numeric']);
});

it('does not match an imported non-facade Validator class', function (): void {
    $result = readInlineValidationRules('viaImportedValidator', InlineValidationImpostorController::class);

    expect($result)->toBeNull();
});

// endregion

// region Shape 4: Request::validate([...])

it('recovers rules from the aliased Request facade', function (): void {
    $result = readInlineValidationRules('viaRequestFacade');

    expect($result?->rules)->toBe(['token' => 'required|string']);
});

// endregion

// region Controller-declared rules

it('resolves $this->rules to the property default value', function (): void {
    $result = readInlineValidationRules('fromRulesProperty');

    expect($result?->rules)->toBe([
        'title' => 'required|string|max:120',
        'body' => ['nullable', 'string'],
    ]);
});

it('resolves $this->rulesByAction()[key] — the BookStack idiom', function (): void {
    $result = readInlineValidationRules('fromKeyedRulesMethod');

    expect($result?->rules)->toBe([
        'name' => ['required', 'string'],
        'description' => 'nullable|string',
    ]);
});

// endregion

// region Conditional contexts (Tier boundary)

it('does not match a validate() in a ternary arm', function (): void {
    expect(readInlineValidationRules('conditionalTernary'))->toBeNull();
});

it('does not match a validate() behind a logical short-circuit', function (): void {
    expect(readInlineValidationRules('conditionalShortCircuit'))->toBeNull();
});

it('does not match an if-guarded validate() inside a transaction closure', function (): void {
    expect(readInlineValidationRules('conditionalInsideClosure'))->toBeNull();
});

// endregion

// region Partial and full degradation

it('keeps literal elements and drops dynamic fields, recording them', function (): void {
    $result = readInlineValidationRules('partiallyDynamic');

    expect($result?->rules)->toBe([
        'status' => ['required'],
        'note' => 'nullable|string',
    ])->and($result?->skippedFields)->toBe(['callback']);
});

it('degrades when the rules argument is a local variable', function (): void {
    $result = readInlineValidationRules('dynamicRules');

    expect($result)->not->toBeNull()
        ->and($result?->rules)->toBeNull()
        ->and($result?->degradeReason)->toContain('neither an array literal');
});

it('does not match a validate() call beyond the statement limit', function (): void {
    expect(readInlineValidationRules('lateValidate'))->toBeNull();
});

it('returns null for methods without a whitelisted validator call', function (): void {
    expect(readInlineValidationRules('withoutValidation'))->toBeNull();
});

// endregion
