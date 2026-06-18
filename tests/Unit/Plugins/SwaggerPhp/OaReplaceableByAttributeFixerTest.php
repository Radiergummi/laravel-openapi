<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Attributes\QueryParam;
use Radiergummi\OpenApi\Attributes\ResponseField;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\Fix\Fix;
use Radiergummi\OpenApi\Lint\Fix\FixApplicator;
use Radiergummi\OpenApi\Lint\Fix\FixContext;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix\OaReplaceableByAttributeFixer;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\OaReplaceableByAttribute;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\AuthoredAnnotationShape;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\ReplaceableAttributeData;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\ReplaceableDocblockData;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\ReplaceableDocblockWithProseData;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\ReplaceableQueryController;

uses()->group('openapi', 'lint', 'fix');

/**
 * Golden-file harness: copies the fixture source to a throwaway temp file, runs the fixer for a
 * synthetic finding, applies the fixes to the copy (never the checked-in fixture), and returns the
 * resulting source.
 *
 * @param class-string                         $class
 * @param array<string, bool|float|int|string> $arguments
 *
 * @return array{after: string, fixes: int}
 */
function runReplaceableFixer(
    string $class,
    string $member,
    AuthoredAnnotationShape $shape,
    string $attribute,
    array $arguments,
): array {
    $sourceFile = new ReflectionClass($class)->getFileName() ?: '';
    $before = file_get_contents($sourceFile) ?: '';

    $temp = tempnam(sys_get_temp_dir(), 'openapi-oa-replace-') ?: '';
    file_put_contents($temp, $before);

    $finding = new Finding(
        ruleId: 'migration.oa-replaceable-by-attribute',
        severity: Severity::Improvable,
        message: 'fixture',
        location: new FindingLocation(file: $sourceFile),
        context: [
            Finding::CONTEXT_SOURCE_CLASS => $class,
            Finding::CONTEXT_SOURCE_MEMBER => $member,
            AuthoredAnnotationShape::FINDING_CONTEXT_KEY => $shape->value,
            OaReplaceableByAttribute::CONTEXT_TARGET_ATTRIBUTE => $attribute,
            OaReplaceableByAttribute::CONTEXT_ATTRIBUTE_ARGUMENTS => $arguments,
        ],
    );

    $remapped = [];

    foreach (new OaReplaceableByAttributeFixer()->fix($finding, new FixContext()) as $fix) {
        $remapped[] = new Fix($temp, $fix->description, $fix->ruleId, $fix->operation);
    }

    new FixApplicator()->apply($remapped);

    $after = file_get_contents($temp) ?: '';
    @unlink($temp);

    return ['after' => $after, 'fixes' => count($remapped)];
}

it('rewrites an OA\Property attribute on a Data property as #[ResponseField]', function (): void {
    $result = runReplaceableFixer(
        ReplaceableAttributeData::class,
        'name',
        AuthoredAnnotationShape::Attribute,
        ResponseField::class,
        ['type' => 'string', 'format' => 'email', 'description' => 'The contact email.'],
    );

    expect($result['fixes'])->toBe(1)
        ->and($result['after'])->toBe(<<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

        use OpenApi\Attributes as OA;
        use Spatie\LaravelData\Data;

        #[OA\Schema(schema: 'ReplaceableAttribute')]
        final class ReplaceableAttributeData extends Data
        {
            public function __construct(
                #[\Radiergummi\OpenApi\Attributes\ResponseField(type: 'string', format: 'email', description: 'The contact email.')]
                public string $name,
            ) {}
        }

        PHP);
});

it('rewrites an @OA\Property docblock on a Data property as #[ResponseField]', function (): void {
    $result = runReplaceableFixer(
        ReplaceableDocblockData::class,
        'name',
        AuthoredAnnotationShape::Docblock,
        ResponseField::class,
        ['type' => 'string', 'format' => 'email', 'description' => 'The contact email.'],
    );

    expect($result['fixes'])->toBe(1)
        ->and($result['after'])
        ->toContain("#[\Radiergummi\OpenApi\Attributes\ResponseField(type: 'string', format: 'email', description: 'The contact email.')]")
        ->not->toContain('@OA\Property');
});

it('keeps surrounding prose when removing an @OA\Property block from a property docblock', function (): void {
    $result = runReplaceableFixer(
        ReplaceableDocblockWithProseData::class,
        'name',
        AuthoredAnnotationShape::Docblock,
        ResponseField::class,
        ['type' => 'string', 'format' => 'email'],
    );

    expect($result['fixes'])->toBe(1)
        ->and($result['after'])
        ->toContain('This prose must survive the rewrite.')
        ->not->toContain('@OA\Property');
});

it('rewrites a query OA\Parameter attribute on a controller method as #[QueryParam]', function (): void {
    $result = runReplaceableFixer(
        ReplaceableQueryController::class,
        'index',
        AuthoredAnnotationShape::Attribute,
        QueryParam::class,
        ['name' => 'q', 'required' => true, 'description' => 'Free-text search.'],
    );

    expect($result['fixes'])->toBe(1)
        ->and($result['after'])
        ->toContain("#[\Radiergummi\OpenApi\Attributes\QueryParam(name: 'q', required: true, description: 'Free-text search.')]")
        ->not->toContain('#[OA\Parameter')
        ->toContain("#[OA\Get(path: '/replaceable-query', operationId: 'replaceableQuery')]");
});

it('yields nothing when the finding context is incomplete', function (): void {
    $finding = new Finding(
        ruleId: 'migration.oa-replaceable-by-attribute',
        severity: Severity::Improvable,
        message: 'fixture',
    );

    expect(iterator_to_array(new OaReplaceableByAttributeFixer()->fix($finding, new FixContext())))->toBe([]);
});

it('yields nothing when the source class cannot be reflected', function (): void {
    $finding = new Finding(
        ruleId: 'migration.oa-replaceable-by-attribute',
        severity: Severity::Improvable,
        message: 'fixture',
        context: [
            Finding::CONTEXT_SOURCE_CLASS => 'Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\NoSuchClass',
            Finding::CONTEXT_SOURCE_MEMBER => 'name',
            AuthoredAnnotationShape::FINDING_CONTEXT_KEY => AuthoredAnnotationShape::Attribute->value,
            OaReplaceableByAttribute::CONTEXT_TARGET_ATTRIBUTE => ResponseField::class,
            OaReplaceableByAttribute::CONTEXT_ATTRIBUTE_ARGUMENTS => ['type' => 'string'],
        ],
    );

    expect(iterator_to_array(new OaReplaceableByAttributeFixer()->fix($finding, new FixContext())))->toBe([]);
});
