<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Tree;

use LogicException;
use OpenApi\Annotations as OA;
use Override;

use function sprintf;
use function str_starts_with;

final class ResponseNode implements Node
{
    private ?Node $parent = null;

    /**
     * @param list<FieldNode>   $fields   Inline fields (empty if schemaRef is set)
     * @param list<ExampleNode> $examples Body-level examples (OAS media type examples)
     * @param list<HeaderNode>  $headers
     * @param list<LinkNode>    $links
     */
    public function __construct(
        public readonly int|string $statusCode,
        public readonly ?string $description,
        public readonly array $fields,
        public readonly array $examples,
        public readonly ?string $schemaRef,
        public readonly array $headers,
        public readonly array $links,
        public readonly ?OA\Response $raw,
    ) {}

    /**
     * @throws LogicException if called more than once.
     *
     * @internal Called exactly once by SpecTreeBuilder.
     */
    public function linkParent(Node $parent): void
    {
        if ($this->parent !== null) {
            throw new LogicException(
                sprintf('Parent already linked on %s', __CLASS__),
            );
        }

        $this->parent = $parent;
    }

    #[Override]
    public function parent(): ?Node
    {
        return $this->parent;
    }

    #[Override]
    public function pointer(string $append = ''): string
    {
        $base = ($this->parent?->pointer() ?? '') . '/responses/' . $this->statusCode;

        return $append !== '' ? $base . '/' . $append : $base;
    }

    public bool $isSuccess {
        get => str_starts_with((string) $this->statusCode, '2');
    }

    public bool $isError {
        get {
            $status = (string) $this->statusCode;

            return str_starts_with($status, '4') || str_starts_with($status, '5');
        }
    }

    public bool $isRedirect {
        get => str_starts_with((string) $this->statusCode, '3');
    }

    public bool $isDefault {
        get => (string) $this->statusCode === 'default';
    }

    /** The enclosing operation. */
    public function operation(): ?OperationNode
    {
        return $this->parent instanceof OperationNode ? $this->parent : null;
    }
}
