<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

use function file_get_contents;

/**
 * Per-run, read-only access to source files for {@see Fixer}s: their raw text and a parsed AST
 * whose nodes carry byte positions (`getStartFilePos()` / `getEndFilePos()`) and resolved names.
 *
 * Both are cached per file path so multiple fixers touching the same file parse it once. The
 * context never mutates files — it only locates the spans fixers turn into {@see Fix}es; writing
 * is {@see FixApplicator}'s job.
 *
 * @internal
 */
final class FixContext
{
    private readonly Parser $parser;

    /** @var array<string, string> */
    private array $sources = [];

    /** @var array<string, array<Node>> */
    private array $asts = [];

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? new ParserFactory()->createForNewestSupportedVersion();
    }

    /**
     * The raw, unmodified contents of `$file`, cached for the duration of the run.
     */
    public function source(string $file): string
    {
        return $this->sources[$file] ??= (file_get_contents($file) ?: '');
    }

    /**
     * The parsed statements of `$file` with byte positions and resolved names attached. Returns an
     * empty list when the file cannot be read or parsed, so fixers degrade to "no fix".
     *
     * @return array<Node>
     */
    public function ast(string $file): array
    {
        if (isset($this->asts[$file])) {
            return $this->asts[$file];
        }

        $statements = $this->parser->parse($this->source($file)) ?? [];

        // Annotate without replacing nodes so byte positions stay intact; fixers read the
        // `resolvedName` attribute to match attributes against their fully-qualified class.
        $traverser = new NodeTraverser(new NameResolver(options: ['replaceNodes' => false]));

        return $this->asts[$file] = $traverser->traverse($statements);
    }
}
