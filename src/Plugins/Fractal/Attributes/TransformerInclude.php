<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Attributes;

use Attribute;

/**
 * Declares one Fractal include: an `availableIncludes` entry, or a
 * `defaultIncludes` entry when `default` is true. Repeatable, class-level on
 * the transformer.
 *
 * ```php
 * #[TransformerInclude('author', transformer: AuthorTransformer::class, default: true)]
 * #[TransformerInclude('comments', transformer: CommentTransformer::class)]
 * final class BookTransformer extends TransformerAbstract { … }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class TransformerInclude
{
    /**
     * @param string            $name        The include name (the response key it adds).
     * @param null|class-string $transformer The transformer producing the included resource's schema.
     * @param bool              $default     True for a `defaultIncludes` entry (present unless excluded).
     */
    public function __construct(
        public string $name,
        public ?string $transformer = null,
        public bool $default = false,
    ) {}
}
