<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Attributes\Webhook;

class OverridesWebhookController
{
    #[Webhook(name: 'payment.received')]
    public function handlePaymentReceived(): void {}
}
