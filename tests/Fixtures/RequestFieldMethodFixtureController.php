<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Attributes\RequestBody;
use Radiergummi\OpenApi\Attributes\RequestField;
use Radiergummi\OpenApi\Tests\Fixtures\Discriminator\CircleData;

/**
 * Test fixture — a controller that validates outside a FormRequest/Data class and documents its
 * request body with method-level `#[RequestField]` attributes (composing with `#[RequestBody]`).
 *
 * Routes are wired up in {@see \Radiergummi\OpenApi\Tests\Feature\RequestFieldMethodLevelTest}.
 */
class RequestFieldMethodFixtureController extends Controller
{
    #[RequestBody(description: 'Create a site.')]
    #[RequestField('domain', required: true, type: 'string', format: 'hostname')]
    #[RequestField('php_version', type: 'string', default: '8.4')]
    #[RequestField('aliases', type: 'array', items: 'string')]
    public function store(Request $request): array
    {
        return [];
    }

    /** A class-string `type:` resolves to a `$ref`; a class-string `items:` to `items: { $ref }`. */
    #[RequestBody(description: 'Field-level refs.')]
    #[RequestField('owner', type: CircleData::class)]
    #[RequestField('shapes', type: 'array', items: CircleData::class)]
    public function storeWithRef(Request $request): array
    {
        return [];
    }
}
