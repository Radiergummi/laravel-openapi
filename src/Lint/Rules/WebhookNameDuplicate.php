<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\WebhookNode;
use Radiergummi\OpenApi\Lint\Visitors\Finalizable;
use Radiergummi\OpenApi\Lint\Visitors\Resettable;
use Radiergummi\OpenApi\Lint\Visitors\WebhookRule as WebhookRuleVisitor;

use function count;
use function sprintf;

/**
 * Reports when two or more webhooks across the entire spec share the same name. Webhook names
 * must be globally unique because they become top-level map keys under `webhooks` in the
 * generated spec.
 */
final class WebhookNameDuplicate implements Rule, WebhookRuleVisitor, Finalizable, Resettable
{
    /**
     * @var array<string, list<WebhookNode>>
     */
    private array $seen = [];

    #[Override]
    public function reset(): void
    {
        $this->seen = [];
    }

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkWebhook(WebhookNode $webhook, LintContext $context): iterable
    {
        $this->seen[$webhook->name][] = $webhook;

        return [];
    }

    /** @return iterable<Finding> */
    #[Override]
    public function finalize(LintContext $context): iterable
    {
        foreach ($this->seen as $name => $nodes) {
            if (count($nodes) < 2) {
                continue;
            }

            foreach ($nodes as $node) {
                yield new Finding(
                    ruleId: $this->id(),
                    level: $this->level(),
                    message: sprintf(
                        'Webhook name "%s" is used %d times across the spec; names must be globally unique',
                        $name,
                        count($nodes),
                    ),
                    location: new FindingLocation(jsonPointer: $node->pointer()),
                    fixHint: 'Use a unique name for each webhook.',
                );
            }
        }
    }

    #[Override]
    public function id(): string
    {
        return 'webhook.name-duplicate';
    }

    #[Override]
    public function level(): int
    {
        return 0;
    }

    #[Override]
    public function description(): string
    {
        return 'Two webhooks share the same name.';
    }
}
