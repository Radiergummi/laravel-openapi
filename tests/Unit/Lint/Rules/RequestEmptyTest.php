<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Rules\RequestEmpty;

uses()->group('openapi', 'lint');

// `request.empty` is a registration stub — it has no visitor. The finding is emitted by
// RequestBodyExtractor (covered by tests/Feature/Lint/RequestEmptyTest.php); these tests pin
// the stub's registration contract so its identity stays stable.

it('exposes the stable rule id', function (): void {
    expect(new RequestEmpty()->id())->toBe('request.empty');
});

it('reports a warning-level severity', function (): void {
    expect(new RequestEmpty()->severity())->toBe(Severity::Underspecified);
});

it('provides a non-empty description', function (): void {
    expect(new RequestEmpty()->description())->not->toBe('');
});

it('exposes the request-body fix hint', function (): void {
    expect(RequestEmpty::FIX_HINT)->toContain('Data class or FormRequest');
});
