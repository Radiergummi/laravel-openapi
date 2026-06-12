<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\ApiResources;

use Radiergummi\OpenApi\Routing\ResourceTarget;
use stdClass;

it('reports a resolved single-resource target', function (): void {
    $target = new ResourceTarget(stdClass::class, isCollection: false);

    expect($target->resourceClass)->toBe(stdClass::class)
        ->and($target->isCollection)->toBeFalse()
        ->and($target->isAmbiguous)->toBeFalse();
});

it('reports an ambiguous target when the resource class is null', function (): void {
    $target = new ResourceTarget(null, isCollection: true);

    expect($target->isAmbiguous)->toBeTrue();
});
