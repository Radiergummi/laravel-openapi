<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use Radiergummi\OpenApi\Support\MethodBody\ConditionalContextPolicy;
use Radiergummi\OpenApi\Support\MethodBody\StatementNodeFinder;

uses()->group('openapi');

// region Helpers

/**
 * @return list<Stmt>
 */
function parseStatements(string $body): array
{
    $statements = new ParserFactory()->createForNewestSupportedVersion()->parse("<?php {$body}");

    return array_values($statements ?? []);
}

function findValidateCall(string $body, ConditionalContextPolicy $policy): ?Node
{
    return new StatementNodeFinder()->findFirst(
        parseStatements($body),
        $policy,
        static fn(Node $node): bool => $node instanceof MethodCall
            && $node->name instanceof Identifier
            && $node->name->toLowerString() === 'validate',
    );
}

// endregion

// region SkipConditionalContexts: straight-line matches

it('finds a call in a plain expression statement', function (): void {
    $found = findValidateCall(
        '$request->validate(["a" => "required"]);',
        ConditionalContextPolicy::SkipConditionalContexts,
    );

    expect($found)->toBeInstanceOf(MethodCall::class);
});

it('finds a call behind an assignment', function (): void {
    $found = findValidateCall(
        '$data = $request->validate(["a" => "required"]);',
        ConditionalContextPolicy::SkipConditionalContexts,
    );

    expect($found)->not->toBeNull();
});

it('finds a call in a return statement', function (): void {
    $found = findValidateCall(
        'return $request->validate(["a" => "required"]);',
        ConditionalContextPolicy::SkipConditionalContexts,
    );

    expect($found)->not->toBeNull();
});

it('finds a call inside a chained call', function (): void {
    $found = findValidateCall(
        '$data = Validator::make($input, $rules)->validate();',
        ConditionalContextPolicy::SkipConditionalContexts,
    );

    expect($found)->not->toBeNull();
});

it('finds a call used as a call argument', function (): void {
    $found = findValidateCall(
        'respond($request->validate(["a" => "required"]));',
        ConditionalContextPolicy::SkipConditionalContexts,
    );

    expect($found)->not->toBeNull();
});

// endregion

// region SkipConditionalContexts: conditional contexts refused

it('does not enter an if statement', function (): void {
    $found = findValidateCall(
        'if ($request->has("x")) { $request->validate(["x" => "required"]); }',
        ConditionalContextPolicy::SkipConditionalContexts,
    );

    expect($found)->toBeNull();
});

it('does not enter a ternary', function (): void {
    $found = findValidateCall(
        '$data = $flag ? $request->validate(["c" => "required"]) : [];',
        ConditionalContextPolicy::SkipConditionalContexts,
    );

    expect($found)->toBeNull();
});

it('does not enter a logical short-circuit', function (): void {
    $found = findValidateCall(
        '$request->has("guard") && $request->validate(["d" => "required"]);',
        ConditionalContextPolicy::SkipConditionalContexts,
    );

    expect($found)->toBeNull();
});

it('does not enter a null-coalescing expression', function (): void {
    $found = findValidateCall(
        '$data = $cached ?? $request->validate(["e" => "required"]);',
        ConditionalContextPolicy::SkipConditionalContexts,
    );

    expect($found)->toBeNull();
});

it('does not enter a closure body, even via a straight-line wrapper call', function (): void {
    $found = findValidateCall(
        <<<'PHP'
        $result = DB::transaction(function () use ($request) {
            if ($request->has("e")) {
                return $request->validate(["e" => "required"]);
            }

            return [];
        });
        PHP,
        ConditionalContextPolicy::SkipConditionalContexts,
    );

    expect($found)->toBeNull();
});

it('does not enter an arrow-function body', function (): void {
    $found = findValidateCall(
        '$result = DB::transaction(fn () => $request->validate(["f" => "required"]));',
        ConditionalContextPolicy::SkipConditionalContexts,
    );

    expect($found)->toBeNull();
});

