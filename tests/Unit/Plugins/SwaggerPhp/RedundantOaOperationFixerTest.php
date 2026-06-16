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
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\OperationAnnotatedController;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\OperationAttributeController;

uses()->group('openapi', 'lint', 'fix');

/**
 * Golden-file harness: copies the real fixture source to a throwaway temp file, runs the fixer
 * against a synthetic finding for `$class::$method`, applies the fixes to the copy (never the
 * checked-in fixture), and returns the before/after source.
 *
 * @param class-string $class
 *
 * @return array{after: string, fixes: int}
 */
function runRedundantOaAnnotationFixer(string $class, string $method, AuthoredAnnotationShape $shape): array
{
    $sourceFile = new ReflectionClass($class)->getFileName() ?: '';
    $before = file_get_contents($sourceFile) ?: '';

    $temp = tempnam(sys_get_temp_dir(), 'openapi-oa-op-fix-') ?: '';
    file_put_contents($temp, $before);

    $finding = new Finding(
        ruleId: 'migration.oa-redundant-operation-with-inference',
        severity: Severity::Improvable,
        message: 'fixture',
        location: new FindingLocation(file: $sourceFile),
        context: [
            Finding::CONTEXT_SOURCE_CLASS => $class,
            Finding::CONTEXT_SOURCE_MEMBER => $method,
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

it('removes the whole @OA\Get docblock from a controller method, leaving siblings intact', function (): void {
    $result = runRedundantOaAnnotationFixer(OperationAnnotatedController::class, 'redundant', AuthoredAnnotationShape::Docblock);

    expect($result['fixes'])->toBe(1)
        ->and($result['after'])
        // The redundant method's annotation is gone, but its signature and the sibling method's
        // essential annotation both survive.
        ->not->toContain('/op-redundant')
        ->toContain('public function redundant(): PlainStructData')
        ->toContain('/op-essential')
        ->toContain('Prose that lives only in the annotation');
});

it('removes the #[OA\*] operation attributes from a controller method', function (): void {
    $result = runRedundantOaAnnotationFixer(OperationAttributeController::class, 'redundant', AuthoredAnnotationShape::Attribute);

    expect($result['fixes'])->toBe(2)
        ->and($result['after'])
        ->not->toContain('OA\Get')
        ->not->toContain('OA\Response')
        ->toContain('public function redundant(): PlainStructData');
});

it('yields nothing when the finding context lacks the source class, member, or shape', function (): void {
    $finding = new Finding(
        ruleId: 'migration.oa-redundant-operation-with-inference',
        severity: Severity::Improvable,
        message: 'fixture',
    );

    expect(iterator_to_array(new RedundantOaAnnotationFixer()->fix($finding, new FixContext())))->toBe([]);
});
