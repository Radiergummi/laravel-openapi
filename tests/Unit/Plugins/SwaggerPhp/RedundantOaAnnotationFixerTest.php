<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\Fix\Fix;
use Radiergummi\OpenApi\Lint\Fix\FixApplicator;
use Radiergummi\OpenApi\Lint\Fix\FixContext;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix\RedundantOaAnnotationFixer;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\OaRedundantWithInference;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\AuthoredSchemaShape;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\RedundantAttributeData;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\RedundantDocblockData;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\RedundantMixedAttributeData;

uses()->group('openapi', 'lint', 'fix');

/**
 * Golden-file harness: copies the real fixture source to a throwaway temp file, runs the fixer
 * against a synthetic finding for `$class`, applies the fixes to the copy (never the checked-in
 * fixture), and returns the before/after source.
 *
 * @param class-string $class
 *
 * @return array{after: string, fixes: int}
 */
function runRedundantOaFixer(string $class, AuthoredSchemaShape $shape): array
{
    $sourceFile = new ReflectionClass($class)->getFileName() ?: '';
    $before = file_get_contents($sourceFile) ?: '';

    $temp = tempnam(sys_get_temp_dir(), 'openapi-oa-fix-') ?: '';
    file_put_contents($temp, $before);

    $finding = new Finding(
        ruleId: 'migration.oa-redundant-with-inference',
        level: 4,
        message: 'fixture',
        location: new FindingLocation(file: $sourceFile),
        context: [
            Finding::CONTEXT_SOURCE_CLASS => $class,
            OaRedundantWithInference::CONTEXT_SHAPE => $shape->value,
        ],
    );

    $remapped = [];

    foreach (new RedundantOaAnnotationFixer()->fix($finding, new FixContext()) as $fix) {
        $remapped[] = new Fix($temp, $fix->description, $fix->ruleId, $fix->operation);
    }

    new FixApplicator()->apply($remapped);

    $after = file_get_contents($temp) ?: '';
    @unlink($temp);

    return ['after' => $after, 'fixes' => count($remapped)];
}

it('removes the #[OA\Schema] and #[OA\Property] attributes from a Data class', function (): void {
    $result = runRedundantOaFixer(RedundantAttributeData::class, AuthoredSchemaShape::Attribute);

    expect($result['fixes'])->toBe(3)
        ->and($result['after'])->toBe(<<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

        use OpenApi\Attributes as OA;
        use Spatie\LaravelData\Data;

        // Line comment (not a docblock) so swagger-php does not lift it into the schema description.
        final class RedundantAttributeData extends Data
        {
            public function __construct(
                public string $name,
                public int $count,
            ) {}
        }

        PHP);
});

it('excises every OA attribute from a mixed group, keeping the non-OA one', function (): void {
    $result = runRedundantOaFixer(RedundantMixedAttributeData::class, AuthoredSchemaShape::Attribute);

    expect($result['fixes'])->toBe(2)
        ->and($result['after'])->toBe(<<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

        use Attribute;
        use OpenApi\Attributes as OA;
        use Spatie\LaravelData\Data;

        #[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
        final class MixedGroupMarker {}

        // A property whose attribute group mixes a non-OA attribute with *two* OA attributes, so the fixer
        // must excise the whole OA run in one pass (there is no re-lint pass to catch leftovers).
        final class RedundantMixedAttributeData extends Data
        {
            public function __construct(
                #[MixedGroupMarker]
                public string $name,
            ) {}
        }

        PHP);
});

it('removes the whole @OA\Schema docblock from a Data class', function (): void {
    $result = runRedundantOaFixer(RedundantDocblockData::class, AuthoredSchemaShape::Docblock);

    expect($result['fixes'])->toBe(1)
        ->and($result['after'])->toBe(<<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

        use OpenApi\Annotations as OA;
        use Spatie\LaravelData\Data;

        final class RedundantDocblockData extends Data
        {
            public function __construct(
                public string $name,
                public int $count,
            ) {}
        }

        PHP);
});
