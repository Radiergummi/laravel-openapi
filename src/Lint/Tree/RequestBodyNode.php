<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Tree;

use LogicException;
use OpenApi\Annotations as OA;
use Override;

use function in_array;
use function sprintf;

final class RequestBodyNode implements Node
{
    public bool $isMultipart {
        get => in_array('multipart/form-data', $this->contentTypes, true);
    }
    public bool $isJson {
        get => in_array('application/json', $this->contentTypes, true);
    }
    private ?Node $parent = null;

    /**
     * @param list<string>      $contentTypes e.g., ["application/json", "multipart/form-data"]
     * @param list<FieldNode>   $fields       Inline fields (empty if schemaRef is set)
     * @param list<ExampleNode> $examples     Body-level examples (OAS media type examples)
     */
    public function __construct(
        public readonly array $contentTypes,
        public readonly bool $required,
        public readonly array $fields,
        public readonly array $examples,
        public readonly ?string $schemaRef,
        public readonly ?string $description,
        public readonly ?OA\RequestBody $raw,
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
        $base = ($this->parent?->pointer() ?? '') . '/requestBody';

        return $append !== '' ? $base . '/' . $append : $base;
    }
}
