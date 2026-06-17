<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\Fix\Fix;
use Radiergummi\OpenApi\Lint\Fix\FixApplicator;
use Radiergummi\OpenApi\Lint\Fix\FixContext;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix\RedundantOaAnnotationFixer;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\AuthoredAnnotationShape;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\RedundantAttributeData;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\RedundantBareSchemaDocblockData;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\RedundantDocblockData;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\RedundantDocblockWithProseData;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\RedundantInlineData;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\RedundantMixedAttributeData;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\RedundantPlainPropertyData;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\RedundantTrailingMarkerData;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\ServerController;

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
function runRedundantOaFixer(string $class, AuthoredAnnotationShape $shape): array
{
    $sourceFile = new ReflectionClass($class)->getFileName() ?: '';
    $before = file_get_contents($sourceFile) ?: '';

    $temp = tempnam(sys_get_temp_dir(), 'openapi-oa-fix-') ?: '';
    file_put_contents($temp, $before);

    $finding = new Finding(
        ruleId: 'migration.oa-redundant-with-inference',
        severity: Severity::Improvable,
        message: 'fixture',
        location: new FindingLocation(file: $sourceFile),
        context: [
            Finding::CONTEXT_SOURCE_CLASS => $class,
            AuthoredAnnotationShape::FINDING_CONTEXT_KEY => $shape->value,
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
    $result = runRedundantOaFixer(RedundantAttributeData::class, AuthoredAnnotationShape::Attribute);

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
    $result = runRedundantOaFixer(RedundantMixedAttributeData::class, AuthoredAnnotationShape::Attribute);

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
    $result = runRedundantOaFixer(RedundantDocblockData::class, AuthoredAnnotationShape::Docblock);

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

it('removes an OA attribute from a declared property on a constructor-less class', function (): void {
    // Exercises property attribute groups (not just promoted params) and the no-constructor path.
    $result = runRedundantOaFixer(RedundantPlainPropertyData::class, AuthoredAnnotationShape::Attribute);

    expect($result['fixes'])->toBe(2)
        ->and($result['after'])
        ->not->toContain('OA\Schema')
        ->not->toContain('OA\Property')
        ->toContain('public string $name');
});

it('excises an OA run that precedes a surviving attribute, and skips OA-free groups', function (): void {
    // `name`: #[OA\Property, Marker] — OA run before the tail; `count`: #[Marker] — no OA to excise.
    $result = runRedundantOaFixer(RedundantTrailingMarkerData::class, AuthoredAnnotationShape::Attribute);

    expect($result['fixes'])->toBe(2) // class #[OA\Schema] + the name property's #[OA\Property]
        ->and($result['after'])
        ->not->toContain('OA\Property')
        ->not->toContain('OA\Schema')
        ->toContain('#[MixedGroupMarker]')
        ->toContain('public int $count');
});

it('excises an inline (not whole-line) all-OA attribute group byte-precisely', function (): void {
    $result = runRedundantOaFixer(RedundantInlineData::class, AuthoredAnnotationShape::Attribute);

    expect($result['fixes'])->toBe(2)
        ->and($result['after'])
        ->not->toContain('OA\Property')
        ->not->toContain('OA\Schema')
        ->toContain('public string $name');
});

it('removes only the annotation block from a docblock that also holds prose', function (): void {
    $result = runRedundantOaFixer(RedundantDocblockWithProseData::class, AuthoredAnnotationShape::Docblock);

    expect($result['fixes'])->toBe(1)
        ->and($result['after'])
        ->not->toContain('@OA\Schema')
        ->not->toContain('@OA\Property')
        ->toContain('This prose must survive the fix.');
});

it('removes a parenthesis-less bare @OA\Schema docblock', function (): void {
    $result = runRedundantOaFixer(RedundantBareSchemaDocblockData::class, AuthoredAnnotationShape::Docblock);

    expect($result['fixes'])->toBe(1)
        ->and($result['after'])->not->toContain('@OA\Schema');
});

it('yields nothing when the finding context lacks the source class or shape', function (): void {
    $finding = new Finding(
        ruleId: 'migration.oa-redundant-with-inference',
        severity: Severity::Improvable,
        message: 'fixture',
    );

    expect(iterator_to_array(new RedundantOaAnnotationFixer()->fix($finding, new FixContext())))->toBe([]);
});

it('yields nothing when the source class cannot be reflected', function (): void {
    $finding = new Finding(
        ruleId: 'migration.oa-redundant-with-inference',
        severity: Severity::Improvable,
        message: 'fixture',
        context: [
            Finding::CONTEXT_SOURCE_CLASS => 'Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\NoSuchClass',
            AuthoredAnnotationShape::FINDING_CONTEXT_KEY => AuthoredAnnotationShape::Attribute->value,
        ],
    );

    expect(iterator_to_array(new RedundantOaAnnotationFixer()->fix($finding, new FixContext())))->toBe([]);
});

it('yields nothing for a docblock-shape finding on a class without a docblock', function (): void {
    // RedundantAttributeData carries attributes, not a docblock — the docblock remover finds nothing.
    $result = runRedundantOaFixer(RedundantAttributeData::class, AuthoredAnnotationShape::Docblock);

    expect($result['fixes'])->toBe(0);
});

it('yields nothing for a docblock-shape finding whose docblock has no @OA annotation', function (): void {
    // ServerController has a plain docblock with no @OA — the annotation block is never located.
    $result = runRedundantOaFixer(ServerController::class, AuthoredAnnotationShape::Docblock);

    expect($result['fixes'])->toBe(0);
});
