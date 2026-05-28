<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use Attribute;

/**
 * Marks an inbound webhook handler — third-party POSTs (Stripe, Mailgun, …) that the generator
 * emits under the OpenAPI 3.1 top-level `webhooks` block instead of `paths`. Method-level only,
 * since each method handles its own logical event name. All standard operation-level attributes
 * still apply.
 *
 * ```php
 * #[OpenApi\Webhook(name: 'stripe.payment_intent.payment_failed')]
 * #[Routing\Post('webhook', name: 'stripe.webhook')]
 * public function handleWebhook(Request $request): Response { … }
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class Webhook
{
    /**
     * @param non-empty-string $name Logical webhook name used as the map key under `webhooks` in
     *                               the generated spec. Use the provider's event name convention,
     *                               e.g. `stripe.payment_intent.succeeded` or `mailgun.delivered`.
     */
    public function __construct(
        public string $name,
    ) {}
}
