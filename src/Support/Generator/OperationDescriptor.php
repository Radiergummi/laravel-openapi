<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use Illuminate\Contracts\Support\Arrayable;
use OpenApi\Annotations as OA;
use Override;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Support\Provenance\FieldProvenance;

use function array_filter;
use function in_array;

/**
 * @implements Arrayable<string, mixed>
 *
 * @internal
 */
final readonly class OperationDescriptor implements Arrayable
{
    /**
     * @param list<string>                           $tags
     * @param list<OA\Parameter>                     $parameters
     * @param null|list<array<string, list<string>>> $security   `null` omits the field entirely
     *                                                           (authed route, no derivable scheme);
     *                                                           `[]` is the explicit public signal
     * @param list<OA\Response>                      $responses
     * @param list<FieldProvenance>                  $provenance Source/reason per derived field;
     *                                                           a read-only side channel for
     *                                                           `openapi:why --fields` that never
     *                                                           reaches the document.
     */
    public function __construct(
        public ?string $summary,
        public ?string $description,
        public array $tags,
        public array $parameters,
        public ?array $security,
        public array $responses,
        public ?OA\RequestBody $requestBody,
        public bool $deprecated,
        public ?string $operationId,
        public ?OA\ExternalDocumentation $externalDocs,
        public array $provenance = [],
    ) {}

    public function withOperationId(string $operationId): self
    {
        return new self(
            summary: $this->summary,
            description: $this->description,
            tags: $this->tags,
            parameters: $this->parameters,
            security: $this->security,
            responses: $this->responses,
            requestBody: $this->requestBody,
            deprecated: $this->deprecated,
            operationId: $operationId,
            externalDocs: $this->externalDocs,
            provenance: $this->provenance,
        );
    }

    /**
     * Builds the method-specific {@see OA\Operation}, assigns it to `$pathItem`, and returns it.
     * Returns null for verbs without a path-item slot (HEAD, TRACE, etc.).
     */
    public function attachTo(OA\PathItem $pathItem, HttpMethod $method): ?OA\Operation
    {
        // OpenAPI 3.1 rejects null on optional fields; drop them so swagger-php omits rather than
        // emits null.
        $props = array_filter(
            $this->toArray(),
            static fn(mixed $value): bool => $value !== null,
        );

        if ($this->deprecated === false) {
            unset($props['deprecated']);
        }

        if (!$this->shouldAttachBody($method)) {
            unset($props['requestBody']);
        }

        return match ($method) {
            HttpMethod::Get => $pathItem->get = new OA\Get($props),
            HttpMethod::Post => $pathItem->post = new OA\Post($props),
            HttpMethod::Put => $pathItem->put = new OA\Put($props),
            HttpMethod::Patch => $pathItem->patch = new OA\Patch($props),
            HttpMethod::Delete => $pathItem->delete = new OA\Delete($props),
            HttpMethod::Options => $pathItem->options = new OA\Options($props),
            default => null,
        };
    }

    #[Override]
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'description' => $this->description,
            'tags' => $this->tags,
            'parameters' => $this->parameters,
            'security' => $this->security,
            'responses' => $this->responses,
            'requestBody' => $this->requestBody,
            'deprecated' => $this->deprecated,
            'operationId' => $this->operationId,
            'externalDocs' => $this->externalDocs,
        ];
    }

    private function shouldAttachBody(HttpMethod $method): bool
    {
        return
            $this->requestBody !== null
            && in_array($method, [
                HttpMethod::Post,
                HttpMethod::Put,
                HttpMethod::Patch,
            ], strict: true);
    }
}
