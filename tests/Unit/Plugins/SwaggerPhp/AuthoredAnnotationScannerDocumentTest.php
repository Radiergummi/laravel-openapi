<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\SwaggerPhp;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;

function documentScanner(?string $path = null): AuthoredAnnotationScanner
{
    return new AuthoredAnnotationScanner(
        [$path ?? dirname(__DIR__, 3) . '/Fixtures/SwaggerPhpDocument'],
        recordingLogger(),
    );
}

it('captures the authored document-level info annotation', function (): void {
    $info = documentScanner()->documentAnnotations()->info;

    expect($info)
        ->toBeInstanceOf(OA\Info::class)
        ->and($info->title)->toBe('Flights API')
        ->and($info->version)->toBe('2.1.0')
        ->and($info->description)->toBe('Book and manage flights.');
});

it('captures the authored document-level server annotation', function (): void {
    $servers = documentScanner()->documentAnnotations()->servers;

    expect($servers)->toHaveCount(1)
        ->and($servers[0]->url)->toBe('https://api.example.com')
        ->and($servers[0]->description)->toBe('Production');
});

it('captures the authored security scheme keyed by name', function (): void {
    $schemes = documentScanner()->documentAnnotations()->securitySchemes;

    expect($schemes)->toHaveKey('bearerAuth')
        ->and($schemes['bearerAuth']->type)->toBe('http')
        ->and($schemes['bearerAuth']->scheme)->toBe('bearer')
        ->and($schemes['bearerAuth']->bearerFormat)->toBe('JWT');
});

it('captures an authored root tag even when no operation references it', function (): void {
    // AugmentTags would prune an unused root tag; the scanner drops that processor so an
    // authored-but-unused @OA\Tag still surfaces for the migration rule.
    $tags = documentScanner()->documentAnnotations()->rootTags;

    expect($tags)->toHaveCount(1)
        ->and($tags[0]->name)->toBe('Flights')
        ->and($tags[0]->description)->toBe('Flight booking and management.');
});

it('returns an empty DTO for a source tree with no document annotations', function (): void {
    $document = documentScanner(dirname(__DIR__, 3) . '/Fixtures/SwaggerPhp')->documentAnnotations();

    expect($document->isEmpty())->toBeTrue()
        ->and($document->info)->toBeNull()
        ->and($document->servers)->toBe([])
        ->and($document->securitySchemes)->toBe([])
        ->and($document->rootTags)->toBe([]);
});

it('degrades to an empty DTO when the scan path does not exist', function (): void {
    expect(documentScanner('/no/such/directory')->documentAnnotations()->isEmpty())->toBeTrue();
});

it('normalises unauthored info to null rather than the swagger-php sentinel', function (): void {
    // The scanner converts swagger-php's UNDEFINED info into a plain null on the DTO.
    $info = documentScanner(dirname(__DIR__, 3) . '/Fixtures/SwaggerPhp')->documentAnnotations()->info;

    expect($info)->toBeNull();
});
