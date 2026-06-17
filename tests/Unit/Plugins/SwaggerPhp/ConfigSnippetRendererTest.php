<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\SwaggerPhp;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\ConfigSnippetRenderer;

function renderer(): ConfigSnippetRenderer
{
    return new ConfigSnippetRenderer();
}

it('renders an info snippet matching the openapi.info array shape', function (): void {
    $snippet = renderer()->info(new OA\Info([
        'title' => 'Flights API',
        'version' => '2.1.0',
        'description' => 'Book and manage flights.',
    ]));

    expect($snippet)->toBe(<<<'PHP'
        'info' => [
            'title' => 'Flights API',
            'version' => '2.1.0',
            'description' => 'Book and manage flights.',
        ],
        PHP);
});

it('renders contact and license as nested info arrays', function (): void {
    $snippet = renderer()->info(new OA\Info([
        'title' => 'API',
        'version' => '1.0.0',
        'contact' => new OA\Contact(['email' => 'team@example.com']),
        'license' => new OA\License(['name' => 'MIT']),
    ]));

    expect($snippet)->toBe(<<<'PHP'
        'info' => [
            'title' => 'API',
            'version' => '1.0.0',
            'contact' => [
                'email' => 'team@example.com',
            ],
            'license' => [
                'name' => 'MIT',
            ],
        ],
        PHP);
});

it('renders a server snippet as a list entry matching openapi.servers', function (): void {
    $snippet = renderer()->servers(new OA\Server([
        'url' => 'https://api.example.com',
        'description' => 'Production',
    ]));

    expect($snippet)->toBe(<<<'PHP'
        'servers' => [
            [
                'url' => 'https://api.example.com',
                'description' => 'Production',
            ],
        ],
        PHP);
});

it('renders a security scheme keyed by name matching openapi.security_schemes', function (): void {
    $snippet = renderer()->securityScheme(new OA\SecurityScheme([
        'securityScheme' => 'bearerAuth',
        'type' => 'http',
        'scheme' => 'bearer',
        'bearerFormat' => 'JWT',
    ]));

    expect($snippet)->toBe(<<<'PHP'
        'security_schemes' => [
            'bearerAuth' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'JWT',
            ],
        ],
        PHP);
});

it('renders a tag keyed by name matching openapi.tags', function (): void {
    $snippet = renderer()->tag(new OA\Tag([
        'name' => 'Flights',
        'description' => 'Flight booking and management.',
    ]));

    expect($snippet)->toBe(<<<'PHP'
        'tags' => [
            'Flights' => [
                'description' => 'Flight booking and management.',
            ],
        ],
        PHP);
});

it('emits only the authored keys, dropping unset properties', function (): void {
    // An Info with only a title must not invent version/description placeholders.
    $snippet = renderer()->info(new OA\Info(['title' => 'Minimal']));

    expect($snippet)->toBe(<<<'PHP'
        'info' => [
            'title' => 'Minimal',
        ],
        PHP);
});
