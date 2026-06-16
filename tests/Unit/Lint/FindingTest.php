<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;

uses()->group('openapi', 'lint');

it('constructs a Finding with required fields and exposes them readonly', function (): void {
    $location = new FindingLocation(
        file: 'app/Http/Controllers/Foo.php',
        line: 42,
        routeName: 'foo.show',
        routeMethod: HttpMethod::Get,
        routeUri: 'foo/{id}',
    );

    $finding = new Finding(
        ruleId: 'response.empty',
        severity: Severity::Broken,
        message: 'No response schema',
        location: $location,
        fixHint: 'Add #[Response].',
        context: ['return_type' => 'JsonResponse'],
    );

    expect($finding->ruleId)
        ->toBe('response.empty')
        ->and($finding->severity)->toBe(Severity::Broken)
        ->and($finding->message)->toBe('No response schema')
        ->and($finding->location)->toBe($location)
        ->and($finding->fixHint)->toBe('Add #[Response].')
        ->and($finding->context)->toBe(['return_type' => 'JsonResponse']);
});

it('defaults fixHint to null and context to empty array', function (): void {
    $finding = new Finding(
        ruleId: 'x.y',
        severity: Severity::Degraded,
        message: 'msg',
        location: new FindingLocation(),
    );

    expect($finding->fixHint)
        ->toBeNull()
        ->and($finding->context)->toBe([]);
});

it('withSeverity returns a copy with the new severity and all other fields preserved', function (): void {
    $location = new FindingLocation(
        file: 'app/Http/Controllers/Foo.php',
        line: 10,
        routeName: 'foo.index',
        routeMethod: HttpMethod::Get,
        routeUri: 'foo',
    );

    $original = new Finding(
        ruleId: 'some.rule',
        severity: Severity::Underspecified,
        message: 'original message',
        location: $location,
        fixHint: 'fix it',
        context: ['key' => 'value'],
    );

    $remapped = $original->withSeverity(Severity::Broken);

    expect($remapped->severity)
        ->toBe(Severity::Broken)
        ->and($remapped->ruleId)->toBe('some.rule')
        ->and($remapped->message)->toBe('original message')
        ->and($remapped->location)->toBe($location)
        ->and($remapped->fixHint)->toBe('fix it')
        ->and($remapped->context)->toBe(['key' => 'value'])
        ->and($remapped)->not->toBe($original);
});
