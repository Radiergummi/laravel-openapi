<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use Attribute;

/**
 * Documents an HTTP response header. Repeatable; `status` scopes the header to a matching
 * response (dropped silently if none). Class-level placement applies to every action, useful
 * for shared headers like `X-Request-Id`. Method-level wins on `(status, name)` collision.
 * For request headers, use {@see Header}.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class ResponseHeader
{
    /**
     * @param non-empty-string      $name
     * @param HttpStatusCode        $status
     * @param null|non-empty-string $description
     * @param OpenApiPrimitiveType  $type
     * @param null|non-empty-string $format
     */
    public function __construct(
        public string $name,
        public int $status = 200,
        public ?string $description = null,
        public string $type = 'string',
        public ?string $format = null,
        public mixed $example = null,
        public ?bool $required = null,
        public ?bool $deprecated = null,
    ) {}
}
