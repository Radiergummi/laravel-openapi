<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Support;

use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\Fix\Fix;
use Radiergummi\OpenApi\Lint\Fix\FixableRule;
use Radiergummi\OpenApi\Lint\Fix\FixApplicator;
use Radiergummi\OpenApi\Lint\Fix\FixContext;
use Radiergummi\OpenApi\Lint\Fix\RemoveAttributeFixer;
use ReflectionClass;

use function file_get_contents;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Golden-file harness for attribute-removal fixers.
 *
 * Copies the *real* source of a fixture class to a throwaway temp file, runs the rule's fixer
 * against a synthetic finding that targets one member, applies the resulting fixes to the copy, and
 * hands back the before/after source. The fixture class is never mutated: reflection reads the
 * loaded class while the edits land on the temp copy (identical bytes, so attribute positions line
 * up).
 */
final class AttributeFixFixture
{
    /**
     * @param class-string          $class
     * @param array<string, string> $extraContext additional finding context keys a fixer reads
     *                                            (e.g. a value the owning rule stamps at check time)
     *
     * @return array{before: string, after: string, fixes: list<Fix>}
     */
    public static function run(
        FixableRule $rule,
        string $class,
        string $member,
        ?string $discriminator = null,
        array $extraContext = [],
    ): array {
        $sourceFile = new ReflectionClass($class)->getFileName() ?: '';
        $before = file_get_contents($sourceFile) ?: '';

        $temp = tempnam(sys_get_temp_dir(), 'openapi-fix-golden-') ?: '';
        file_put_contents($temp, $before);

        $context = [
            Finding::CONTEXT_SOURCE_CLASS => $class,
            Finding::CONTEXT_SOURCE_MEMBER => $member,
            ...$extraContext,
        ];

        if ($discriminator !== null) {
            $context[RemoveAttributeFixer::CONTEXT_DISCRIMINATOR] = $discriminator;
        }

        $finding = new Finding(
            ruleId: $rule->id,
            severity: $rule->severity,
            message: 'fixture',
            location: new FindingLocation(file: $sourceFile),
            context: $context,
        );

        // The fixer derives the target file from reflection (the real fixture); copy its operations
        // onto the temp file so applying them never mutates the checked-in fixture.
        $fixes = [];
        $remapped = [];

        foreach ($rule->fixer()->fix($finding, new FixContext()) as $fix) {
            $fixes[] = $fix;
            $remapped[] = new Fix($temp, $fix->description, $fix->ruleId, $fix->operation);
        }

        new FixApplicator()->apply($remapped);

        $after = file_get_contents($temp) ?: '';
        @unlink($temp);

        return ['before' => $before, 'after' => $after, 'fixes' => $fixes];
    }
}
