<?php

declare(strict_types=1);

/**
 * Per-route action return-shape classifier (app-context, deterministic).
 *
 * Boots a consumer app, enumerates its routes under an API prefix, reflects each controller action,
 * and labels the action's return EXPRESSION with a source-shape string. This is the signal the
 * generated spec cannot carry: whether an empty 2xx is *correctly* empty (a void/no-content action)
 * or a *give-up* empty (the action returns a body the generator could not resolve). metrics.php joins
 * these shapes with the spec's substantive-ness to produce the three-way responseCoverage breakdown
 * (#413). It does NOT decide substantive-ness — only the source shape.
 *
 * Emits a JSON array of {uri, verb, action, returnType, shape} records to stdout.
 *
 * Usage: php classify.php <repo-dir> [--prefix=/api] > classify.json
 *   <repo-dir> is the consumer app root (must have vendor/ + bootstrap/app.php).
 */

use Illuminate\Routing\Route;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure as ClosureExpr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_ as NewExpr;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

$repoDir = $argv[1] ?? null;
$prefix = '/api';

foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--prefix=')) {
        $prefix = substr($arg, 9);
    }
}

if ($repoDir === null || !is_dir($repoDir)) {
    fwrite(STDERR, "usage: classify.php <repo-dir> [--prefix=/api]\n");
    exit(2);
}

require $repoDir . '/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require $repoDir . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$router = $app->make('router');
$parser = (new ParserFactory())->createForHostVersion();
$nodeFinder = new NodeFinder();

/** @var array<string, null|list<Stmt>> $fileAst */
$fileAst = [];

/**
 * Parse a file once.
 *
 * @return null|list<Stmt>
 */
$astFor = function (string $file) use ($parser, &$fileAst): ?array {
    if (array_key_exists($file, $fileAst)) {
        return $fileAst[$file];
    }

    try {
        $code = @file_get_contents($file);
        $fileAst[$file] = $code === false ? null : $parser->parse($code);
    } catch (Throwable) {
        $fileAst[$file] = null;
    }

    return $fileAst[$file];
};

/** Find the ClassMethod node for a ReflectionMethod (the overload whose line range contains it). */
$methodNode = function (ReflectionMethod $method) use ($astFor, $nodeFinder): ?ClassMethod {
    $file = $method->getDeclaringClass()->getFileName();

    if ($file === false) {
        return null;
    }

    $ast = $astFor($file);

    if ($ast === null) {
        return null;
    }

    $candidates = $nodeFinder->find($ast, static fn(Node $n): bool => $n instanceof ClassMethod
        && $n->name->toString() === $method->getName());
    $start = $method->getStartLine();

    foreach ($candidates as $candidate) {
        if ($candidate->getStartLine() <= $start && $candidate->getEndLine() >= $start) {
            return $candidate;
        }
    }

    return $candidates[0] ?? null;
};

/**
 * Method-level returns, skipping nested closures/classes.
 *
 * @param list<Node> $statements
 *
 * @return list<Return_>
 */
$methodReturns = function (array $statements) use (&$methodReturns): array {
    $found = [];
    $walk = function (Node $node) use (&$walk, &$found): void {
        if ($node instanceof ClosureExpr || $node instanceof ArrowFunction || $node instanceof ClassLike) {
            return;
        }

        if ($node instanceof Return_) {
            $found[] = $node;
        }

        foreach ($node->getSubNodeNames() as $sub) {
            $children = $node->{$sub};

            foreach (is_array($children) ? $children : [$children] as $child) {
                if ($child instanceof Node) {
                    $walk($child);
                }
            }
        }
    };

    foreach ($statements as $statement) {
        $walk($statement);
    }

    return $found;
};

$shortName = static fn(?string $fqcn): ?string => $fqcn === null
    ? null
    : (($pos = strrpos($fqcn, '\\')) === false ? $fqcn : substr($fqcn, $pos + 1));

/** Peel resource-preserving `->additional(...)` wrappers. */
$unwrapChain = static function (Expr $expr): Expr {
    while ($expr instanceof MethodCall
        && $expr->name instanceof Identifier
        && strtolower($expr->name->toString()) === 'additional') {
        $expr = $expr->var;
    }

    return $expr;
};

