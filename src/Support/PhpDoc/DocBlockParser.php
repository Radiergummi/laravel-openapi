<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\PhpDoc;

use Illuminate\Container\Attributes\Scoped;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use Throwable;

use function array_key_exists;

/**
 * Parses raw PHPDoc comments into {@see ParsedDocBlock}s via phpstan/phpdoc-parser.
 *
 * Bound as a scoped singleton; the per-run parse cache resets between generation
 * runs under Octane.
 *
 * @internal
 */
#[Scoped]
final class DocBlockParser
{
    /**
     * @var array<string, ParsedDocBlock>
     */
    private array $cache = [];

    public function __construct(
        private readonly Lexer $lexer,
        private readonly PhpDocParser $parser,
    ) {}

    public static function create(): self
    {
        $config = new ParserConfig([]);
        $constExpr = new ConstExprParser($config);

        return new self(
            lexer: new Lexer($config),
            parser: new PhpDocParser($config, new TypeParser($config, $constExpr), $constExpr),
        );
    }

    public function parse(string $docComment): ParsedDocBlock
    {
        if (array_key_exists($docComment, $this->cache)) {
            return $this->cache[$docComment];
        }

        try {
            $tokens = new TokenIterator($this->lexer->tokenize($docComment));

            return $this->cache[$docComment] = new ParsedDocBlock($this->parser->parse($tokens));
        } catch (Throwable) {
            // Malformed comment — behave as "no tags", never break the pipeline.
            return $this->cache[$docComment] = ParsedDocBlock::empty();
        }
    }
}
