<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Generator;

use Illuminate\Contracts\Support\Arrayable;
use OpenApi\Annotations as OA;

use function array_filter;
use function in_array;
use function strtoupper;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class OperationDescriptor implements Arrayable
{
    /**
     * @param list<string>                      $tags
     * @param list<OA\Parameter>                $parameters
     * @param list<array<string, list<string>>> $security
     * @param list<OA\Response>                 $responses
     */
    public function __construct(
        public ?string $summary,
        public ?string $description,
        public array $tags,
        public array $parameters,
        public array $security,
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

    public function toOpenApi(string $method): ?OA\Operation
    {
        $method = strtoupper($method);

        // swagger-php serialises explicit nulls verbatim, and OpenAPI 3.1
        // rejects null on optional fields (externalDocs, summary, …). Drop
        // unset optionals so they are omitted rather than emitted as null.
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
            'GET' => new OA\Get($props),
            'POST' => new OA\Post($props),
            'PUT' => new OA\Put($props),
            'PATCH' => new OA\Patch($props),
            'DELETE' => new OA\Delete($props),
            'OPTIONS' => new OA\Options($props),
            default => null,
        };
    }

    private function shouldAttachBody(string $method): bool
    {
        return
            $this->requestBody !== null
            && in_array($method, ['POST', 'PUT', 'PATCH'], strict: true)
        ;
    }
}
