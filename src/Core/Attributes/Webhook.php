<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Attributes;

use Attribute;

/**
 * Marks an inbound webhook handler so the generator emits it under the OpenAPI 3.1 top-level
 * `webhooks` block instead of `paths`.
 *
 * Inbound webhooks are operations that a third party (Stripe, Mailgun, Teams, …) POSTs to your
 * server — distinct from operations your API consumers call. OpenAPI 3.1 provides a dedicated
 * `webhooks` field to document them separately.
 *
 * **Usage:** annotate the controller *method* that handles the webhook. The route still needs to
 * exist (so the request body / response extractors can do their job), but its path will not appear
 * under `paths`.
 *
 * ```php
 * #[OpenApi\Webhook(name: 'stripe.payment_intent.payment_failed')]
 * #[Routing\Post('webhook', name: 'stripe.webhook')]
 * public function handleWebhook(Request $request): Response { … }
 * ```
 *
 * The `name` becomes the map key under `webhooks`:
 *
 * ```yaml
 * webhooks:
 *   stripe.payment_intent.payment_failed:
 *     post:
 *       summary: …
 * ```
 *
 * All standard operation-level attributes (`#[OpenApi\Operation]`, `#[OpenApi\RequestBody]`,
 * `#[OpenApi\Response]`, `#[OpenApi\Tag]`, …) still apply — they compose the operation object
 * exactly as for a normal route.
 *
 * **Class-level placement** is intentionally not supported: different methods on the same
 * controller may handle different webhook events, and each needs its own logical `name`.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class Webhook
{
    /**
     * @param string $name Logical webhook name used as the map key under `webhooks` in the
     *                     generated spec. Use the provider's event name convention, e.g.
     *                     `stripe.payment_intent.succeeded` or `mailgun.delivered`.
     */
    public function __construct(
        public string $name,
    ) {}
}