it('does not enter a match arm', function (): void {
    $found = findValidateCall(
        '$data = match ($mode) { "strict" => $request->validate(["g" => "required"]), default => [] };',
        ConditionalContextPolicy::SkipConditionalContexts,
    );

    expect($found)->toBeNull();
});

// endregion

// region IncludeConditionalContexts: full descent

it('finds a call inside an if statement under the inclusive policy', function (): void {
    $found = findValidateCall(
        'if ($request->has("x")) { $request->validate(["x" => "required"]); }',
        ConditionalContextPolicy::IncludeConditionalContexts,
    );

    expect($found)->not->toBeNull();
});

it('finds a call inside a guarded closure under the inclusive policy', function (): void {
    $found = findValidateCall(
        '$result = DB::transaction(function () use ($request) { if ($flag) { $request->validate(["e" => "required"]); } });',
        ConditionalContextPolicy::IncludeConditionalContexts,
    );

    expect($found)->not->toBeNull();
});

// endregion

// region findAll

/**
 * @return list<FuncCall>
 */
function findAllAbortCalls(string $body, ConditionalContextPolicy $policy): array
{
    $nodes = new StatementNodeFinder()->findAll(
        parseStatements($body),
        $policy,
        static fn(Node $node): bool => $node instanceof FuncCall
            && $node->name instanceof Name
            && $node->name->toLowerString() === 'abort',
    );

    $calls = [];

    foreach ($nodes as $node) {
        if ($node instanceof FuncCall) {
            $calls[] = $node;
        }
    }

    return $calls;
}

function abortStatusOf(FuncCall $call): ?int
{
    $status = $call->getArgs()[0]->value;

    return $status instanceof Int_ ? $status->value : null;
}

it('returns every match in source order under the inclusive policy', function (): void {
    $found = findAllAbortCalls(
        <<<'PHP'
        if ($user === null) {
            abort(401);
        }
        abort_unless($user->isAdmin(), 403);
        abort(404, "Not found");
        PHP,
        ConditionalContextPolicy::IncludeConditionalContexts,
    );

    expect($found)->toHaveCount(2)
        ->and(abortStatusOf($found[0]))->toBe(401)
        ->and(abortStatusOf($found[1]))->toBe(404);
});

it('descends into closures and match arms under the inclusive policy', function (): void {
    $found = findAllAbortCalls(
        <<<'PHP'
        $result = DB::transaction(function () { abort(409); });
        $value = match ($mode) { "strict" => abort(422), default => null };
        PHP,
        ConditionalContextPolicy::IncludeConditionalContexts,
    );

    expect($found)->toHaveCount(2);
});

it('returns an empty list when nothing matches', function (): void {
    $found = findAllAbortCalls(
        '$user = User::findOrFail($id);',
        ConditionalContextPolicy::IncludeConditionalContexts,
    );

    expect($found)->toBe([]);
});

it('collects only straight-line matches under the skipping policy', function (): void {
    $found = findAllAbortCalls(
        <<<'PHP'
        abort(403);
        if ($missing) {
            abort(404);
        }
        $value = $flag ? abort(409) : null;
        abort(422);
        PHP,
        ConditionalContextPolicy::SkipConditionalContexts,
    );

    expect($found)->toHaveCount(2)
        ->and(abortStatusOf($found[0]))->toBe(403)
        ->and(abortStatusOf($found[1]))->toBe(422);
});

it('collects multiple matches within a single statement under the skipping policy', function (): void {
    $found = new StatementNodeFinder()->findAll(
        parseStatements('respond($request->validate(["a" => "required"]), $request->validate(["b" => "required"]));'),
        ConditionalContextPolicy::SkipConditionalContexts,
        static fn(Node $node): bool => $node instanceof MethodCall
            && $node->name instanceof Identifier
            && $node->name->toLowerString() === 'validate',
    );

    expect($found)->toHaveCount(2);
});

// endregion
