<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Tests\Fixtures\Auth\AuthFixtureController;
use Symfony\Component\Yaml\Yaml;

uses()->group('openapi');

beforeEach(function (): void {
    Route::get('/oa-fixture/public', [AuthoringFixtureController::class, 'publicAction'])
        ->middleware('auth:api');
    Route::get('/oa-fixture/scoped', [AuthoringFixtureController::class, 'scopedAction']);
    Route::get('/oa-fixture/headered', [AuthoringFixtureController::class, 'headeredAction']);
    Route::get('/oa-fixture/external-docs', [AuthoringFixtureController::class, 'withExternalDocsAction']);
    Route::post('/oa-fixture/webhook', [AuthoringFixtureController::class, 'webhookAction']);
    Route::get('/oa-fixture/throwing', [AuthoringFixtureController::class, 'throwingAction'])
        ->middleware(['auth:api', 'scope:projects', 'throttle:api']);
    Route::get('/oa-fixture/throws-fixture', [AuthoringFixtureController::class, 'throwsFixtureAction']);
    Route::post('/oa-fixture/linked', [AuthoringFixtureController::class, 'linkedAction']);
    Route::post('/oa-fixture/multi-linked', [AuthoringFixtureController::class, 'multiLinkedAction']);
    Route::post('/oa-fixture/inbound-webhook', [AuthoringFixtureController::class, 'inboundWebhookAction']);
    Route::post('/oa-fixture/malformed-request-example', [AuthoringFixtureController::class, 'malformedRequestExampleAction']);
    Route::get('/oa-fixture/malformed-response-example', [AuthoringFixtureController::class, 'malformedResponseExampleAction']);
    Route::post('/oa-fixture/created-response', [AuthoringFixtureController::class, 'createdResponseAction']);
    Route::post('/oa-fixture/multi-twoxx-response', [AuthoringFixtureController::class, 'multiTwoxxResponseAction']);

    $this->spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());
});

it('emits security: [] for PublicEndpoint despite auth middleware', function (): void {
    $operation = $this->spec['paths']['/oa-fixture/public']['get'];

    expect($operation['security'])->toBe([]);
});

it('honors explicit Security scopes', function (): void {
    $operation = $this->spec['paths']['/oa-fixture/scoped']['get'];

    expect($operation['security'])->toBe([
        ['oauth2' => ['admin', 'projects']],
        ['oauth2ClientCredentials' => ['admin', 'projects']],
    ]);
});

it('advertises both Authorization Code and Client Credentials schemes', function (): void {
    $schemes = $this->spec['components']['securitySchemes'];

    expect($schemes)
        ->toHaveKey('oauth2')
        ->and($schemes['oauth2']['type'])->toBe('oauth2')
        ->and($schemes['oauth2']['flows'])->toHaveKey('authorizationCode')
        ->and($schemes['oauth2']['flows']['authorizationCode']['tokenUrl'])->toEndWith('/oauth/token')
        ->and($schemes)->toHaveKey('oauth2ClientCredentials')
        ->and($schemes['oauth2ClientCredentials']['type'])->toBe('oauth2')
        ->and($schemes['oauth2ClientCredentials']['flows'])->toHaveKey('clientCredentials')
        ->and($schemes['oauth2ClientCredentials']['flows']['clientCredentials']['tokenUrl'])
        ->toEndWith('/oauth/token')
        ->and($schemes['oauth2ClientCredentials']['flows']['clientCredentials'])
        ->not->toHaveKey('refreshUrl');
});

it('emits both schemes in middleware-derived per-route security', function (): void {
    $operation = $this->spec['paths']['/oa-fixture/throwing']['get'];

    expect($operation['security'])->toBe([
        ['oauth2' => ['projects']],
        ['oauth2ClientCredentials' => ['projects']],
    ]);
});

it('appends Header attributes as in: header parameters', function (): void {
    $operation = $this->spec['paths']['/oa-fixture/headered']['get'];

    $headers = array_values(array_filter(
        $operation['parameters'] ?? [],
        static fn(array $p): bool => ($p['in'] ?? null) === 'header',
    ));

    expect($headers)->toHaveCount(2)
        ->and($headers[0]['name'])->toBe('X-Tenant-Id')
        ->and($headers[0]['required'])->toBeTrue()
        ->and($headers[0]['schema']['example'])->toBe('acme-corp')
        ->and($headers[1]['name'])->toBe('Idempotency-Key')
        ->and($headers[1]['description'])->toBe('Client idempotency key');
});

