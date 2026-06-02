<?php

declare(strict_types=1);

use Pest\Expectation;
use PHPUnit\Framework\Assert;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
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
    $findings = is_array($value)
        ? array_values($value)
        : array_values(iterator_to_array($value));

    $matched = array_filter(
        $findings,
        static fn(Finding $finding): bool => $finding->ruleId === $ruleId
            && ($messageContains === null || str_contains($finding->message, $messageContains)),
    );

    $criteria = $messageContains === null
        ? "rule ID '{$ruleId}'"
        : "rule ID '{$ruleId}' with a message containing '{$messageContains}'";

    $emitted = $findings === []
        ? 'no findings were emitted'
        : "the findings emitted were:\n  - " . implode("\n  - ", array_map(
            static fn(Finding $finding): string => "{$finding->ruleId}: {$finding->message}",
            $findings,
        ));

    Assert::assertNotEmpty(
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
