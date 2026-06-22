<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use Illuminate\Container\Attributes\Scoped;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Support\MethodBody\AstLiteralEvaluator;
use Throwable;

use function array_all;
use function array_is_list;
use function file_get_contents;
use function glob;
use function is_dir;
use function is_int;
use function is_string;
use function strtolower;

/**
 * Reads `database/migrations/*.php` statically to recover per-column schema signals (formats,
 * length, unsigned bounds, decimal scale, enum members, nullability, defaults, comments).
 *
 * Tier-1 bounded AST: it matches only a whitelist of `Blueprint` fluent-call shapes inside
 * `Schema::create()` / `Schema::table()` closures, keyed by the literal table name. No variable
 * tracking, no live connection. Dynamic table/column names, `->change()` alter chains, off-whitelist
 * macros, and unparseable files all degrade to no contribution (a debug log) rather than throwing.
 *
 * @internal
 */
#[Scoped]
final class MigrationColumnReader
{
    private const string SCHEMA_FACADE = 'Illuminate\Support\Facades\Schema';

    /**
     * Column-defining heads that imply a coarse type the cast would not.
     *
     * @var array<string, string>
     */
    private const array TYPE_HEADS = [
        'json' => 'object',
        'jsonb' => 'object',
        'year' => 'integer',
    ];

    /**
     * Column-defining heads carrying an OpenAPI string format.
     *
     * @var array<string, string>
     */
    private const array FORMAT_HEADS = [
        'uuid' => 'uuid',
        'foreignuuid' => 'uuid',
        'ulid' => 'uuid',
        'foreignulid' => 'uuid',
        'ipaddress' => 'ip',
        'date' => 'date',
        'datetime' => 'date-time',
        'datetimetz' => 'date-time',
        'timestamp' => 'date-time',
        'timestamptz' => 'date-time',
    ];

    /** @var array<string, true> Heads that mark an unsigned/auto-increment integer (minimum 0). */
    private const array UNSIGNED_HEADS = [
        'unsignedinteger' => true,
        'unsignedbiginteger' => true,
        'unsignedmediuminteger' => true,
        'unsignedsmallinteger' => true,
        'unsignedtinyinteger' => true,
        'unsigneddecimal' => true,
        'increments' => true,
        'bigincrements' => true,
        'mediumincrements' => true,
        'smallincrements' => true,
        'tinyincrements' => true,
    ];

    /**
     * Plain scalar column heads that carry no type/format signal on their own but are recognised
     * so their `->nullable()` / `->default()` / `->comment()` modifiers still attach.
     *
     * @var array<string, true>
     */
    private const array PLAIN_HEADS = [
        'integer' => true,
        'biginteger' => true,
        'mediuminteger' => true,
        'smallinteger' => true,
        'tinyinteger' => true,
        'boolean' => true,
        'float' => true,
        'double' => true,
        'text' => true,
        'mediumtext' => true,
        'longtext' => true,
        'tinytext' => true,
        'time' => true,
        'timetz' => true,
        'binary' => true,
    ];

    /**
     * Per-table column index, lazily built on first lookup.
     *
     * @var null|array<string, array<string, ColumnMetadata>>
     */
    private ?array $index = null;

    private readonly Parser $parser;

    private readonly NodeFinder $nodeFinder;

    public function __construct(
        private readonly ?string $migrationsDirectory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->parser = new ParserFactory()->createForNewestSupportedVersion();
        $this->nodeFinder = new NodeFinder();
    }

    /**
     * The column metadata declared for a table, keyed by column name. Empty when no migration
     * declares the table (or migration reading is unavailable).
     *
     * @return array<string, ColumnMetadata>
     */
    public function columnsForTable(string $table): array
    {
        $this->index ??= $this->buildIndex();

        return $this->index[$table] ?? [];
    }

