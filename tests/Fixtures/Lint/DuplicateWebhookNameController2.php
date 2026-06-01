<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Attributes\Webhook;

class DuplicateWebhookNameController2
{
    #[Webhook(name: 'stripe.payment_intent.succeeded')]
    public function handlePaymentSucceeded2(): void {}
}
