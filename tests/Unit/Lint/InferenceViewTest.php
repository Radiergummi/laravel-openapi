<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Lint\InferenceView;
use Radiergummi\OpenApi\Support\Generator\InferenceOnlyGeneration;

uses()->group('openapi', 'lint');

function inferenceViewFrom(OA\OpenApi $document): InferenceView
{
    return InferenceView::from(new InferenceOnlyGeneration($document, []));
}

it('resolves a control component schema by its component name', function (): void {
    $child = new OA\Schema(['schema' => 'RefChildData', 'type' => 'object']);
    $document = new OA\OpenApi([
        'components' => new OA\Components(['schemas' => [$child]]),
    ]);

    expect(inferenceViewFrom($document)->schemaForName('RefChildData'))->toBe($child);
});

it('returns null for an unknown component name', function (): void {
    $document = new OA\OpenApi([
        'components' => new OA\Components(['schemas' => [
            new OA\Schema(['schema' => 'Known', 'type' => 'object']),
        ]]),
    ]);

    expect(inferenceViewFrom($document)->schemaForName('Unknown'))->toBeNull();
});

it('returns null by name when the document has no components', function (): void {
    expect(inferenceViewFrom(new OA\OpenApi([]))->schemaForName('Anything'))->toBeNull();
});
