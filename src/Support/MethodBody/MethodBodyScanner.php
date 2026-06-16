<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\MethodBody;

use Illuminate\Container\Attributes\Scoped;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Throwable;

use function array_key_exists;
use function array_slice;
use function array_values;
use function file_get_contents;
use function is_file;
use function is_string;
use function preg_match;
use function strpos;
use function substr;

/**
 * Bounded method-body scanner: parses a controller source file once per generation run,
 * locates the AST node of a reflected method, and returns its first N top-level statements.
 *
 * Callers match their own whitelisted call shapes against those statements. Variable tracking
 * across statements or into other methods is intentionally out of scope.
 *
 * @internal
 */
#[Scoped]
final class MethodBodyScanner
{
    /** @var array<string, null|list<Stmt>> null marks a parse failure */
    private array $astCache = [];

    /** @var array<string, string> kept for trailing-comment lookups */
    private array $sourceCache = [];

    private readonly Parser $parser;

    private readonly NodeFinder $nodeFinder;

    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
        $this->parser = new ParserFactory()->createForNewestSupportedVersion();
        $this->nodeFinder = new NodeFinder();
    }

    /** @return list<Stmt> */
    public function firstStatements(ReflectionMethod $method, int $limit): array
    {
        $methodNode = $this->methodNode($method);

        return array_values(array_slice($methodNode->stmts ?? [], 0, $limit));
    }

    private function methodNode(ReflectionMethod $method): ?ClassMethod
    {
        $file = $method->getFileName();
        $declarationLine = $method->getStartLine();

        if ($file === false || $declarationLine === false) {
            return null;
        }

        $statements = $this->astFor($file);

        if ($statements === null) {
            return null;
        }

        $node = $this->nodeFinder->findFirst(
            $statements,
            static fn(Node $node): bool
                => $node instanceof ClassMethod
                && $node->name->toString() === $method->getName()
                && $node->getStartLine() <= $declarationLine
                && $node->getEndLine() >= $declarationLine,
        );

        return $node instanceof ClassMethod ? $node : null;
    }

    /**
     * @return null|list<Stmt>
     */
    private function astFor(string $file): ?array
    {
        if (array_key_exists($file, $this->astCache)) {
            return $this->astCache[$file];
        }

        $source = is_file($file) ? file_get_contents($file) : false;

        if (!is_string($source)) {
            return $this->astCache[$file] = null;
        }

        $this->sourceCache[$file] = $source;

        try {
            $statements = $this->parser->parse($source);
        } catch (Throwable $exception) {
            $this->logger->notice(
                'Tier-1 body scan skipped a file that failed to parse.',
                ['file' => $file, 'exception' => $exception],
            );

            $statements = null;
        }

        if ($statements === null) {
            return $this->astCache[$file] = null;
        }

        $traverser = new NodeTraverser(new NameResolver(options: ['preserveOriginalNames' => true]));

        /** @var list<Stmt> $resolved */
        $resolved = array_values($traverser->traverse($statements));

        return $this->astCache[$file] = $resolved;
    }

    /**
     * Returns the text of a trailing `//` comment on the node's last source line, or null.
     * Lets callers read inline annotations like `'email' => 'required|email', // The contact address.`
     */
    public function trailingCommentAfter(string $file, Node $node): ?string
    {
        $source = $this->sourceCache[$file] ?? null;
        $endPosition = $node->getEndFilePos();

        if ($source === null || $endPosition < 0) {
            return null;
        }

        $lineEnd = strpos($source, "\n", $endPosition);
        $restOfLine = $lineEnd === false
            ? substr($source, $endPosition + 1)
            : substr($source, $endPosition + 1, $lineEnd - $endPosition - 1);

        if (preg_match('~^\s*,?\s*//\s*(.+?)\s*$~', $restOfLine, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
