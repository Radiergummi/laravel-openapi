<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\SwaggerPhp;

use Radiergummi\OpenApi\Plugins\SwaggerPhp\Stages\HarvestAuthoredAnnotationsStage;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\SwaggerPhpPlugin;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;

it('registers the harvest stage', function (): void {
    $registry = new OpenApiRegistry();

    (new SwaggerPhpPlugin())->register($registry);

    expect($registry->stages)->toContain(HarvestAuthoredAnnotationsStage::class);
});
