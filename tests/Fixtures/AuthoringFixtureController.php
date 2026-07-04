<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Radiergummi\OpenApi\Attributes\CookieParam;
use Radiergummi\OpenApi\Attributes\Deprecated;
use Radiergummi\OpenApi\Attributes\Example;
use Radiergummi\OpenApi\Attributes\ExternalDocs;
use Radiergummi\OpenApi\Attributes\Header;
use Radiergummi\OpenApi\Attributes\Hide;
use Radiergummi\OpenApi\Attributes\Link;
use Radiergummi\OpenApi\Attributes\PublicEndpoint;
use Radiergummi\OpenApi\Attributes\RequestBody;
use Radiergummi\OpenApi\Attributes\Response;
use Radiergummi\OpenApi\Attributes\ResponseExample;
use Radiergummi\OpenApi\Attributes\ResponseHeader;
use Radiergummi\OpenApi\Attributes\Security;
use Radiergummi\OpenApi\Attributes\Webhook;
use Radiergummi\OpenApi\Enums\MediaType;

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

    /**
     * @deprecated Use createdResponseAction() instead.
     */
    public function deprecatedViaDocBlockAction(): array
    {
        return [];
    }

    /**
     * @deprecated Doc reason.
     */
    #[Deprecated(reason: 'Attribute reason.')]
    public function deprecatedViaAttributeAndDocBlockAction(): array
    {
        return [];
    }

    #[Header('X-Tenant-Id', example: 'acme-corp', required: true)]
    #[Header('Idempotency-Key', description: 'Client idempotency key')]
    public function headeredAction(): array
    {
        return [];
    }

    /** An inferred header read of the same name yields to the #[Header] attribute. */
    #[Header('X-Api-Key', description: 'Authored API key.', required: true)]
    public function inferredHeaderOverriddenByAttributeAction(Request $request): array
    {
        return [$request->header('X-Api-Key')];
    }

    /** Header names are case-insensitive, so a case-differing #[Header] and inferred read collapse. */
    #[Header('x-api-key', description: 'Authored API key.', required: true)]
    public function caseVariantHeaderInferredAndAttributeAction(Request $request): array
    {
        return [$request->header('X-Api-Key')];
    }

    /** Two #[Header] attributes differing only in case are the same header; the last one wins. */
    #[Header('X-Request-Id')]
    #[Header('x-request-id', required: true)]
    public function caseVariantHeaderAttributesAction(): array
    {
        return [];
    }

    /** Two inferred header reads differing only in case are the same header. */
    public function caseVariantInferredHeadersAction(Request $request): array
    {
        return [
            $request->header('X-Api-Key'),
            $request->header('x-api-key'),
        ];
    }

    /** An inferred cookie read of the same name yields to the #[CookieParam] attribute. */
    #[CookieParam('session', description: 'Authored session cookie.', required: true)]
    public function inferredCookieOverriddenByAttributeAction(Request $request): array
    {
        return [$request->cookie('session')];
    }

    /** Inferred query, cookie, and header reads of the same name coexist as three parameters. */
    public function inferredRequestLocationsAction(Request $request): array
    {
        return [
            $request->query('token'),
            $request->cookie('token'),
            $request->header('token'),
        ];
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

    #[Hide(only: ['staging', 'production'])]
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

    /**
     * Fixture for response-header inference: an authored Location header on the 201 response must
     * win over the convention-derived one (custom description kept, no duplicate appended).
     */
    #[Response(status: 201, description: 'Created')]
    #[ResponseHeader(name: 'Location', status: 201, description: 'Authored location')]
    public function authoredLocationAction(): array
    {
        return [];
    }

    /**
     * Fixture for response-header inference: an authored X-RateLimit-Limit header on the primary
     * (200) response must win over the convention-derived one, while the un-authored sibling
     * X-RateLimit-Remaining is still appended by the convention.
     */
    #[ResponseHeader(name: 'X-RateLimit-Limit', description: 'Authored limit')]
    public function authoredRateLimitAction(): array
    {
        return [];
    }
}
