<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Radiergummi\OpenApi\Lint\Fix\Ast\AstOperation;
use Radiergummi\OpenApi\Lint\Fix\Ast\FixOperationVisitor;
use RuntimeException;
use Throwable;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function rename;
use function substr_replace;
use function tempnam;
use function unlink;
use function usort;

use const LOCK_EX;

/**
 * Applies a batch of {@see Fix}es to the working tree, grouped by file and written atomically via a
 * temp file + rename so a failure never leaves a half-written source.
 *
 * Two operation models are supported. {@see AstOperation} fixes mutate a cloned syntax tree and are
 * reprinted once per file with php-parser's format-preserving printer, leaving every untouched byte
 * exactly as it was. Byte-addressed {@see FixOperation} fixes are spliced bottom-to-top with
 * overlap de-confliction. A single file uses one model or the other; the two never interleave.
 *
 * @internal
 */
final readonly class FixApplicator
{
    private Parser $parser;

    private Standard $printer;

    public function __construct()
    {
        $this->parser = new ParserFactory()->createForNewestSupportedVersion();
        $this->printer = new Standard();
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
            $source = file_get_contents($file) ?: '';

            [$accepted, $rejected, $newSource] = $this->hasAstOperation($fileFixes)
                ? $this->applyAst($source, $fileFixes)
                : $this->applyByteSplice($source, $fileFixes);

            foreach ($accepted as $fix) {
                $applied[] = $fix;
            }

            foreach ($rejected as $fix) {
                $skipped[] = $fix;
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
     */
    private function hasAstOperation(array $fileFixes): bool
    {
        return array_any($fileFixes, static fn(Fix $fix): bool => $fix->operation instanceof AstOperation);
    }

    /**
     * Mutate a cloned tree, then reprint with the format-preserving printer. Any byte-splice fix
     * that slipped into the same file is rejected: the two models cannot share a file.
     *
     * @param list<Fix> $fileFixes
     *
     * @return array{list<Fix>, list<Fix>, string}
     */
    private function applyAst(string $source, array $fileFixes): array
    {
        $oldStatements = $this->parser->parse($source);

        if ($oldStatements === null) {
            return [[], $fileFixes, $source];
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
        $rejected = [];

        foreach ($fileFixes as $fix) {
            if (!$fix->operation instanceof AstOperation) {
                $rejected[] = $fix;

                continue;
            }

            $visitor = new FixOperationVisitor($fix->operation);
            $pairs[] = [$fix, $visitor];
            $traverser->addVisitor($visitor);
        }

        $newStatements = $traverser->traverse($newStatements);

        $accepted = [];

        foreach ($pairs as [$fix, $visitor]) {
            if ($visitor->applied) {
                $accepted[] = $fix;
            } else {
                $rejected[] = $fix;
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
            return [[], [...$rejected, ...$accepted], $source];
        }

        return [$accepted, $rejected, $newSource];
    }

    /**
     * @param list<Fix> $fileFixes
     *
     * @return array{list<Fix>, list<Fix>, string}
     */
    private function applyByteSplice(string $source, array $fileFixes): array
    {
        /** @var list<array{edit: SourceEdit, fix: Fix}> $pairs */
        $pairs = [];

        foreach ($fileFixes as $fix) {
            if ($fix->operation instanceof AstOperation) {
                continue;
            }

            $pairs[] = ['edit' => $fix->operation->toEdit($source), 'fix' => $fix];
        }

        // Bottom-to-top: largest start offset first. Ties break on the wider edit so a containing
        // edit is preferred over the narrower one it would swallow.
        usort(
            $pairs,
            static fn(array $a, array $b): int
                => $b['edit']->start <=> $a['edit']->start
                ?: $b['edit']->end <=> $a['edit']->end,
        );

        $accepted = [];
        $rejected = [];

        /** @var list<SourceEdit> $acceptedEdits */
        $acceptedEdits = [];
        $result = $source;

        foreach ($pairs as $pair) {
            $edit = $pair['edit'];

            foreach ($acceptedEdits as $existing) {
                if ($edit->overlaps($existing)) {
                    $rejected[] = $pair['fix'];

                    continue 2;
                }
            }

            $acceptedEdits[] = $edit;
            $accepted[] = $pair['fix'];

            $result = substr_replace(
                $result,
                $edit->replacement,
                $edit->start,
                $edit->end - $edit->start,
            );
        }

        return [$accepted, $rejected, $result];
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
