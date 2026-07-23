<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Pest\Expectation;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use PHPUnit\Framework\Assert;
use Psr\Log\AbstractLogger;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponse;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\InferenceView;
use Radiergummi\OpenApi\Plugins\Core\Support\FormRequestRulesReader;
use Radiergummi\OpenApi\Plugins\Core\Support\FormRequestStaticRulesReader;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\InferenceRetention;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerationOrchestrator;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Radiergummi\OpenApi\Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

uses(TestCase::class)->in('Unit', 'Feature');

/**
 * Asserts that a rule emitted at least one {@see Finding} matching the given criteria.
 *
 * The value under expectation is the iterable of findings a rule produced — typically a
 * visitor method's return (a Generator). It is normalised to an array and searched for a
 * finding whose `ruleId` equals `$ruleId` and, when `$messageContains` is given, whose
 * `message` contains that substring. On failure every emitted finding is listed so the
 * mismatch is obvious.
 *
 *     expect($rule->checkField($field, $context))
 *         ->toEmitFinding(ruleId: 'field.description-missing', messageContains: 'status');
 */
expect()->extend('toEmitFinding', function (string $ruleId, ?string $messageContains = null): Expectation {
    /** @var iterable<Finding> $value */
    $value = $this->value;
    $findings = is_array($value) ? $value : iterator_to_array($value);

    $matched = array_any(
        $findings,
        static fn(Finding $finding): bool
            => $finding->ruleId === $ruleId
            && ($messageContains === null || str_contains($finding->message, $messageContains)),
    );

    $criteria = $messageContains === null
        ? "rule ID '{$ruleId}'"
        : "rule ID '{$ruleId}' with a message containing '{$messageContains}'";

    $emitted = $findings === []
        ? 'no findings were emitted'
        : "the findings emitted were:\n  - " . implode(
            "\n  - ",
            array_map(
                static fn(Finding $finding): string => "{$finding->ruleId}: {$finding->message}",
                $findings,
            ),
        );

    Assert::assertTrue(
        $matched,
        "Failed asserting that a finding with {$criteria} was emitted; {$emitted}.",
    );

    return $this;
});

/**
 * Resolves a named parameter of a closure to its ReflectionParameter.
 */
function reflectFunctionParameter(Closure $fn, string $name): ReflectionParameter
{
    foreach (new ReflectionFunction($fn)->getParameters() as $param) {
        if ($param->getName() === $name) {
            return $param;
        }
    }

    throw new RuntimeException("Parameter {$name} not found.");
}

/**
 * Runs the generator against the named spec (or the default) and returns the rendered
 * OpenAPI document as a parsed array.
 *
 * @return array<string, mixed>
 */
function generateSpec(?string $specName = null, string $environment = 'testing'): array
{
    $registry = app(SpecRegistry::class);
    $spec = $specName === null ? $registry->default() : $registry->get($specName);

    $env = $environment !== 'testing' ? $environment : app()->environment();

    return Yaml::parse(app(OpenApiGenerator::class)->generate($spec, $env)->toYaml());
}

/**
 * Parses a PHPDoc type expression into a phpstan/phpdoc-parser {@see TypeNode}, for the type-engine
 * tests that feed type strings through the resolver + schema engine.
 */
function parsePhpDocType(string $expression): TypeNode
{
    $config = new ParserConfig([]);
    $lexer = new Lexer($config);
    $typeParser = new TypeParser($config, new ConstExprParser($config));

    return $typeParser->parse(new TokenIterator($lexer->tokenize($expression)));
}

/**
 * A {@see FormRequestRulesReader} backed by a fresh scanner, for tests that construct the reader
 * directly. Production wires the whole chain onto the one scoped {@see MethodBodyScanner}; tests
 * that build it by hand supply their own scanner here.
 */
function formRequestRulesReader(): FormRequestRulesReader
{
    return new FormRequestRulesReader(new FormRequestStaticRulesReader(new MethodBodyScanner()));
}

/**
 * Generates a spec with the inferred view retained, then assembles the {@see InferenceView} from the
 * live registry and retention store, exactly as {@see LintRunner} does off its single generation.
 * The swagger-php migration tests use this to compare against pure inference without a second pass.
 */
function retainedInferenceView(string $spec = 'default', string $environment = 'testing'): InferenceView
{
    $document = app(OpenApiGenerationOrchestrator::class)
        ->generateOne($spec, $environment, retainInferredView: true);

    return InferenceView::fromRetention(
        $document,
        app(ComponentSchemaRegistry::class),
        app(InferenceRetention::class),
    );
}

/**
 * The JSON schema `$ref` of an inferred operation's response for the given status, or null.
 * Used by the swagger-php migration tests to compare a pre-merge inferred operation's response
 * schema against the harvested document's.
 */
function inferredResponseRef(?OA\Operation $operation, string $status = '200'): ?string
{
    foreach (is_array($operation?->responses) ? $operation->responses : [] as $response) {
        if ((string) $response->response !== $status || !is_array($response->content)) {
            continue;
        }

        foreach ($response->content as $mediaType) {
            $ref = $mediaType->schema instanceof OA\Schema ? $mediaType->schema->ref : null;

            if (is_string($ref) && str_starts_with($ref, '#/')) {
                return $ref;
            }
        }
    }

    return null;
}

/**
 * A PSR-3 logger that records each call as `['level' => ..., 'message' => ...]`, for asserting
 * on warnings emitted by code under test (e.g., the resolver fault boundary).
 *
 * @return AbstractLogger&object{records: list<array{level: mixed, message: string}>}
 */
function recordingLogger(): AbstractLogger
{
    return new class () extends AbstractLogger {
        /** @var list<array{level: mixed, message: string}> */
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = ['level' => $level, 'message' => (string) $message];
        }
    };
}

/** Unwraps the annotation a primary-response resolver carried, for tests asserting on the response. */
function primaryResponseAnnotation(?PrimaryResponse $result): ?OA\Response
{
    return $result?->response;
}
