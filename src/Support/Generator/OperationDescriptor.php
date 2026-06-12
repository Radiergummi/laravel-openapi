<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use Illuminate\Contracts\Support\Arrayable;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Enums\HttpMethod;

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
        );
    }

    /**
     * Builds the method-specific {@see OA\Operation} and assigns it to the matching `$pathItem`
     * property, returning the same instance (or null for verbs without a path-item slot, e.g.
     * HEAD/TRACE). Assigning inside the match keeps the concrete operation type flowing into the
     * concrete property, so the method → operation-class → property-name mapping lives in one place.
     */
    public function attachTo(OA\PathItem $pathItem, HttpMethod $method): ?OA\Operation
    {
        // swagger-php serialises explicit nulls verbatim, and OpenAPI 3.1 rejects null on optional
        // fields (externalDocs, summary, …). Drop unset optionals so they are omitted rather than
        // emitted as null.
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
