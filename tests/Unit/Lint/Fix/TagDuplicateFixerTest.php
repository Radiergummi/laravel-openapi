<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\TagDuplicate;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix\DuplicateTagFixtureController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix\SharedGroupTagFixtureController;
use Radiergummi\OpenApi\Tests\Support\AttributeFixFixture;

uses()->group('openapi', 'lint', 'fix');

it('removes the duplicate #[Tag] attribute and leaves the rest byte-identical', function (): void {
    $result = AttributeFixFixture::run(
        new TagDuplicate(),
        DuplicateTagFixtureController::class,
        'index',
        discriminator: 'users',
    );

    expect($result['fixes'])
        ->toHaveCount(1)
        ->and($result['after'])->toBe(
            <<<'PHP'
                <?php
                
                declare(strict_types=1);
                
                namespace Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix;
                
                use Radiergummi\OpenApi\Attributes\Tag;
                
                class DuplicateTagFixtureController
                {
                    #[Tag('users')]
                    public function index(): void {}
                }
                PHP . "\n",
        );
});

it('removes the duplicate attribute from a shared group, swallowing the comma', function (): void {
    $result = AttributeFixFixture::run(
        new TagDuplicate(),
        SharedGroupTagFixtureController::class,
        'index',
        discriminator: 'users',
    );

    expect($result['fixes'])
        ->toHaveCount(1)
        ->and($result['after'])->toContain("#[Tag('users')]")
        ->and($result['after'])->not->toContain("#[Tag('users'), Tag('users')]");
});
