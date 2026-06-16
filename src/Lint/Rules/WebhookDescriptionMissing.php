<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\WebhookNode;
use Radiergummi\OpenApi\Lint\Visitors\WebhookRule as WebhookRuleVisitor;

use function sprintf;
use function trim;

/**
 * Reports webhook operations missing a description.
 *
 * Webhook consumers receive unexpected incoming requests; a description explaining when and why
 * the webhook fires is essential context for them.
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
