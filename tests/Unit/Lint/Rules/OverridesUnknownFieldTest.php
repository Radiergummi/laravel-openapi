<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Lint\Rules\OverridesUnknownField;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;

uses()->group('openapi', 'lint');

/**
 * @param array<string, array<string, mixed>> $overrides
 *
 * @return list<Radiergummi\OpenApi\Lint\Finding>
 */
function overridesUnknownFieldCollect(array $overrides): array
{
    $collector = new ArrayFindingsCollector();

    new OverridesUnknownField($overrides)
        ->checkConfiguration(app(SpecRegistry::class), [], $collector);

    return $collector->all();
}

it('has the correct id and severity', function (): void {
    $rule = new OverridesUnknownField([]);

    expect($rule->id())->toBe('overrides.unknown-field')
        ->and($rule->severity())->toBe(Severity::Inconsistent);
});

it('stays silent for fully allowlisted blocks', function (): void {
    $findings = overridesUnknownFieldCollect([
        'users.show' => [
            'operationId' => 'x',
            'summary'     => 'x',
            'description' => 'x',
            'tags'        => ['x'],
            'deprecated'  => true,
            'x-internal'  => true,
        ],
    ]);

    expect($findings)->toBe([]);
});

it('flags an unknown field key', function (): void {
    $findings = overridesUnknownFieldCollect([
        'users.show' => ['operatonId' => 'typo'],
    ]);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('overrides.unknown-field')
        ->and($findings[0]->message)->toContain('users.show')
        ->and($findings[0]->message)->toContain('operatonId');
});

it('flags each unknown field in a block separately', function (): void {
    $findings = overridesUnknownFieldCollect([
        'users.show' => ['responses' => [], 'parameters' => []],
    ]);

    expect($findings)->toHaveCount(2);
});

it('ignores a malformed (non-array) override block', function (): void {
    // @phpstan-ignore argument.type (intentionally malformed config to exercise the is_array guard)
    $findings = overridesUnknownFieldCollect([
        'users.show' => 'not-an-array',
    ]);

    expect($findings)->toBe([]);
});
