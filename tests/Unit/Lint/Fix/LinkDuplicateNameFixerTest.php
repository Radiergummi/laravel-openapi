<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\LinkDuplicateName;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix\DuplicateLinkNameFixtureController;
use Radiergummi\OpenApi\Tests\Support\AttributeFixFixture;

uses()->group('openapi', 'lint', 'fix');

it('removes the duplicate #[Link] attribute', function (): void {
    $result = AttributeFixFixture::run(
        new LinkDuplicateName(),
        DuplicateLinkNameFixtureController::class,
        'show',
        discriminator: 'self',
    );

    expect($result['fixes'])->toHaveCount(1)
        ->and($result['after'])->toBe(<<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix;

        use Radiergummi\OpenApi\Attributes\Link;

        class DuplicateLinkNameFixtureController
        {
            #[Link(name: 'self', operationId: 'self.show')]
            public function show(): void {}
        }

        PHP);
});
