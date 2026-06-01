<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Attributes\Webhook;

class DuplicateWebhookNameController
{
    #[Webhook(name: 'stripe.payment_intent.succeeded')]
    public function handlePaymentSucceeded(): void {}
}
