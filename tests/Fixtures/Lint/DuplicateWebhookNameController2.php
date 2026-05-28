<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Attributes\Webhook;

class DuplicateWebhookNameController2
{
    #[Webhook(name: 'stripe.payment_intent.succeeded')]
    public function handlePaymentSucceeded2(): void {}
}
