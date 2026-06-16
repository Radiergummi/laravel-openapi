<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Errors;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver;
use Radiergummi\OpenApi\Support\Generator\Stages\ErrorResponseInferenceStage;

/**
 * The body slice of an error response, produced by an {@see ErrorResponseResolver}.
 *
 * Carries media-type contents, headers, links, and an optional description override.
 * The response key and default description are owned by {@see ErrorResponseInferenceStage}.
 */
final readonly class ErrorResponse
{
    /**
     * @param list<OA\MediaType> $content
     * @param list<OA\Header>    $headers
     * @param list<OA\Link>      $links
     */
    public function __construct(
        public array $content = [],
        public array $headers = [],
        public array $links = [],
        public ?string $description = null,
    ) {}

    public static function bodyless(): self
    {
        return new self();
    }
}
