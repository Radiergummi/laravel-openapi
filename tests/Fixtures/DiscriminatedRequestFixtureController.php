<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Attributes\RequestBody;
use Radiergummi\OpenApi\Attributes\RequestField;
use Radiergummi\OpenApi\Attributes\RequestVariant;

/**
 * Fixture — discriminated request bodies. Routes are wired in
 * {@see \Radiergummi\OpenApi\Tests\Feature\DiscriminatedRequestBodyTest}.
 */
class DiscriminatedRequestFixtureController extends Controller
{
    #[RequestBody(discriminator: 'provider')]
    #[RequestVariant('aws', null, new RequestField('region', required: true), new RequestField('access_key', required: true))]
    #[RequestVariant('hetzner', null, new RequestField('api_token', required: true))]
    public function inline(Request $request): array
    {
        return [];
    }
}
