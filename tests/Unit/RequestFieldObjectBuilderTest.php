<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit;

use Radiergummi\OpenApi\Attributes\RequestField;
use Radiergummi\OpenApi\Plugins\Core\Support\RequestFieldObjectBuilder;

uses()->group('openapi');

it('builds properties and collects required names', function (): void {
    [$properties, $required] = RequestFieldObjectBuilder::propertiesAndRequired([
        new RequestField('domain', required: true, type: 'string'),
        new RequestField('php_version', type: 'string'),
    ]);

    expect($properties)->toHaveCount(2)
        ->and($properties[0]->property)->toBe('domain')
        ->and($properties[1]->property)->toBe('php_version')
        ->and($required)->toBe(['domain']);
});

it('skips a field with no name', function (): void {
    [$properties, $required] = RequestFieldObjectBuilder::propertiesAndRequired([
        new RequestField(null, required: true),
        new RequestField('keep'),
    ]);

    expect($properties)->toHaveCount(1)
        ->and($properties[0]->property)->toBe('keep')
        ->and($required)->toBe([]);
});
