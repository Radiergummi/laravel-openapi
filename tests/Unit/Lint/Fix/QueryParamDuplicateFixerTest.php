<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\QueryParamDuplicate;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix\DuplicateQueryParamFixtureController;
use Radiergummi\OpenApi\Tests\Support\AttributeFixFixture;

uses()->group('openapi', 'lint', 'fix');

it('removes the duplicate #[QueryParam] attribute', function (): void {
    $result = AttributeFixFixture::run(
        new QueryParamDuplicate(),
        DuplicateQueryParamFixtureController::class,
        'index',
        discriminator: 'q',
    );

    expect($result['fixes'])
        ->toHaveCount(1)
        ->and($result['after'])->toBe(
            <<<'PHP'
                <?php
                
                declare(strict_types=1);
                
                namespace Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix;
                
                use Radiergummi\OpenApi\Attributes\QueryParam;
                
                class DuplicateQueryParamFixtureController
                {
                    #[QueryParam(name: 'q')]
                    public function index(): void {}
                }
                PHP . "\n",
        );
});
