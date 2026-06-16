<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;

uses()->group('openapi', 'lint');

it('passes when a matching finding was emitted', function (): void {
    $findings = [
        new Finding(
            ruleId: 'field.description-missing',
            severity: Severity::Underspecified,
            message: 'Field "status" has no description.',
        ),
    ];

    expect($findings)->toEmitFinding(ruleId: 'field.description-missing', messageContains: 'status');
});

it('normalises a generator without a manual iterator_to_array call', function (): void {
    $findings = (static function (): Generator {
        yield new Finding(ruleId: 'operation.id-missing', severity: Severity::Degraded, message: 'GET /users has no operationId.');
    })();

    expect($findings)->toEmitFinding(ruleId: 'operation.id-missing');
});

it('fails listing the emitted findings when no rule id matches', function (): void {
    $findings = [new Finding(ruleId: 'other.rule', severity: Severity::Degraded, message: 'something else')];

    expect(function () use ($findings): void {
        expect($findings)->toEmitFinding(ruleId: 'field.description-missing');
    })->toThrow(AssertionFailedError::class, "rule ID 'field.description-missing'");
});

it('fails when the rule id matches but the message substring does not', function (): void {
    $findings = [new Finding(ruleId: 'field.description-missing', severity: Severity::Underspecified, message: 'no relevant token here')];

    expect(function () use ($findings): void {
        expect($findings)->toEmitFinding(ruleId: 'field.description-missing', messageContains: 'status');
    })->toThrow(AssertionFailedError::class, 'status');
});

it('reports that no findings were emitted when the iterable is empty', function (): void {
    expect(function (): void {
        expect([])->toEmitFinding(ruleId: 'field.description-missing');
    })->toThrow(AssertionFailedError::class, 'no findings were emitted');
});
