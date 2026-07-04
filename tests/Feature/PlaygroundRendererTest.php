<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

uses(WithPlayground::class)->group('openapi');

it('renders the Scalar shell by default, pointed at the spec endpoint', function (): void {
    $response = $this->get('/api/docs')->assertOk();

    expect($response->getContent())
        ->toContain('@scalar/api-reference')
        ->toContain(route('openapi.spec'));
});

it('renders the Swagger UI shell when the renderer is swagger-ui', function (): void {
    config(['openapi.routes.playground.renderer' => 'swagger-ui']);

    $response = $this->get('/api/docs')->assertOk();

    expect($response->getContent())
        ->toContain('SwaggerUIBundle')
        ->toContain('swagger-ui')
        ->toContain(route('openapi.spec'))
        ->not->toContain('@scalar/api-reference');
});

it('falls back to the Scalar shell for an unknown renderer value', function (): void {
    config(['openapi.routes.playground.renderer' => 'nonsense']);

    expect($this->get('/api/docs')->assertOk()->getContent())
        ->toContain('@scalar/api-reference');
});

it('includes persistAuthorization in the Swagger UI shell when persist is true', function (): void {
    config([
        'openapi.routes.playground.renderer' => 'swagger-ui',
        'openapi.routes.playground.auth.persist' => true,
    ]);

    expect($this->get('/api/docs')->assertOk()->getContent())
        ->toContain('persistAuthorization: true');
});

it('omits persistAuthorization from the Swagger UI shell when persist is false', function (): void {
    config([
        'openapi.routes.playground.renderer' => 'swagger-ui',
        'openapi.routes.playground.auth.persist' => false,
    ]);

    expect($this->get('/api/docs')->assertOk()->getContent())
        ->not->toContain('persistAuthorization');
});

it('includes persistAuthorization in Swagger UI by default', function (): void {
    config(['openapi.routes.playground.renderer' => 'swagger-ui']);

    // No auth.persist configured — the controller fallback is true.
    expect($this->get('/api/docs')->assertOk()->getContent())
        ->toContain('persistAuthorization: true');
});

it('includes data-configuration in Scalar when preferred_scheme is set', function (): void {
    config(['openapi.routes.playground.auth.preferred_scheme' => 'bearer']);

    expect($this->get('/api/docs')->assertOk()->getContent())
        ->toContain('data-configuration')
        // The actual scheme value must round-trip into the JSON (HTML-encoded by Blade's {{ }}).
        ->toContain('preferredSecurityScheme&quot;:&quot;bearer&quot;');
});

it('omits data-configuration from Scalar when preferred_scheme is null', function (): void {
    config(['openapi.routes.playground.auth.preferred_scheme' => null]);

    expect($this->get('/api/docs')->assertOk()->getContent())
        ->not->toContain('data-configuration');
});

it('omits data-configuration from Scalar when auth config is absent', function (): void {
    // No auth config set at all — the page must still load and omit data-configuration.
    expect($this->get('/api/docs')->assertOk()->getContent())
        ->not->toContain('data-configuration');
});

it('HTML-encodes the preferred_scheme value in the Scalar attribute', function (): void {
    // A scheme name containing a double-quote is first JSON-escaped by json_encode (to \"),
    // then Blade's {{ }} HTML-encodes the full JSON string, turning " to &quot;.
    // The result in the attribute is my-api\&quot;key — structurally safe because no bare
    // double-quote appears outside the HTML entity, so the attribute boundary is intact.
    config(['openapi.routes.playground.auth.preferred_scheme' => 'my-api"key']);

    $html = $this->get('/api/docs')->assertOk()->getContent();

    // The raw unescaped double-quote must not appear in the attribute value.
    expect($html)->not->toContain('my-api"key');
    // The JSON-then-HTML-encoded form must be present.
    expect($html)->toContain('my-api\&quot;key');
});
