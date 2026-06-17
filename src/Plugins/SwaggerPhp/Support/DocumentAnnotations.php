<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Support;

use OpenApi\Annotations as OA;

/**
 * The document-level swagger-php annotations an application actually authored: only those mapping
 * to a `config/openapi.php` key the generator reads ({@see ConfigSnippetRenderer}). Operation- and
 * schema-level annotations live elsewhere on {@see AuthoredAnnotationScanner}.
 *
 * @internal
 */
final readonly class DocumentAnnotations
{
    /**
     * @param list<OA\Server>                  $servers
     * @param array<string, OA\SecurityScheme> $securitySchemes keyed by scheme name
     * @param list<OA\Tag>                     $rootTags
     */
    public function __construct(
        public ?OA\Info $info = null,
        public array $servers = [],
        public array $securitySchemes = [],
        public array $rootTags = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->info === null
            && $this->servers === []
            && $this->securitySchemes === []
            && $this->rootTags === [];
    }
}
