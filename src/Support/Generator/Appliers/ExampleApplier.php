<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator\Appliers;

use InvalidArgumentException;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Attributes\BaseExample as BaseExampleAttribute;
use Radiergummi\OpenApi\Attributes\Example as ExampleAttribute;
use Radiergummi\OpenApi\Attributes\ResponseExample as ResponseExampleAttribute;
use Radiergummi\OpenApi\Attributes\ResponseExampleFile as ResponseExampleFileAttribute;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Generator\ExampleFileLoader;
use RuntimeException;

use function in_array;
use function is_array;

/**
 * Attaches `#[Example]` (request body), `#[ResponseExample]`, and `#[ResponseExampleFile]` payloads
 * to the media types of request and response bodies. Owns example-attribute reading and `OA\Examples`
 * construction; resolves file-based examples through the {@see ExampleFileLoader}.
 *
 * @internal
 */
final readonly class ExampleApplier
{
    public function __construct(
        private ExampleFileLoader $fileLoader,
    ) {}

    /** @throws RuntimeException */
    public function applyRequestExamples(ActionDescriptor $descriptor, ?OA\RequestBody $body): void
    {
        if ($body === null) {
            return;
        }

        $content = $body->content;

        if (!is_array($content) || $content === []) {
            return;
        }

        $instances = [];

        foreach ($descriptor->actionAttributes(ExampleAttribute::class) as $attribute) {
            try {
                $instances[] = $attribute->newInstance();
            } catch (InvalidArgumentException) {
                // Malformed #[Example] attribute; skip and continue generating
            }
        }

        if ($instances === []) {
            return;
        }

        $examples = $this->collectExamples($instances);

        foreach ($content as $media) {
            if ($media instanceof OA\MediaType) {
                $media->examples = $examples;
            }
        }
    }

    /**
     * Attaches named `#[ResponseExample]`s to the matching response's media types by status.
     *
     * @param list<OA\Response> $responses
     *
     * @throws RuntimeException
     */
    public function applyResponseExamples(ActionDescriptor $descriptor, array $responses): void
    {
        $attributes = $descriptor->actionAttributes(ResponseExampleAttribute::class);

        if ($attributes === []) {
            return;
        }

        /** @var array<string, list<ResponseExampleAttribute>> $byStatus */
        $byStatus = [];

        foreach ($attributes as $attribute) {
            try {
                $instance = $attribute->newInstance();
            } catch (InvalidArgumentException) {
                // Malformed #[ResponseExample] attribute; skip and continue generating
                continue;
            }

            $byStatus[(string) $instance->status][] = $instance;
        }

        foreach ($responses as $response) {
            $status = (string) $response->response;

            if (!isset($byStatus[$status])) {
                continue;
            }

            $content = $response->content;

            // An example implies a body; scaffold a media type when the response has none.
            if (!is_array($content) || $content === []) {
                $content = [MediaType::Json->schema()];
                $response->content = $content;
            }

            $examples = $this->collectExamples($byStatus[$status]);

            foreach ($content as $media) {
                if ($media instanceof OA\MediaType) {
                    $media->examples = $examples;
                }
            }
        }
    }

    /**
     * Attaches `#[ResponseExampleFile]` payloads as the singular media-type `example` on a response.
     *
     * `status: null` targets the already-resolved primary response. Files for a status with no
     * matching response are dropped silently; so are files for a conventionally bodyless status
     * (204/205/304) that has no content, since scaffolding a JSON body there is invalid OpenAPI.
     * When a media type already carries named `examples` (e.g. from `#[ResponseExample]`), the
     * singular `example` is left unset: the two are mutually exclusive on one media type.
     *
     * @param list<OA\Response> $responses
     *
     * @throws RuntimeException When a referenced file is missing or not valid JSON.
     */
    public function applyResponseExampleFiles(
        ActionDescriptor $descriptor,
        array $responses,
        OA\Response $primaryResponse,
    ): void {
        $attributes = $descriptor->actionAttributes(ResponseExampleFileAttribute::class);

        if ($attributes === []) {
            return;
        }

        /** @var array<string, OA\Response> $byStatus */
        $byStatus = [];

        foreach ($responses as $response) {
            $byStatus[(string) $response->response] = $response;
        }

        foreach ($attributes as $attribute) {
            $instance = $attribute->newInstance();

            $response = $instance->status !== null
                ? ($byStatus[(string) $instance->status] ?? null)
                : $primaryResponse;

            if ($response === null) {
                continue;
            }

            $content = $response->content;

            if (!is_array($content) || $content === []) {
                // A bodyless status must not gain a JSON body just to carry an example. The set is
                // inlined deliberately: the canonical list is a private Lint-layer detail, and
                // Support must not depend on Lint.
                if (in_array((int) $response->response, [204, 205, 304], true)) {
                    continue;
                }

                $content = [MediaType::Json->schema()];
                $response->content = $content;
            }

            $example = $this->fileLoader->load($instance->file);

            foreach ($content as $media) {
                // Named examples and a singular example are mutually exclusive on one media type.
                if ($media instanceof OA\MediaType && !is_array($media->examples)) {
                    $media->example = $example;
                }
            }
        }
    }

    /**
     * @param list<BaseExampleAttribute> $instances
     *
     * @return list<OA\Examples>
     *
     * @throws RuntimeException
     */
    private function collectExamples(array $instances): array
    {
        $out = [];

        foreach ($instances as $instance) {
            // Resolve file-based examples at generation time.
            $value = $instance->file !== null
                ? $this->fileLoader->load($instance->file)
                : $instance->value;

            $properties = [
                'example' => $instance->name,
                // OA\Examples requires `summary`; fall back to the name.
                'summary' => $instance->summary ?? $instance->name,
                'value' => $value,
            ];

            if ($instance->description !== null) {
                $properties['description'] = $instance->description;
            }

            $out[] = new OA\Examples($properties);
        }

        return $out;
    }
}
