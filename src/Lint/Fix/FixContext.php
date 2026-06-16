<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

use function file_get_contents;

/**
 * Per-run read-only access to source files: raw text and a byte-positioned, name-resolved AST.
 * Both are cached per path so multiple fixers touching the same file parse it once.
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
     * The {@see ClassLike} node for a fully-qualified `$class` within `$file`, or null if absent.
     */
    public function classNode(string $file, string $class): ?ClassLike
    {
        $node = new NodeFinder()->findFirst(
            $this->ast($file),
            static fn(Node $node): bool
                => $node instanceof ClassLike
                && $node->namespacedName?->toString() === $class,
        );

        return $node instanceof ClassLike ? $node : null;
    }

    /**
     * Parsed statements with byte positions and resolved names. Returns an empty list on failure.
     *
     * @return array<Node>
     */
    public function ast(string $file): array
    {
        if (isset($this->asts[$file])) {
            return $this->asts[$file];
        }

        $statements = $this->parser->parse($this->source($file)) ?? [];

        // Annotate without replacing so byte positions stay intact.
        $traverser = new NodeTraverser(new NameResolver(options: ['replaceNodes' => false]));

        return $this->asts[$file] = $traverser->traverse($statements);
    }

    /**
     * Raw, unmodified contents of `$file`, cached for the run.
     */
    public function source(string $file): string
    {
        return $this->sources[$file] ??= (file_get_contents($file) ?: '');
    }
}
