<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\FindingLocation;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\WebhookRule as WebhookRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\WebhookNode;

use function sprintf;
use function trim;

/**
 * Reports webhook operations that have no description.
 *
 * Webhook consumers need more context than REST consumers because they receive
 * unexpected incoming requests. Each webhook operation should include a
 * description explaining when and why the webhook fires.
 */
final class WebhookDescriptionMissing implements Rule, WebhookRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkWebhook(WebhookNode $webhook, LintContext $context): iterable
    {
        $description = $webhook->description;

        if ($description !== null && trim($description) !== '') {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf('Webhook "%s" has no description', $webhook->name),
            location: new FindingLocation(jsonPointer: $webhook->pointer()),
            fixHint: 'Add a description explaining when and why this webhook fires.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'webhook.description-missing';
    }

    #[Override]
    public function level(): int
    {
        return 2;
    }

    #[Override]
    public function description(): string
    {
        return 'Webhook operation has no description.';
    }
}
