<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Attributes\RequestBody;
use Radiergummi\OpenApi\Attributes\RequestField;
use Radiergummi\OpenApi\Attributes\RequestVariant;
use Radiergummi\OpenApi\Tests\Fixtures\Discriminator\CircleData;

/**
 * Fixture — discriminated request bodies. Routes are wired in
 * {@see \Radiergummi\OpenApi\Tests\Feature\DiscriminatedRequestBodyTest}.
 */
class DiscriminatedRequestFixtureController extends Controller
{
    #[RequestBody(discriminator: 'provider')]
    #[RequestVariant('aws', fields: [new RequestField('region', required: true), new RequestField('access_key', required: true)])]
    #[RequestVariant('hetzner', fields: [new RequestField('api_token', required: true)])]
    public function inline(Request $request): array
    {
        return [];
    }

    #[RequestBody(discriminator: 'provider')]
    #[RequestVariant('aws', fields: [new RequestField('region', required: true)])]
    #[RequestVariant('custom', schema: CircleData::class)]
    public function mixed(Request $request): array
    {
        return [];
    }

    #[RequestBody(discriminator: 'provider')]
    #[RequestVariant('a-b', fields: [new RequestField('host', required: true)])]
    #[RequestVariant('a_b', fields: [new RequestField('port', required: true)])]
    public function colliding(Request $request): array
    {
        return [];
    }

    #[RequestBody(discriminator: 'type')]
    #[RequestVariant('a', fields: [new RequestField('only_a', required: true)])]
    #[RequestVariant('b', fields: [new RequestField('type', description: 'Authored discriminator.'), new RequestField('only_b')])]
    public function injection(Request $request): array
    {
        return [];
    }

    #[RequestBody(discriminator: 'provider')]
    #[RequestVariant('aws')] // malformed: neither schema nor fields
    public function malformed(Request $request): array
    {
        return [];
    }

    #[RequestBody(discriminator: 'provider')]
    #[RequestVariant('aws', fields: [new RequestField('a')])]
    #[RequestVariant('aws', fields: [new RequestField('b')])] // duplicate value
    public function duplicate(Request $request): array
    {
        return [];
    }

    #[RequestBody(discriminator: 'provider')]
    #[RequestVariant('weird', schema: DateTimeImmutable::class)] // not resolvable by any ref resolver
    public function unresolvable(Request $request): array
    {
        return [];
    }
}
