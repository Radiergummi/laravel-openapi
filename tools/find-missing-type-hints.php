<?php

declare(strict_types=1);

/**
 * Finds function-like constructs (named functions, methods, closures, arrow functions) whose
 * parameters or return value lack a NATIVE type declaration, so they can be hinted as part of the
 * type-safety effort. Built on nikic/php-parser, so it inspects real signatures rather than
 * matching text.
 *
 * By default a parameter/return counts as "missing" whenever it has no native type, even if a
 * PHPDoc tag documents it (that is the gap PHPStan does not report). Pass --undocumented-only to
 * narrow the report to signatures that also lack PHPDoc type coverage.
 *
 * Usage:
 *   php tools/find-missing-type-hints.php [paths...] [--kind=all|closures|functions|methods]
 *                                         [--undocumented-only] [--json]
 *
 * Defaults: paths=src, --kind=all
 */

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

require __DIR__ . '/../vendor/autoload.php';

/**
 * @internal
 */
final class MissingTypeHintVisitor extends NodeVisitorAbstract
{
    /** @var list<array{file: string, line: int, kind: string, name: string, params: list<string>, return: bool}> */
    public array $findings = [];

    /** @var list<string> */
    private array $classStack = [];

    public function __construct(
        private readonly string $file,
        private readonly bool $undocumentedOnly,
    ) {}

    public function enterNode(Node $node): null
    {
        if ($node instanceof ClassLike) {
            $this->classStack[] = $node->name?->toString() ?? 'class@anonymous';
        }

        if ($node instanceof FunctionLike) {
            $this->inspect($node);
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof ClassLike) {
            array_pop($this->classStack);
        }

        return null;
    }

    private function inspect(FunctionLike $node): void
    {
        [$kind, $name] = $this->describe($node);
        $docComment = $node->getDocComment()?->getText() ?? '';

        $missingParams = [];

        foreach ($node->getParams() as $param) {
            if ($param->type !== null) {
                continue;
            }

            $variableName = $param->var instanceof Node\Expr\Variable && is_string($param->var->name)
                ? '$' . $param->var->name
                : '$?';

            if ($this->undocumentedOnly && $this->hasParamDoc($docComment, $variableName)) {
                continue;
            }

            $missingParams[] = $variableName;
        }

        $missingReturn = $node->getReturnType() === null
            && !$this->returnTypeForbidden($node)
            && !($this->undocumentedOnly && $this->hasReturnDoc($docComment));

        if ($missingParams === [] && !$missingReturn) {
            return;
        }

        $this->findings[] = [
            'file' => $this->file,
            'line' => $node->getStartLine(),
            'kind' => $kind,
            'name' => $name,
            'params' => $missingParams,
            'return' => $missingReturn,
        ];
    }

    /**
     * @return array{string, string}
     */
    private function describe(FunctionLike $node): array
    {
        return match (true) {
            $node instanceof ClassMethod => ['method', $this->currentClass() . '::' . $node->name->toString() . '()'],
            $node instanceof Function_ => ['function', $node->name->toString() . '()'],
            $node instanceof Closure => ['closure', ''],
            $node instanceof ArrowFunction => ['arrow-fn', ''],
            default => ['callable', ''],
        };
    }

    private function currentClass(): string
    {
        $name = end($this->classStack);

        return $name === false ? '' : $name;
    }

    /**
     * PHP forbids a return type declaration on constructors and destructors.
     */
    private function returnTypeForbidden(FunctionLike $node): bool
    {
        return $node instanceof ClassMethod
            && in_array(strtolower($node->name->toString()), ['__construct', '__destruct'], true);
    }

    private function hasParamDoc(string $docComment, string $variableName): bool
    {
        $quoted = preg_quote($variableName, '/');

        return preg_match('/@param\s+\S+\s+' . $quoted . '\b/', $docComment) === 1;
    }

    private function hasReturnDoc(string $docComment): bool
    {
        return preg_match('/@return\s+\S+/', $docComment) === 1;
    }
}

$paths = [];
$kind = 'all';
$undocumentedOnly = false;
$json = false;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--kind=')) {
        $kind = substr($arg, 7);
    } elseif ($arg === '--undocumented-only') {
        $undocumentedOnly = true;
    } elseif ($arg === '--json') {
        $json = true;
    } elseif (str_starts_with($arg, '--')) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(2);
    } else {
        $paths[] = $arg;
    }
}

if ($paths === []) {
    $paths = ['src'];
}

$kindFilter = match ($kind) {
    'all' => ['function', 'method', 'closure', 'arrow-fn'],
    'closures' => ['closure', 'arrow-fn'],
    'functions' => ['function'],
    'methods' => ['method'],
    default => null,
};

if ($kindFilter === null) {
    fwrite(STDERR, "Unknown --kind: {$kind} (use all|closures|functions|methods)\n");
    exit(2);
}

$parser = (new ParserFactory())->createForHostVersion();

/** @var list<array{file: string, line: int, kind: string, name: string, params: list<string>, return: bool}> $findings */
$findings = [];

foreach (collectPhpFiles($paths) as $file) {
    $source = file_get_contents($file);

    if ($source === false) {
        continue;
    }

    try {
        $ast = $parser->parse($source) ?? [];
    } catch (Throwable $error) {
        fwrite(STDERR, "Skipping {$file}: {$error->getMessage()}\n");

        continue;
    }

    $visitor = new MissingTypeHintVisitor($file, $undocumentedOnly);
    $traverser = new NodeTraverser();
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    foreach ($visitor->findings as $finding) {
        if (in_array($finding['kind'], $kindFilter, true)) {
            $findings[] = $finding;
        }
    }
}

if ($json) {
    echo json_encode($findings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), "\n";

    exit($findings === [] ? 0 : 1);
}

printf("%-9s %-50s %s\n", 'KIND', 'LOCATION', 'MISSING');
printf("%s\n", str_repeat('-', 100));

foreach ($findings as $finding) {
    $missing = [];

    if ($finding['return']) {
        $missing[] = 'return';
    }

    if ($finding['params'] !== []) {
        $missing[] = 'params: ' . implode(', ', $finding['params']);
    }

    printf(
        "%-9s %-50s %s\n",
        $finding['kind'],
        "{$finding['file']}:{$finding['line']}",
        ($finding['name'] !== '' ? $finding['name'] . ' — ' : '') . implode('; ', $missing),
    );
}

printf("%s\n", str_repeat('-', 100));
printf("%d signature(s) missing a native type hint.\n", count($findings));

exit($findings === [] ? 0 : 1);

/**
 * @param list<string> $paths
 *
 * @return iterable<string>
 */
function collectPhpFiles(array $paths): iterable
{
    foreach ($paths as $path) {
        if (is_file($path)) {
            yield $path;

            continue;
        }

        if (!is_dir($path)) {
            fwrite(STDERR, "Skipping non-existent path: {$path}\n");

            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo && $entry->getExtension() === 'php') {
                yield $entry->getPathname();
            }
        }
    }
}
