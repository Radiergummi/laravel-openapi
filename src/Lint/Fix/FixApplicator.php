<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Radiergummi\OpenApi\Lint\Fix\Ast\FixConflictDetector;
use Radiergummi\OpenApi\Lint\Fix\Ast\FixOperationVisitor;
use RuntimeException;
use Throwable;

use function array_map;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function rename;
use function tempnam;
use function unlink;

use const LOCK_EX;

/**
 * Applies a batch of {@see Fix}es to the working tree, grouped by file. Each file is parsed once,
 * cloned, mutated by one {@see FixOperationVisitor} per fix, and reprinted once with php-parser's
 * format-preserving printer, leaving every untouched byte exactly as it was. The result is written
 * atomically via a temp file + rename so a failure never leaves a half-written source.
 *
 * @internal
 */
final readonly class FixApplicator
{
    private Parser $parser;

    private Standard $printer;

    private FixConflictDetector $conflictDetector;

    public function __construct()
    {
        $this->parser = new ParserFactory()->createForNewestSupportedVersion();
        $this->printer = new Standard();
        $this->conflictDetector = new FixConflictDetector();
    }

    /**
     * @param list<Fix> $fixes
     *
     * @throws RuntimeException When a modified file cannot be written atomically.
     */
    public function apply(array $fixes, bool $dryRun = false): FixResult
    {
        /** @var array<string, list<Fix>> $byFile */
        $byFile = [];

        foreach ($fixes as $fix) {
            $byFile[$fix->file][] = $fix;
        }

        $applied = [];
        $skipped = [];
        $modifiedFiles = [];

        foreach ($byFile as $file => $fileFixes) {
            // Keep only fixes whose effects on a shared node are provably independent; the rest are
            // skipped as conflicts before any mutation so a same-node clash never lands silently.
            ['kept' => $kept, 'skipped' => $conflicts] = $this->conflictDetector->partition($fileFixes);

            foreach ($conflicts as $conflict) {
                $skipped[] = $conflict;
            }

            $source = file_get_contents($file) ?: '';

            [$accepted, $rejected, $newSource] = $this->applyToFile($source, $kept);

            foreach ($accepted as $fix) {
                $applied[] = $fix;
            }

            foreach ($rejected as $skip) {
                $skipped[] = $skip;
            }

            if ($accepted !== [] && $newSource !== $source) {
                $modifiedFiles[] = $file;

                if (!$dryRun) {
                    $this->write($file, $newSource);
                }
            }
        }

        return new FixResult($applied, $skipped, $modifiedFiles);
    }

    /**
     * @param list<Fix> $fileFixes
     *
     * @return array{list<Fix>, list<SkippedFix>, string}
     */
    private function applyToFile(string $source, array $fileFixes): array
    {
        $oldStatements = $this->parser->parse($source);

        if ($oldStatements === null) {
            return [[], $this->skipAll($fileFixes, FixSkipReason::NodeNotFound), $source];
        }

        $oldTokens = $this->parser->getTokens();

        // NameResolver with replaceNodes:false stamps each class node's namespacedName so the
        // visitor can match by FQCN, while leaving every node's bytes intact for the FP printer.
        new NodeTraverser(new NameResolver(null, ['replaceNodes' => false]))->traverse($oldStatements);

        // Clone so $oldStatements stays the pristine original the FP printer diffs against, while
        // $newStatements is the tree the fix visitors mutate.
        $newStatements = new NodeTraverser(new CloningVisitor())->traverse($oldStatements);

        $traverser = new NodeTraverser();

        /** @var list<array{Fix, FixOperationVisitor}> $pairs */
        $pairs = [];

        foreach ($fileFixes as $fix) {
            $visitor = new FixOperationVisitor($fix->operation);
            $pairs[] = [$fix, $visitor];
            $traverser->addVisitor($visitor);
        }

        $newStatements = $traverser->traverse($newStatements);

        $accepted = [];
        $rejected = [];

        foreach ($pairs as [$fix, $visitor]) {
            if ($visitor->applied) {
                $accepted[] = $fix;
            } else {
                $rejected[] = new SkippedFix($fix, FixSkipReason::NodeNotFound);
            }
        }

        if ($accepted === []) {
            return [[], $rejected, $source];
        }

        try {
            $newSource = $this->printer->printFormatPreserving($newStatements, $oldStatements, $oldTokens);
        } catch (Throwable) {
            // A format-preserving print failure would otherwise reformat the whole file and destroy
            // comments/style. Reject the fixes instead of shipping a mangled file.
            return [[], [...$rejected, ...$this->skipAll($accepted, FixSkipReason::PrintFailed)], $source];
        }

        return [$accepted, $rejected, $newSource];
    }

    /**
     * @param list<Fix> $fixes
     *
     * @return list<SkippedFix>
     */
    private function skipAll(array $fixes, FixSkipReason $reason): array
    {
        return array_map(static fn(Fix $fix): SkippedFix => new SkippedFix($fix, $reason), $fixes);
    }

    /**
     * @throws RuntimeException When the temp file cannot be created, written, or renamed into place.
     */
    private function write(string $file, string $contents): void
    {
        $temp = tempnam(dirname($file), 'openapi-fix-');

        if ($temp === false) {
            throw new RuntimeException("Unable to create a temporary file next to {$file}.");
        }

        if (file_put_contents($temp, $contents, LOCK_EX) === false) {
            @unlink($temp);

            throw new RuntimeException("Unable to write fixed contents for {$file}.");
        }

        if (!rename($temp, $file)) {
            @unlink($temp);

            throw new RuntimeException("Unable to replace {$file} with the fixed version.");
        }
    }
}
