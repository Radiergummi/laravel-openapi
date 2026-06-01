<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Tree;

use OpenApi\Annotations as OA;

final readonly class ApiNode implements Node
{
    /**
     * @param list<OperationNode>       $operations
     * @param list<ComponentSchemaNode> $components
     * @param list<WebhookNode>         $webhooks
     * @param list<string>              $declaredTags    Root-level tag names
     * @param array<string, string>     $tagDescriptions Tag name → description
     */
    public function __construct(
        public array $operations,
        public array $components,
        public array $webhooks,
        public array $declaredTags,
        public array $tagDescriptions,
        public OA\OpenApi $raw,
    ) {}

    public function pointer(string $append = ''): string
    {
        return $append !== '' ? '#/' . $append : '#';
    }

    public function parent(): ?Node
    {
        return null;
    }
}
