<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\ResponseDuplicateStatus;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix\DuplicateResponseStatusFixtureController;
use Radiergummi\OpenApi\Tests\Support\AttributeFixFixture;

uses()->group('openapi', 'lint', 'fix');

it('removes the duplicate #[Response] attribute', function (): void {
    $result = AttributeFixFixture::run(
        new ResponseDuplicateStatus(),
        DuplicateResponseStatusFixtureController::class,
        'show',
        discriminator: '200',
    );

    expect($result['fixes'])
        ->toHaveCount(1)
        ->and($result['after'])->toBe(
            <<<'PHP'
                <?php
                
                declare(strict_types=1);
                
                namespace Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix;
                
                use Radiergummi\OpenApi\Attributes\Response;
                
                class DuplicateResponseStatusFixtureController
                {
                    #[Response(status: 200, description: 'OK')]
                    public function show(): void {}
                }
                PHP,
        );
});