    /**
     * Parses every migration file once, merging each `Schema::create/table` block into the index.
     *
     * @return array<string, array<string, ColumnMetadata>>
     */
    private function buildIndex(): array
    {
        if ($this->migrationsDirectory === null || !is_dir($this->migrationsDirectory)) {
            return [];
        }

        $files = glob($this->migrationsDirectory . '/*.php') ?: [];

        /** @var array<string, array<string, ColumnMetadata>> $index */
        $index = [];

        foreach ($files as $file) {
            foreach ($this->blocksIn($file) as [$table, $columns]) {
                $index[$table] = [...($index[$table] ?? []), ...$columns];
            }
        }

        return $index;
    }

    /**
     * Yields `[table, columns]` for each readable `Schema::create/table` block in one file.
     *
     * @return list<array{string, array<string, ColumnMetadata>}>
     */
    private function blocksIn(string $file): array
    {
        $source = file_get_contents($file);

        if (!is_string($source)) {
            return [];
        }

        try {
            $statements = $this->parser->parse($source);
        } catch (Throwable $exception) {
            $this->logger->debug('Migration column reader skipped a file that failed to parse.', [
                'file' => $file,
                'exception' => $exception->getMessage(),
            ]);

            return [];
        }

        if ($statements === null) {
            return [];
        }

        $traverser = new NodeTraverser(new NameResolver(options: ['preserveOriginalNames' => true]));
        $resolved = $traverser->traverse($statements);

        /** @var list<StaticCall> $calls */
        $calls = $this->nodeFinder->find(
            $resolved,
            fn(Node $node): bool => $this->isSchemaBlueprintCall($node),
        );

        $blocks = [];

        foreach ($calls as $call) {
            $block = $this->blockFrom($call, $file);

            if ($block !== null) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /** Whether the node is a `Schema::create(...)` / `Schema::table(...)` static call. */
    private function isSchemaBlueprintCall(Node $node): bool
    {
        return $node instanceof StaticCall
            && $node->class instanceof Name
            && $node->name instanceof Identifier
            && !$node->isFirstClassCallable()
            && ($node->name->toLowerString() === 'create' || $node->name->toLowerString() === 'table')
            && $this->facadeMatches($node->class);
    }

    /**
     * Reads one `Schema::create/table` block: a literal table name plus a closure whose
     * `$blueprint`-rooted call chains define the columns. Returns null for a dynamic table name or
     * an unreadable closure.
     *
     * @return null|array{string, array<string, ColumnMetadata>}
     */
    private function blockFrom(StaticCall $call, string $file): ?array
    {
        $arguments = $call->getArgs();

        if (!isset($arguments[0], $arguments[1])) {
            return null;
        }

        $table = $this->literalString($arguments[0]->value);

        if ($table === null) {
            $this->logger->debug('Migration column reader skipped a block with a dynamic table name.', [
                'file' => $file,
            ]);

            return null;
        }

        $closure = $arguments[1]->value;

        if (!$closure instanceof Closure || $closure->params === []) {
            return null;
        }

        $blueprintParameter = $closure->params[0]->var;

        if (!$blueprintParameter instanceof Variable || !is_string($blueprintParameter->name)) {
            return null;
        }

        return [$table, $this->columnsFromClosure($closure->stmts, $blueprintParameter->name, $file)];
    }

    /**
     * Walks a closure body's top-level statements, reading each `$blueprint`-rooted call chain into
     * a column. No variable tracking: only expression statements whose chain bottoms out at the
     * blueprint parameter are considered.
     *
     * @param array<Stmt> $statements
     *
     * @return array<string, ColumnMetadata>
     */
    private function columnsFromClosure(array $statements, string $blueprintName, string $file): array
    {
        $columns = [];

        foreach ($statements as $statement) {
            if (!$statement instanceof Stmt\Expression || !$statement->expr instanceof MethodCall) {
                continue;
            }

            $column = $this->columnFromChain($statement->expr, $blueprintName, $file);

            if ($column !== null) {
                [$name, $metadata] = $column;
                $columns[$name] = $metadata;
            }
        }

        return $columns;
    }

    /**
     * Resolves one fluent call chain (`$table->string('x', 64)->nullable()->comment('…')`) into a
     * `[column, metadata]` pair. Returns null when the chain is not blueprint-rooted, the head is
     * off-whitelist, the column name is dynamic, or the chain contains a `->change()` alter call.
     *
     * @return null|array{string, ColumnMetadata}
     */
    private function columnFromChain(MethodCall $chain, string $blueprintName, string $file): ?array
    {
        // Unwind modifier calls (the tail) down to the column-defining head, collecting modifiers.
        /** @var list<MethodCall> $modifiers */
        $modifiers = [];
        $node = $chain;

        while ($node->var instanceof MethodCall) {
            $modifiers[] = $node;
            $node = $node->var;
        }

        // The head's receiver must be the blueprint parameter variable.
        if (
            !$node->var instanceof Variable
            || $node->var->name !== $blueprintName
            || !$node->name instanceof Identifier
        ) {
            return null;
        }

        $head = strtolower($node->name->toString());
        $arguments = $node->getArgs();

        // The `->change()` alter-flow is Tier-2 territory; skip the whole column.
        if ($this->chainAltersExistingColumn($head, $modifiers)) {
            return null;
        }

        $columnName = isset($arguments[0]) ? $this->literalString($arguments[0]->value) : null;

        if ($columnName === null) {
            $this->logger->debug('Migration column reader skipped a column with a dynamic or absent name.', [
                'file' => $file,
                'call' => $head,
            ]);

            return null;
        }

        $metadata = $this->metadataForHead($head, $arguments);

        if ($metadata === null) {
            $this->logger->debug('Migration column reader saw an off-whitelist column definition.', [
                'file' => $file,
                'call' => $head,
            ]);

            return null;
        }

        return [$columnName, $this->applyModifiers($metadata, $modifiers)];
    }

    /**
     * Whether the head call or any modifier in the chain is `->change()` (an alter, not a create).
     *
     * @param list<MethodCall> $modifiers
     */
    private function chainAltersExistingColumn(string $head, array $modifiers): bool
    {
        if ($head === 'change') {
            return true;
        }

        foreach ($modifiers as $modifier) {
            if ($modifier->name instanceof Identifier && $modifier->name->toLowerString() === 'change') {
                return true;
            }
        }

        return false;
    }

    /**
     * Maps a column-defining head to its base metadata, or null when the head is off-whitelist.
     *
     * @param array<Arg> $arguments
     */
    private function metadataForHead(string $head, array $arguments): ?ColumnMetadata
    {
        // Heads that compute from their arguments take precedence over the flat lookup tables.
        $computed = match ($head) {
            'macaddress' => new ColumnMetadata(type: 'string', pattern: '^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$'),
            'decimal', 'unsigneddecimal' => $this->decimalMetadata($head, $arguments),
            'string', 'char' => $this->lengthMetadata($arguments),
            'enum', 'set' => $this->enumMetadata($arguments),
            default => null,
        };

        if ($computed !== null) {
            return $computed;
        }

        // Every whitelisted format is a string format, so the type is string too.
        if (isset(self::FORMAT_HEADS[$head])) {
            return new ColumnMetadata(type: 'string', format: self::FORMAT_HEADS[$head]);
        }

        if (isset(self::TYPE_HEADS[$head])) {
            return new ColumnMetadata(type: self::TYPE_HEADS[$head]);
        }

        if (isset(self::UNSIGNED_HEADS[$head])) {
            return new ColumnMetadata(minimum: 0);
        }

        // A recognised plain column adds no type signal but lets its modifiers (default, comment,
        // nullable) attach. Anything else is off-whitelist.
        return isset(self::PLAIN_HEADS[$head]) ? new ColumnMetadata() : null;
    }

    /**
     * `decimal($column, $precision, $scale)` → number with `multipleOf = 10^-scale`. Falls back to a
     * bare number type when the scale argument is absent or non-literal.
     *
     * @param array<Arg> $arguments
     */
    private function decimalMetadata(string $head, array $arguments): ColumnMetadata
    {
        $minimum = $head === 'unsigneddecimal' ? 0 : null;
        $scale = isset($arguments[2]) ? $this->literalInt($arguments[2]->value) : null;

        if ($scale === null || $scale < 0) {
            return new ColumnMetadata(type: 'number', minimum: $minimum);
        }

        return new ColumnMetadata(type: 'number', minimum: $minimum, multipleOf: 10 ** -$scale);
    }

    /**
     * `string($column, $length)` / `char($column, $length)` → maxLength when the length is a literal.
     *
     * @param array<Arg> $arguments
     */
    private function lengthMetadata(array $arguments): ColumnMetadata
    {
        $length = isset($arguments[1]) ? $this->literalInt($arguments[1]->value) : null;

        return new ColumnMetadata(maxLength: $length !== null && $length > 0 ? $length : null);
    }

    /**
     * `enum($column, [...])` / `set($column, [...])` → enum members, but only when every member is a
     * literal string. A dynamic member drops the enum entirely.
     *
     * @param array<Arg> $arguments
     */
    private function enumMetadata(array $arguments): ColumnMetadata
    {
        if (!isset($arguments[1])) {
            return new ColumnMetadata();
        }

        $members = $this->literal($arguments[1]->value);

        if (
            !is_array($members)
            || $members === []
            || !array_is_list($members)
            || !array_all($members, static fn(mixed $member): bool => is_string($member))
        ) {
            return new ColumnMetadata();
        }

        /** @var list<string> $members */
        return new ColumnMetadata(enum: $members);
    }

    /**
     * Folds the chain's modifier calls (`->nullable()`, `->default(...)`, `->comment('…')`) into the
     * base metadata. Unknown modifiers are ignored.
     *
     * @param list<MethodCall> $modifiers
     */
    private function applyModifiers(ColumnMetadata $metadata, array $modifiers): ColumnMetadata
    {
        $nullable = $metadata->nullable;
        $default = $metadata->default;
        $hasDefault = $metadata->hasDefault;
        $description = $metadata->description;

        foreach ($modifiers as $modifier) {
            if (!$modifier->name instanceof Identifier) {
                continue;
            }

            $arguments = $modifier->getArgs();

            switch ($modifier->name->toLowerString()) {
                case 'nullable':
                    // `->nullable(false)` re-asserts NOT NULL; treat a literal false as non-nullable.
                    $nullable = !isset($arguments[0]) || $this->literal($arguments[0]->value) !== false;

                    break;

                case 'default':
                    if (isset($arguments[0])) {
                        $value = $this->literalScalar($arguments[0]->value);

                        if ($value !== null || $arguments[0]->value instanceof Expr\ConstFetch) {
                            $default = $value;
                            $hasDefault = true;
                        }
                    }

                    break;

                case 'comment':
                    if (isset($arguments[0])) {
                        $comment = $this->literalString($arguments[0]->value);

                        if ($comment !== null) {
                            $description = $comment;
                        }
                    }

                    break;
            }
        }

        return new ColumnMetadata(
            type: $metadata->type,
            format: $metadata->format,
            pattern: $metadata->pattern,
            maxLength: $metadata->maxLength,
            minimum: $metadata->minimum,
            multipleOf: $metadata->multipleOf,
            enum: $metadata->enum,
            nullable: $nullable,
            default: $default,
            hasDefault: $hasDefault,
            description: $description,
        );
    }

    /**
     * Matches a static-call class against the Schema facade: the resolved FQCN or the bare global
     * alias. An import bound to a different `Schema` class never matches.
     */
    private function facadeMatches(Name $class): bool
    {
        $resolved = $class->toString();

        return $resolved === self::SCHEMA_FACADE || $resolved === 'Schema';
    }

    private function literalString(Expr $expression): ?string
    {
        $value = $this->literal($expression);

        return is_string($value) ? $value : null;
    }

    private function literalInt(Expr $expression): ?int
    {
        $value = $this->literal($expression);

        return is_int($value) ? $value : null;
    }

    /** A literal scalar (string/int/float/bool) or null; non-literal and array values yield null. */
    private function literalScalar(Expr $expression): string|int|float|bool|null
    {
        $value = $this->literal($expression);

        return $value === null || is_array($value) ? null : $value;
    }

    private function literal(Expr $expression): mixed
    {
        try {
            return AstLiteralEvaluator::evaluate($expression);
        } catch (Throwable) {
            return null;
        }
    }
}