it('attaches ExternalDocs to the operation', function (): void {
    $operation = $this->spec['paths']['/oa-fixture/external-docs']['get'];

    expect($operation['externalDocs']['url'])->toBe('https://notion.so/runbook')
        ->and($operation['externalDocs']['description'])->toBe('Implementation notes');
});

it('overrides request body media type and description from RequestBody attribute', function (): void {
    $operation = $this->spec['paths']['/oa-fixture/webhook']['post'];

    // No Data class on the method, so the attribute builds a minimal body from scratch.
    expect($operation['requestBody']['description'])->toBe('Webhook payload')
        ->and(array_key_first($operation['requestBody']['content']))->toBe('application/x-www-form-urlencoded');
});

it('emits error responses derived from @throws annotations', function (): void {
    $responses = $this->spec['paths']['/oa-fixture/throwing']['get']['responses'];

    // Since OAPI-018, known status codes are componentised: the per-operation response
    // is a $ref to components.responses rather than an inline schema with a description.
    expect($responses)
        ->toHaveKey('404')
        ->and($responses['404']['$ref'])->toBe('#/components/responses/NotFound')
        ->and($responses)->toHaveKey('422')
        ->and($responses['422']['$ref'])->toBe('#/components/responses/ValidationFailed')
        ->and($responses)->toHaveKey('401');
});

it('emits middleware-derived 401, 403, and 429 responses', function (): void {
    $responses = $this->spec['paths']['/oa-fixture/throwing']['get']['responses'];

    // Since OAPI-018, standard responses are componentised — verify the $refs.
    expect($responses)
        ->toHaveKey('401')
        ->and($responses['401']['$ref'])->toBe('#/components/responses/Unauthorized')
        ->and($responses)->toHaveKey('403')
        ->and($responses['403']['$ref'])->toBe('#/components/responses/Forbidden')
        ->and($responses)->toHaveKey('429')
        ->and($responses['429']['$ref'])->toBe('#/components/responses/TooManyRequests');
});

it('resolves #[Throws] attribute from the exception class via use-statement resolution', function (): void {
    $responses = $this->spec['paths']['/oa-fixture/throws-fixture']['get']['responses'];

    expect($responses)
        ->toHaveKey('418')
        ->and($responses['418']['description'])->toBe("I'm a teapot");
});

it('omits routes marked with bare #[Hide]', function (): void {
    Route::get('/oa-fixture/hidden', [AuthoringFixtureController::class, 'hiddenAction']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());

    expect($spec['paths'])->not->toHaveKey('/oa-fixture/hidden');
});

it('keeps env-scoped #[Hide] routes visible in non-matching environments', function (): void {
    Route::get('/oa-fixture/env-hidden', [AuthoringFixtureController::class, 'envHiddenAction']);

    // Tests run under the 'testing' environment; the fixture hides in
    // staging/production, so the route should be present here.
    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());

    expect($spec['paths'])->toHaveKey('/oa-fixture/env-hidden');
});

it('omits env-scoped #[Hide] routes when the current environment matches', function (): void {
    Route::get('/oa-fixture/env-hidden', [AuthoringFixtureController::class, 'envHiddenAction']);

    app()['env'] = 'production';

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());

    expect($spec['paths'])->not->toHaveKey('/oa-fixture/env-hidden');
});

it('emits a link on the primary response from a single #[Link] attribute', function (): void {
    $response = $this->spec['paths']['/oa-fixture/linked']['post']['responses']['200'];

    expect($response)->toHaveKey('links')
        ->and($response['links'])->toHaveKey('GetWidget')
        ->and($response['links']['GetWidget']['operationId'])->toBe('api.v0.widgets.single')
        ->and($response['links']['GetWidget']['parameters'])->toBe(['uuid' => '$response.body#/data/uuid'])
        ->and($response['links']['GetWidget']['description'])->toBe('Retrieve the created widget.');
});

it('emits multiple links when several #[Link] attributes are stacked', function (): void {
    $response = $this->spec['paths']['/oa-fixture/multi-linked']['post']['responses']['200'];

    expect($response)->toHaveKey('links')
        ->and($response['links'])->toHaveKey('GetAlpha')
        ->and($response['links']['GetAlpha']['operationId'])->toBe('api.v0.alpha')
        ->and($response['links'])->toHaveKey('GetBeta')
        ->and($response['links']['GetBeta']['operationRef'])->toBe('#/paths/~1beta/get')
        ->and($response['links']['GetBeta']['parameters'])->toBe(['id' => '$response.body#/data/id']);
});

