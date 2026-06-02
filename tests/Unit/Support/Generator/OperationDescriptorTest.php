<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Support\Generator\OperationDescriptor;

use function Radiergummi\OpenApi\is_undefined;

uses()->group('openapi');

function descriptorWithSecurity(?array $security): OperationDescriptor
{
    return new OperationDescriptor(
        summary: null,
        description: null,
        tags: [],
        parameters: [],
        security: $security,
        responses: [],
        requestBody: null,
        deprecated: false,
        operationId: null,
        externalDocs: null,
    );
}

it('omits the security field when security is null (authed route, no derivable scheme)', function (): void {
    $pathItem  = new OA\PathItem(['_context' => new Context()]);
    $operation = descriptorWithSecurity(null)->attachTo($pathItem, HttpMethod::Get);

    expect(is_undefined($operation->security))->toBeTrue();
});

it('emits an explicit empty security array (the OpenAPI public signal) when security is []', function (): void {
    $pathItem  = new OA\PathItem(['_context' => new Context()]);
    $operation = descriptorWithSecurity([])->attachTo($pathItem, HttpMethod::Get);

    expect($operation->security)->toBe([]);
});
