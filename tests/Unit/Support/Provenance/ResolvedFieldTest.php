<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Support\Provenance\FieldCandidate;
use Radiergummi\OpenApi\Support\Provenance\ResolvedField;

uses()->group('openapi');

it('picks the first present candidate as the winner', function (): void {
    $resolved = ResolvedField::merge('summary', [
        FieldCandidate::present('Author summary', '#[Summary] (method)', 'author override'),
        FieldCandidate::present('Convention summary', 'convention', 'index → GET'),
    ]);

    expect($resolved)->not->toBeNull()
        ->and($resolved->value)->toBe('Author summary')
        ->and($resolved->source)->toBe('#[Summary] (method)')
        ->and($resolved->reason)->toBe('author override');
});

it('skips absent higher-precedence candidates and picks the first present one', function (): void {
    $resolved = ResolvedField::merge('summary', [
        FieldCandidate::absent('#[Summary] (method)', 'no method attribute'),
        FieldCandidate::absent('docblock', 'no docblock'),
        FieldCandidate::present('Convention summary', 'convention', 'index → GET'),
    ]);

    expect($resolved?->value)->toBe('Convention summary')
        ->and($resolved?->source)->toBe('convention');
});

it('records only present lower-precedence candidates as superseded', function (): void {
    $resolved = ResolvedField::merge('summary', [
        FieldCandidate::present('Author summary', '#[Summary] (method)', 'author override'),
        FieldCandidate::absent('docblock', 'no docblock'),
        FieldCandidate::present('Convention summary', 'convention', 'index → GET', "convention 'Convention summary'"),
    ]);

    expect($resolved?->supersededBy)->toBe(["convention 'Convention summary'"]);
});

it('uses the source as the superseded label when none is given', function (): void {
    $resolved = ResolvedField::merge('tags', [
        FieldCandidate::present(['a'], '#[Operation]', 'author override'),
        FieldCandidate::present(['b'], 'controller-derived', 'controller short name'),
    ]);

    expect($resolved?->supersededBy)->toBe(['controller-derived']);
});

it('returns null when no candidate is present', function (): void {
    $resolved = ResolvedField::merge('summary', [
        FieldCandidate::absent('#[Summary] (method)', 'no method attribute'),
        FieldCandidate::absent('convention', 'no convention matched'),
    ]);

    expect($resolved)->toBeNull();
});

it('returns null for an empty candidate list', function (): void {
    expect(ResolvedField::merge('summary', []))->toBeNull();
});

it('records no supersede for a single present candidate', function (): void {
    $resolved = ResolvedField::merge('status', [
        FieldCandidate::present(200, 'default', 'no convention matched'),
    ]);

    expect($resolved?->value)->toBe(200)
        ->and($resolved?->supersededBy)->toBe([]);
});

it('preserves the native value type of the winner', function (): void {
    $tags = ['Flights', 'Admin'];
    $resolved = ResolvedField::merge('tags', [
        FieldCandidate::present($tags, 'controller-derived', 'controller short name'),
    ]);

    expect($resolved?->value)->toBe($tags);
});

it('projects to a FieldProvenance with an explicit display value', function (): void {
    $resolved = ResolvedField::merge('status', [
        FieldCandidate::present(201, '#[Response] (method)', 'author override'),
    ]);
    $provenance = $resolved?->toProvenance('201');

    expect($provenance?->field)->toBe('status')
        ->and($provenance?->value)->toBe('201')
        ->and($provenance?->source)->toBe('#[Response] (method)')
        ->and($provenance?->reason)->toBe('author override')
        ->and($provenance?->supersededBy)->toBe([]);
});

it('stringifies a scalar winner value when no display value is given', function (): void {
    $resolved = ResolvedField::merge('status', [
        FieldCandidate::present(204, 'convention', 'destroy → DELETE'),
    ]);

    expect($resolved?->toProvenance()->value)->toBe('204');
});

it('carries the superseded labels into the projected provenance', function (): void {
    $resolved = ResolvedField::merge('summary', [
        FieldCandidate::present('Find one flight', '#[Summary] (method)', 'author override'),
        FieldCandidate::present('Show Flight', 'convention', 'show → GET', "convention 'Show Flight'"),
    ]);

    expect($resolved?->toProvenance()->supersededBy)->toBe(["convention 'Show Flight'"]);
});
