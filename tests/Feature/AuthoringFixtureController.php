<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Radiergummi\OpenApi\Core\Attributes\Example;
use Radiergummi\OpenApi\Core\Attributes\ExternalDocs;
use Radiergummi\OpenApi\Core\Attributes\Header;
use Radiergummi\OpenApi\Core\Attributes\Hide;
use Radiergummi\OpenApi\Core\Attributes\Link;
use Radiergummi\OpenApi\Core\Attributes\PublicEndpoint;
use Radiergummi\OpenApi\Core\Attributes\RequestBody;
use Radiergummi\OpenApi\Core\Attributes\Response;
use Radiergummi\OpenApi\Core\Attributes\ResponseExample;
use Radiergummi\OpenApi\Core\Attributes\Security;
use Radiergummi\OpenApi\Core\Attributes\Webhook;
use Radiergummi\OpenApi\Core\Enums\MediaType;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Radiergummi\OpenApi\Tests\Fixtures\TeapotException;

/**
 * Test fixture — exercises the medium-leverage authoring attributes.
 *
 * Routes are wired up in {@see AuthoringAttributesTest}'s `beforeEach`.
 */
class AuthoringFixtureController extends Controller
{
    #[PublicEndpoint]
    public function publicAction(): array
    {
        return [];
    }

    #[Security(['admin', 'projects'])]
    public function scopedAction(): array
    {
        return [];
    }

    #[Header('X-Tenant-Id', required: true, example: 'acme-corp')]
    #[Header('Idempotency-Key', description: 'Client idempotency key')]
    public function headeredAction(): array
    {
        return [];
    }

    #[ExternalDocs(url: 'https://notion.so/runbook', description: 'Implementation notes')]
    public function withExternalDocsAction(): array
    {
        return [];
    }

    #[RequestBody(description: 'Webhook payload', mediaType: MediaType::FormUrlEncoded)]
    public function webhookAction(): array
    {
        return [];
    }

    /**
     * Fixture for the throws-to-response extractor — see AuthoringAttributesTest.
     *
     * @throws AuthenticationException
     * @throws ModelNotFoundException
     * @throws ValidationException
     *
     * @noinspection PhpDocRedundantThrowsInspection
     */
    public function throwingAction(): array
    {
        return [];
    }

    #[Hide]
    public function hiddenAction(): array
    {
        return [];
    }

    #[Hide(environments: ['staging', 'production'])]
    public function envHiddenAction(): array
    {
        return [];
    }

    /**
     * Fixture for #[Throws] attribute resolution — see AuthoringAttributesTest.
     *
     * @throws TeapotException
     *
     * @noinspection PhpDocRedundantThrowsInspection
     */
    public function throwsFixtureAction(): array
    {
        return [];
    }

    #[Link(
        name: 'GetWidget',
        operationId: 'api.v0.widgets.single',
        parameters: ['uuid' => '$response.body#/data/uuid'],
        description: 'Retrieve the created widget.',
    )]
    public function linkedAction(): array
    {
        return [];
    }

    #[Link(name: 'GetAlpha', operationId: 'api.v0.alpha')]
    #[Link(name: 'GetBeta', operationRef: '#/paths/~1beta/get', parameters: ['id' => '$response.body#/data/id'])]
    public function multiLinkedAction(): array
    {
        return [];
    }

    /**
     * Handle an inbound test event.
     *
     * Exercises the #[Webhook] attribute that diverts a route from `paths`
     * into the top-level `webhooks` block.
     */
    #[Webhook(name: 'test.event')]
    public function inboundWebhookAction(): array
    {
        return [];
    }

    /**
     * Fixture for malformed #[Example] — neither value nor file is supplied.
     *
     * Generation must complete without throwing; the malformed attribute is skipped.
     */
    #[Example(name: 'bad-request-example')]
    public function malformedRequestExampleAction(): array
    {
        return [];
    }

    /**
     * Fixture for malformed #[ResponseExample] — both value and file are supplied.
     *
     * Generation must complete without throwing; the malformed attribute is skipped.
     */
    #[ResponseExample(status: 200, name: 'bad-response-example', value: ['id' => 'x'], file: 'some/path.json')]
    public function malformedResponseExampleAction(): array
    {
        return [];
    }

    /**
     * Fixture for Bug 2: a #[Response(status: 201, ...)] on a POST endpoint must become the
     * primary response (displacing the auto-derived 200) and must NOT also appear as an
     * additional response, so the operation has exactly one 201 and no 200.
     */
    #[Response(status: 201, description: 'Created')]
    public function createdResponseAction(): array
    {
        return [];
    }

    /**
     * Fixture for Bug 2 — multiple 2xx attributes: the first becomes primary, subsequent ones stay
     * as additional responses. No auto-derived 200 should appear alongside them.
     */
    #[Response(status: 201, description: 'Created')]
    #[Response(status: 202, description: 'Accepted')]
    public function multiTwoxxResponseAction(): array
    {
        return [];
    }
}
