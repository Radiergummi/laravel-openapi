<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\Fix\Fix;
use Radiergummi\OpenApi\Lint\Fix\FixApplicator;
use Radiergummi\OpenApi\Lint\Fix\FixContext;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix\RedundantOaPropertyFixer;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\AuthoredAnnotationShape;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\RedundantMixedAttributeData;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\RedundantPropertyDocblockData;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\RedundantPropertyMixedData;

uses()->group('openapi', 'lint', 'fix');

/**
 * Golden-file harness: copies the fixture source to a throwaway temp file, runs the per-property
 * fixer for a synthetic finding, applies the fixes to the copy (never the checked-in fixture), and
 * returns the resulting source.
 *
 * @param class-string $class
 *
 * @return array{after: string, fixes: int}
 */
function runRedundantPropertyFixer(string $class, string $member, AuthoredAnnotationShape $shape): array
{
    $sourceFile = new ReflectionClass($class)->getFileName() ?: '';
    $before = file_get_contents($sourceFile) ?: '';

    $temp = tempnam(sys_get_temp_dir(), 'openapi-oa-redundant-prop-') ?: '';
    file_put_contents($temp, $before);

    $finding = new Finding(
        ruleId: 'migration.oa-redundant-property-with-inference',
        severity: Severity::Improvable,
        message: 'fixture',
        location: new FindingLocation(file: $sourceFile),
        context: [
            Finding::CONTEXT_SOURCE_CLASS => $class,
            Finding::CONTEXT_SOURCE_MEMBER => $member,
            AuthoredAnnotationShape::FINDING_CONTEXT_KEY => $shape->value,
        ],
    );

    $remapped = [];

    foreach (new RedundantOaPropertyFixer()->fix($finding, new FixContext()) as $fix) {
        $remapped[] = new Fix($temp, $fix->description, $fix->ruleId, $fix->operation);
    }

    new FixApplicator()->apply($remapped);

    $after = file_get_contents($temp) ?: '';
    @unlink($temp);

    return ['after' => $after, 'fixes' => count($remapped)];
}

it('removes only the redundant member attribute, leaving the sibling byte-identical', function (): void {
    $result = runRedundantPropertyFixer(
        RedundantPropertyMixedData::class,
        'name',
        AuthoredAnnotationShape::Attribute,
    );

    expect($result['fixes'])->toBe(1)
        ->and($result['after'])->toBe(<<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

        use OpenApi\Attributes as OA;
        use Spatie\LaravelData\Data;

        // `name` restates the inferred `string` type and is redundant; `role` carries a human-written
        // description inference cannot derive, so only `name` is subsumed by inference.
        #[OA\Schema(schema: 'RedundantPropertyMixed')]
        final class RedundantPropertyMixedData extends Data
        {
            public function __construct(
                public string $name,
                #[OA\Property(property: 'role', type: 'string', description: 'The contact role.')]
                public string $role,
            ) {}
        }

        PHP);
});

it('removes a redundant @OA\Property block from a property docblock', function (): void {
    $result = runRedundantPropertyFixer(
        RedundantPropertyDocblockData::class,
        'name',
        AuthoredAnnotationShape::Docblock,
    );

    expect($result['fixes'])->toBe(1)
        ->and($result['after'])->not->toContain('@OA\Property');
});

it('removes the OA\Property from a mixed attribute group, keeping the non-OA marker', function (): void {
    // RedundantMixedAttributeData stacks a non-OA marker with two OA attributes on one member; the
    // fixer must excise the OA run and keep the marker intact.
    $result = runRedundantPropertyFixer(
        RedundantMixedAttributeData::class,
        'name',
        AuthoredAnnotationShape::Attribute,
    );

    expect($result['fixes'])->toBe(1)
        ->and($result['after'])
        ->toContain('MixedGroupMarker')
        ->not->toContain('OA\Property')
        ->not->toContain('OA\Examples');
});

it('yields nothing when the finding context is incomplete', function (): void {
    $finding = new Finding(
        ruleId: 'migration.oa-redundant-property-with-inference',
        severity: Severity::Improvable,
        message: 'fixture',
    );

    expect(iterator_to_array(new RedundantOaPropertyFixer()->fix($finding, new FixContext())))->toBe([]);
});

it('yields nothing when the source class cannot be reflected', function (): void {
    $finding = new Finding(
        ruleId: 'migration.oa-redundant-property-with-inference',
        severity: Severity::Improvable,
        message: 'fixture',
        context: [
            Finding::CONTEXT_SOURCE_CLASS => 'Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\NoSuchClass',
            Finding::CONTEXT_SOURCE_MEMBER => 'name',
            AuthoredAnnotationShape::FINDING_CONTEXT_KEY => AuthoredAnnotationShape::Attribute->value,
        ],
    );

    expect(iterator_to_array(new RedundantOaPropertyFixer()->fix($finding, new FixContext())))->toBe([]);
});
