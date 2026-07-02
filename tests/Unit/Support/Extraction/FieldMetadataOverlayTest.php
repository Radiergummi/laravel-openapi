<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Extraction;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Support\Extraction\FieldMetadataOverlay;
use Radiergummi\OpenApi\Support\Provenance\FieldCandidate;

use function Radiergummi\OpenApi\is_undefined;

function overlay(): FieldMetadataOverlay
{
    return FieldMetadataOverlay::create();
}

it('resolves the highest-precedence present candidate', function (): void {
    $resolved = overlay()->resolve('description', [
        FieldCandidate::present('from attribute', '#[PathParam]', 'attribute'),
        FieldCandidate::present('from docblock', '@param', 'docblock'),
    ]);

    expect($resolved?->value)->toBe('from attribute')
        ->and($resolved?->source)->toBe('#[PathParam]')
        ->and($resolved?->supersededBy)->toBe(['@param']);
});

it('skips absent candidates and resolves the first present one', function (): void {
    $resolved = overlay()->resolve('description', [
        FieldCandidate::absent('#[PathParam]', 'no attribute'),
        FieldCandidate::present('from docblock', '@param', 'docblock'),
        FieldCandidate::present('synthetic', 'convention', 'fallback'),
    ]);

    expect($resolved?->value)->toBe('from docblock')
        ->and($resolved?->supersededBy)->toBe(['convention']);
});

it('returns null when every candidate is absent', function (): void {
    $resolved = overlay()->resolve('description', [
        FieldCandidate::absent('#[PathParam]', 'no attribute'),
        FieldCandidate::absent('@param', 'no docblock'),
    ]);

    expect($resolved)->toBeNull();
});

it('applies resolved sub-fields onto a schema and records provenance', function (): void {
    $schema = new OA\Schema([]);

    $provenance = overlay()->apply($schema, [
        'description' => [FieldCandidate::present('A user id.', '#[PathParam]', 'attribute')],
        'format' => [FieldCandidate::present('uuid', '#[PathParam]', 'attribute')],
    ]);

    expect($schema->description)->toBe('A user id.')
        ->and($schema->format)->toBe('uuid')
        ->and($provenance)->toHaveCount(2)
        ->and($provenance[0]->field)->toBe('description')
        ->and($provenance[0]->source)->toBe('#[PathParam]');
});

it('leaves a sub-field untouched when no candidate is present', function (): void {
    $schema = new OA\Schema(['description' => 'inferred']);

    $provenance = overlay()->apply($schema, [
        'description' => [FieldCandidate::absent('#[PathParam]', 'no attribute')],
    ]);

    expect($schema->description)->toBe('inferred')
        ->and($provenance)->toBe([]);
});

it('treats an explicit value as present even when it is null', function (): void {
    // The overlay is policy-free: a caller that wants "null suppresses" passes an absent candidate;
    // an explicitly present null value is applied verbatim.
    $schema = new OA\Schema(['description' => 'inferred']);

    overlay()->apply($schema, [
        'description' => [FieldCandidate::present(null, '#[PathParam]', 'explicit null')],
    ]);

    expect($schema->description)->toBeNull();
});

it('applies onto an OA\\Parameter target', function (): void {
    $parameter = new OA\Parameter([]);

    overlay()->apply($parameter, [
        'deprecated' => [FieldCandidate::present(true, '#[QueryParam]', 'attribute')],
    ]);

    expect($parameter->deprecated)->toBeTrue()
        ->and(is_undefined($parameter->description))->toBeTrue();
});