/** Label a return expression with a source-shape string (mirrors the library's reader whitelist). */
$classifyExpr = function (Expr $expr) use ($unwrapChain, $shortName): string {
    $expr = $unwrapChain($expr);

    if ($expr instanceof StaticCall && $expr->name instanceof Identifier) {
        $method = strtolower($expr->name->toString());
        $class = $expr->class instanceof Name ? $expr->class->toString() : '<dynamic>';

        if ($method === 'collection') {
            return 'resource::collection';
        }

        if ($method === 'make' || $method === 'collect') {
            return 'resource::' . $method;
        }

        return 'static-call:' . $shortName($class) . '::' . $method;
    }

    if ($expr instanceof NewExpr) {
        return $expr->class instanceof Name ? 'new ' . $shortName($expr->class->toString()) . '(...)' : 'new <dynamic>';
    }

    if ($expr instanceof MethodCall && $expr->name instanceof Identifier) {
        $method = strtolower($expr->name->toString());

        if ($method === 'toresource' || $method === 'toresourcecollection') {
            return '->' . $expr->name->toString() . '()';
        }

        if ($method === 'json') {
            $arg = $expr->getArgs()[0]->value ?? null;

            return $arg instanceof Expr\Array_ ? 'response()->json([array literal])' : 'response()->json(<non-literal>)';
        }

        if ($method === 'nocontent') {
            return 'response()->noContent()';
        }

        return '->' . $method . '() chain';
    }

    if ($expr instanceof Expr\Array_) {
        return 'array literal';
    }

    if ($expr instanceof Expr\Ternary || $expr instanceof Expr\Match_) {
        return 'conditional/ternary';
    }

    if ($expr instanceof Variable) {
        return 'variable (unresolved)';
    }

    if ($expr instanceof Expr\ConstFetch && in_array(strtolower($expr->name->toString()), ['null', 'true', 'false'], true)) {
        return 'scalar literal (' . strtolower($expr->name->toString()) . ')';
    }

    if ($expr instanceof Node\Scalar) {
        return 'scalar literal';
    }

    return 'other (' . (new ReflectionClass($expr))->getShortName() . ')';
};

/**
 * Resolve `return $var;` to its single unconditional assignment, mirroring the reader.
 *
 * @param list<Stmt> $statements
 */
$resolveVarExpr = function (Variable $var, array $statements) use ($nodeFinder): ?Expr {
    if (!is_string($var->name)) {
        return null;
    }

    $name = $var->name;
    $topLevel = [];

    foreach ($statements as $statement) {
        if ($statement instanceof Stmt\Expression && $statement->expr instanceof Assign
            && $statement->expr->var instanceof Variable && $statement->expr->var->name === $name) {
            $topLevel[] = $statement->expr;
        }
    }

    $all = $nodeFinder->find($statements, static fn(Node $n): bool => $n instanceof Assign
        && $n->var instanceof Variable && $n->var->name === $name);

    return count($topLevel) === 1 && count($all) === 1 ? $topLevel[0]->expr : null;
};

$records = [];

foreach ($router->getRoutes() as $route) {
    /** @var Route $route */
    $uri = '/' . ltrim($route->uri(), '/');

    if ($prefix !== '' && !str_starts_with($uri, rtrim($prefix, '/'))) {
        continue;
    }

    $action = $route->getActionName();

    foreach ($route->methods() as $verb) {
        $verb = strtolower($verb);

        if (!in_array($verb, ['get', 'post', 'put', 'patch', 'delete'], true)) {
            continue;
        }

        $record = ['uri' => $uri, 'verb' => $verb, 'action' => $action, 'returnType' => '', 'shape' => null];

        if ($action === 'Closure' || !str_contains($action, '@')) {
            $record['shape'] = 'closure/invokable-or-unknown';
            $records[] = $record;

            continue;
        }

        [$class, $methodName] = explode('@', $action, 2);

        try {
            $method = new ReflectionMethod($class, $methodName);
        } catch (Throwable) {
            $record['shape'] = 'reflection-failed';
            $records[] = $record;

            continue;
        }

        $returnType = $method->getReturnType();
        $record['returnType'] = $returnType === null ? '' : (string) $returnType;

        $node = $methodNode($method);

        if ($node === null || $node->stmts === null) {
            $record['shape'] = strtolower($record['returnType']) === 'void' ? 'void/no-body' : 'no-body (abstract/interface)';
            $records[] = $record;

            continue;
        }

        $returns = $methodReturns($node->stmts);

        if ($returns === []) {
            $record['shape'] = 'no return (void-like)';
            $records[] = $record;

            continue;
        }

        $topReturn = null;

        foreach ($node->stmts as $statement) {
            if ($statement instanceof Return_) {
                $topReturn = $statement;

                break;
            }
        }

        if ($topReturn === null) {
            $record['shape'] = 'conditional returns only';
            $records[] = $record;

            continue;
        }

        if (count($returns) > 1) {
            $record['shape'] = 'multiple returns';
            $records[] = $record;

            continue;
        }

        $expr = $topReturn->expr;

        if ($expr === null) {
            $record['shape'] = 'return; (void)';
            $records[] = $record;

            continue;
        }

        if ($expr instanceof Variable) {
            $resolved = $resolveVarExpr($expr, $node->stmts);
            $record['shape'] = $resolved === null
                ? 'variable (not single-assigned)'
                : $classifyExpr($resolved) . ' (via $var)';
            $records[] = $record;

            continue;
        }

        $record['shape'] = $classifyExpr($expr);
        $records[] = $record;
    }
}

echo json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
