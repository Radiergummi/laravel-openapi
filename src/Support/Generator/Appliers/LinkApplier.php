<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator\Appliers;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Attributes\Link as LinkAttribute;
use Radiergummi\OpenApi\Routing\ActionDescriptor;

use function assert;
use function is_array;

/**
 * Attaches `#[Link]` attributes to a primary response as OpenAPI links. Links are per-operation,
 * not per-controller.
 *
 * @internal
 */
final readonly class LinkApplier
{
    public function apply(ActionDescriptor $descriptor, OA\Response $primaryResponse): void
    {
        $attrs = $descriptor->actionAttributes(LinkAttribute::class);

        if ($attrs === []) {
            return;
        }

        $links = is_array($primaryResponse->links) ? $primaryResponse->links : [];

        foreach ($attrs as $attribute) {
            $instance = $attribute->newInstance();
            assert($instance instanceof LinkAttribute);

            $props = ['link' => $instance->name];

            if ($instance->operationId !== null) {
                $props['operationId'] = $instance->operationId;
            }

            if ($instance->operationRef !== null) {
                $props['operationRef'] = $instance->operationRef;
            }

            if ($instance->parameters !== []) {
                $props['parameters'] = $instance->parameters;
            }

            if ($instance->description !== null) {
                $props['description'] = $instance->description;
            }

            $links[] = new OA\Link($props);
        }

        $primaryResponse->links = $links;
    }
}
