<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

uses(WithPlayground::class)->group('openapi');

it('uses the route-derived spec URL when spec_url is not set', function (): void {
    $response = $this->get('/api/docs')->assertOk();

    expect($response->getContent())
        ->toContain(route('openapi.spec'))
        ->not->toContain('data-url=""')
        ->not->toContain("url: ''");
});

it('uses an explicit spec_url override verbatim for the Scalar renderer', function (): void {
    config(['openapi.routes.playground.spec_url' => 'https://cdn.example.com/api/openapi.yaml']);

    $response = $this->get('/api/docs')->assertOk();

    expect($response->getContent())
        ->toContain('https://cdn.example.com/api/openapi.yaml')
        ->not->toContain(route('openapi.spec'));
});

it('uses an explicit spec_url override verbatim for the Swagger UI renderer', function (): void {
    config([
        'openapi.routes.playground.renderer' => 'swagger-ui',
        'openapi.routes.playground.spec_url' => 'https://cdn.example.com/api/openapi.yaml',
    ]);

    $response = $this->get('/api/docs')->assertOk();

    expect($response->getContent())
        ->toContain('https://cdn.example.com/api/openapi.yaml')
        ->not->toContain(route('openapi.spec'));
});

it('falls back to the route-derived spec URL when spec_url is null', function (): void {
    config(['openapi.routes.playground.spec_url' => null]);

    $response = $this->get('/api/docs')->assertOk();

    expect($response->getContent())
        ->toContain(route('openapi.spec'));
});

it('falls back to the route-derived spec URL when spec_url is an empty string', function (): void {
    config(['openapi.routes.playground.spec_url' => '']);

    $response = $this->get('/api/docs')->assertOk();

    // A blank env var must not produce an empty data-url; the route-derived URL is used instead.
    expect($response->getContent())
        ->toContain(route('openapi.spec'))
        ->not->toContain('data-url=""')
        ->not->toContain("url: ''");
});
