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
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpComponentMigration\AttributeComponents;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpComponentMigration\DocblockComponents;

uses()->group('openapi', 'lint', 'fix');

/**
 * Copies the fixture source to a throwaway temp file, runs the component fixer for `$componentName`
 * on `$class`, applies the fixes to the copy, and returns the after-source + fix count.
 *
 * @param class-string $class
 *
 * @return array{after: string, fixes: int}
 */
function runComponentFixer(string $class, AuthoredAnnotationShape $shape, string $componentName): array
{
    $sourceFile = new ReflectionClass($class)->getFileName() ?: '';
    $before = file_get_contents($sourceFile) ?: '';

    $temp = tempnam(sys_get_temp_dir(), 'openapi-component-fix-') ?: '';
    file_put_contents($temp, $before);

    $finding = new Finding(
        ruleId: 'migration.oa-redundant-component-with-inference',
        severity: Severity::Improvable,
        message: 'fixture',
        location: new FindingLocation(file: $sourceFile),
        context: [
            Finding::CONTEXT_SOURCE_CLASS => $class,
            AuthoredAnnotationShape::FINDING_CONTEXT_KEY => $shape->value,
            RedundantOaAnnotationFixer::CONTEXT_COMPONENT_NAME => $componentName,
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

it('removes only the targeted #[OA\Response] component, keeping stacked siblings', function (): void {
    $result = runComponentFixer(AttributeComponents::class, AuthoredAnnotationShape::Attribute, 'PlainOk');

    expect($result['fixes'])->toBe(1)
        ->and($result['after'])
        ->not->toContain("response: 'PlainOk'")
        ->toContain("response: 'DescribedOk'")
        ->toContain("response: 'AliasedOk'")
        ->toContain("response: 'OrphanOk'")
        ->toContain("response: 'AliasingResponse'");
});

it('removes only the targeted @OA\Parameter docblock block, keeping the load-bearing sibling', function (): void {
    $result = runComponentFixer(DocblockComponents::class, AuthoredAnnotationShape::Docblock, 'RecordPath');

    expect($result['fixes'])->toBe(1)
        ->and($result['after'])
        ->not->toContain('parameter="RecordPath"')
        ->toContain('parameter="KeptParam"')
        ->toContain('A description inference cannot derive.');
});

it('yields no fix when the named component is absent from the class', function (): void {
    $result = runComponentFixer(AttributeComponents::class, AuthoredAnnotationShape::Attribute, 'NoSuchComponent');

    expect($result['fixes'])->toBe(0);
});