it('omits optional link fields when not provided', function (): void {
    $link = $this->spec['paths']['/oa-fixture/multi-linked']['post']['responses']['200']['links']['GetAlpha'];

    expect($link)->not->toHaveKey('parameters')
        ->and($link)->not->toHaveKey('description')
        ->and($link)->not->toHaveKey('operationRef');
});

// OAPI-026 — #[Webhook] diverts routes from paths into webhooks

it('emits a webhooks block when at least one #[Webhook] route is registered', function (): void {
    expect($this->spec)->toHaveKey('webhooks');
});

it('places a #[Webhook] route under webhooks.{name} instead of paths', function (): void {
    expect($this->spec['webhooks'])->toHaveKey('test.event')
        ->and($this->spec['paths'])->not->toHaveKey('/oa-fixture/inbound-webhook');
});

it('emits the correct HTTP method under the webhook name', function (): void {
    expect($this->spec['webhooks']['test.event'])->toHaveKey('post');
});

it('carries the auto-derived summary onto the webhook operation', function (): void {
    $operation = $this->spec['webhooks']['test.event']['post'];

    expect($operation['summary'])->toBe('Handle an inbound test event.');
});

it('omits a webhooks key when no routes carry #[Webhook]', function (): void {
    // Re-generate while filtering out the inbound webhook route.
    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate(
        [static fn($d): bool => $d->route->getActionName() === AuthoringFixtureController::class . '@inboundWebhookAction'],
    )->toYaml());

    expect($spec)->not->toHaveKey('webhooks');
});

it('falls back to app name and 0.0.0 when openapi.info is not configured', function (): void {
    config()->set('openapi.info', null);
    config()->set('app.name', 'Test App');

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());

    expect($spec['info']['title'])->toBe('Test App')
        ->and($spec['info']['version'])->toBe('0.0.0');
});

it('reads info, servers, and tags from config', function (): void {
    config()->set('openapi.info', [
        'title'   => 'Custom API',
        'version' => '9.9.9',
    ]);
    config()->set('openapi.servers', [
        ['url' => 'https://prod.example.com', 'description' => 'Production'],
        ['url' => 'https://staging.example.com', 'description' => 'Staging'],
    ]);
    config()->set('openapi.tags', [
        'Projects' => ['description' => 'Sourcing project management.'],
    ]);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());

    expect($spec['info']['title'])->toBe('Custom API')
        ->and($spec['info']['version'])->toBe('9.9.9')
        ->and($spec['servers'])->toHaveCount(2)
        ->and($spec['servers'][0]['url'])->toBe('https://prod.example.com')
        ->and($spec['tags'])->toBe([
            ['name' => 'Projects', 'description' => 'Sourcing project management.'],
        ]);
});

// OAPI — deriveTag treats 'Auth' as a real domain segment, not structural noise

it('derives the Auth tag for controllers nested under an Auth namespace segment', function (): void {
    Route::get('/oa-fixture/auth-only', [AuthFixtureController::class, 'index']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());

    $tags = $spec['paths']['/oa-fixture/auth-only']['get']['tags'] ?? [];

    expect($tags)->toContain('Auth');
});

// Malformed #[Example] / #[ResponseExample] — generation must not abort

it('survives a malformed #[Example] with neither value nor file', function (): void {
    expect($this->spec['paths'])->toHaveKey('/oa-fixture/malformed-request-example');
});

it('survives a malformed #[ResponseExample] with both value and file', function (): void {
    expect($this->spec['paths'])->toHaveKey('/oa-fixture/malformed-response-example');
});

// region Bug 2: #[Response(status: 201)] must replace the auto-derived primary, not append

it('promotes #[Response(status: 201)] to the primary response and omits a redundant 200 (Bug 2)', function (): void {
    $responses = $this->spec['paths']['/oa-fixture/created-response']['post']['responses'];

    // The attribute-declared 201 must appear as the primary success response.
    expect($responses)->toHaveKey('201')
        ->and($responses['201']['description'])->toBe('Created');

    // No auto-derived 200 should leak alongside the explicit 201.
    expect($responses)->not->toHaveKey('200');
});

it('uses the first 2xx attribute as primary and keeps subsequent 2xx as additional (Bug 2)', function (): void {
    $responses = $this->spec['paths']['/oa-fixture/multi-twoxx-response']['post']['responses'];

    // First 2xx (201) becomes the primary; second 2xx (202) stays as additional.
    expect($responses)->toHaveKey('201')
        ->and($responses)->toHaveKey('202')
        ->and($responses)->not->toHaveKey('200');
});

// endregion
