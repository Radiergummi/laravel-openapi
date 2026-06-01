<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Tree;

use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Visitors\ApiRule;
use Radiergummi\OpenApi\Lint\Visitors\ComponentSchemaRule;
use Radiergummi\OpenApi\Lint\Visitors\ExampleRule;
use Radiergummi\OpenApi\Lint\Visitors\FieldRule;
use Radiergummi\OpenApi\Lint\Visitors\Finalizable;
use Radiergummi\OpenApi\Lint\Visitors\HeaderRule;
use Radiergummi\OpenApi\Lint\Visitors\LinkRule;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule;
use Radiergummi\OpenApi\Lint\Visitors\ParameterRule;
use Radiergummi\OpenApi\Lint\Visitors\QueryParameterRule;
use Radiergummi\OpenApi\Lint\Visitors\RequestBodyRule;
use Radiergummi\OpenApi\Lint\Visitors\Resettable;
use Radiergummi\OpenApi\Lint\Visitors\ResponseRule;
use Radiergummi\OpenApi\Lint\Visitors\WebhookRule;

use function spl_object_id;

/**
 * Performs a single depth-first traversal of the domain tree, dispatching to rules at each node
 * level. Rules declare their interest in specific nodes by implementing visitor interfaces.
 */
final class SpecTreeWalker
{
    /**
     * All visitor interfaces a rule may implement. Used to bucket rules on construction.
     *
     * @var list<class-string>
     */
    private const array VISITOR_INTERFACES = [
        ApiRule::class,
        OperationRule::class,
        ParameterRule::class,
        QueryParameterRule::class,
        RequestBodyRule::class,
        ResponseRule::class,
        FieldRule::class,
        HeaderRule::class,
        LinkRule::class,
        ExampleRule::class,
        ComponentSchemaRule::class,
        WebhookRule::class,
    ];

    /**
     * Rules indexed by visitor interface for O(1) dispatch.
     *
     * @var array<class-string<key-of<self::VISITOR_INTERFACES>>, list<Rule>>
     */
    private array $visitors = [];

    /**
     * @param list<Rule> $rules
     */
    public function __construct(array $rules)
    {
        foreach ($rules as $rule) {
            foreach (self::VISITOR_INTERFACES as $interface) {
                if ($rule instanceof $interface) {
                    $this->visitors[$interface][] = $rule;
                }
            }
        }
    }

    /**
     * Walk the domain tree and dispatch to all registered rules.
     *
     * @return iterable<Finding>
     */
    public function walk(ApiNode $api, LintContext $context): iterable
    {
        // Step 0: Reset stateful rules for clean state in long-lived processes
        $this->forEachUniqueRule(static function (Rule $rule): void {
            if ($rule instanceof Resettable) {
                $rule->reset();
            }
        });

        // Step 1: ApiRules
        yield from $this->dispatchApi($api, $context);

        // Step 2: Operations
        foreach ($api->operations as $operation) {
            yield from $this->walkOperation($operation, $context);
        }

        // Step 3: Component schemas
        foreach ($api->components as $component) {
            yield from $this->dispatchComponentSchema($component, $context);
            yield from $this->walkFields(
                $component->fields,
                $context,
                sourceClass: $component->sourceClass,
            );
        }

        // Step 4: Webhooks
        foreach ($api->webhooks as $webhook) {
            yield from $this->dispatchWebhook($webhook, $context);
            yield from $this->walkOperation($webhook->operation, $context);
        }

        // Step 5: Finalize
        yield from $this->dispatchFinalize($context);
    }

    /**
     * Call $callback once for every registered rule (deduplicated).
     *
     * @param callable(Rule): void $callback
     */
    private function forEachUniqueRule(callable $callback): void
    {
        foreach ($this->uniqueRules() as $rule) {
            $callback($rule);
        }
    }

    /**
     * Iterate over every registered rule exactly once, regardless of how many visitor interfaces it
     * implements. Uses `spl_object_id` to deduplicate across buckets.
     *
     * @return iterable<Rule>
     */
    private function uniqueRules(): iterable
    {
        $seen = [];

        foreach ($this->visitors as $rules) {
            foreach ($rules as $rule) {
                $id = spl_object_id($rule);

                if (isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                yield $rule;
            }
        }
    }

    /**
     * @return iterable<Finding>
     */
    private function dispatchApi(ApiNode $node, LintContext $context): iterable
    {
        foreach ($this->visitors[ApiRule::class] ?? [] as $rule) {
            assert($rule instanceof ApiRule);
            yield from $rule->checkApi($node, $context);
        }
    }

    /**
     * @return iterable<Finding>
     */
    private function walkOperation(OperationNode $operation, LintContext $context): iterable
    {
        $location = FindingLocation::fromOperation($operation);

        yield from $this->enrichAll(
            $this->dispatchOperation($operation, $context),
            $location,
        );

        // region Path parameters
        foreach ($operation->parameters as $param) {
            yield from $this->enrichAll(
                $this->dispatchParameter($param, $context),
                $location,
            );

            foreach ($param->examples as $example) {
                yield from $this->enrichAll(
                    $this->dispatchExample($example, $context),
                    $location,
                );
            }
        }
        // endregion

        // region Query parameters
        foreach ($operation->queryParameters as $parameters) {
            yield from $this->enrichAll(
                $this->dispatchQueryParameter($parameters, $context),
                $location,
            );

            foreach ($parameters->examples as $example) {
                yield from $this->enrichAll(
                    $this->dispatchExample($example, $context),
                    $location,
                );
            }
        }
        // endregion

        // region Request body
        if ($operation->requestBody !== null) {
            yield from $this->enrichAll(
                $this->dispatchRequestBody($operation->requestBody, $context),
                $location,
            );

            foreach ($operation->requestBody->examples as $example) {
                yield from $this->enrichAll(
                    $this->dispatchExample($example, $context),
                    $location,
                );
            }

            yield from $this->walkFields(
                $operation->requestBody->fields,
                $context,
                $location,
            );
        }
        // endregion

        // region Responses
        foreach ($operation->responses as $response) {
            yield from $this->enrichAll(
                $this->dispatchResponse($response, $context),
                $location,
            );

            foreach ($response->examples as $example) {
                yield from $this->enrichAll(
                    $this->dispatchExample($example, $context),
                    $location,
                );
            }

            yield from $this->walkFields(
                $response->fields,
                $context,
                $location,
            );

            foreach ($response->headers as $header) {
                yield from $this->enrichAll(
                    $this->dispatchHeader($header, $context),
                    $location,
                );
            }

            foreach ($response->links as $link) {
                yield from $this->enrichAll(
                    $this->dispatchLink($link, $context),
                    $location,
                );
            }
        }
        // endregion
    }

    /**
     * Enrich each finding with contextual location defaults.
     *
     * When `$defaults` is null (e.g., component schema context without an operation), findings pass
     * through unchanged.
     *
     * @param iterable<Finding> $findings
     *
     * @return iterable<Finding>
     */
    private function enrichAll(iterable $findings, ?FindingLocation $defaults): iterable
    {
        if ($defaults === null) {
            yield from $findings;

            return;
        }

        foreach ($findings as $finding) {
            yield $finding->withLocationDefaults($defaults);
        }
    }

    /**
     * @return iterable<Finding>
     */
    private function dispatchOperation(OperationNode $node, LintContext $context): iterable
    {
        foreach ($this->visitors[OperationRule::class] ?? [] as $rule) {
            assert($rule instanceof OperationRule);
            yield from $rule->checkOperation($node, $context);
        }
    }

    /**
     * @return iterable<Finding>
     */
    private function dispatchParameter(ParameterNode $node, LintContext $context): iterable
    {
        foreach ($this->visitors[ParameterRule::class] ?? [] as $rule) {
            assert($rule instanceof ParameterRule);
            yield from $rule->checkParameter($node, $context);
        }
    }

    /**
     * @return iterable<Finding>
     */
    private function dispatchExample(ExampleNode $node, LintContext $context): iterable
    {
        foreach ($this->visitors[ExampleRule::class] ?? [] as $rule) {
            assert($rule instanceof ExampleRule);
            yield from $rule->checkExample($node, $context);
        }
    }

    /**
     * @return iterable<Finding>
     */
    private function dispatchQueryParameter(QueryParameterNode $node, LintContext $context): iterable
    {
        foreach ($this->visitors[QueryParameterRule::class] ?? [] as $rule) {
            assert($rule instanceof QueryParameterRule);
            yield from $rule->checkQueryParameter($node, $context);
        }
    }

    /**
     * @return iterable<Finding>
     */
    private function dispatchRequestBody(RequestBodyNode $node, LintContext $context): iterable
    {
        foreach ($this->visitors[RequestBodyRule::class] ?? [] as $rule) {
            assert($rule instanceof RequestBodyRule);
            yield from $rule->checkRequestBody($node, $context);
        }
    }

    /**
     * Walk a list of FieldNode subtrees and dispatch each registered FieldRule / ExampleRule.
     *
     * @param list<FieldNode>      $fields           Fields to walk
     * @param null|FindingLocation $locationDefaults Contextual defaults to merge into findings
     * @param null|class-string    $sourceClass      When set, findings are stamped with
     *                                               CONTEXT_SOURCE_CLASS so a class-scope
     *                                               #[IgnoreLint] directive can match
     *                                               structurally.
     *
     * @return iterable<Finding>
     */
    private function walkFields(
        array $fields,
        LintContext $context,
        ?FindingLocation $locationDefaults = null,
        ?string $sourceClass = null,
    ): iterable {
        // Lazy skip: no field or example rules registered → skip entire subtree
        if (
            ($this->visitors[FieldRule::class] ?? []) === []
            && ($this->visitors[ExampleRule::class] ?? []) === []
        ) {
            return;
        }

        foreach ($fields as $field) {
            // Extend defaults with the field's JSON pointer so rules don't have to construct it
            // manually. Rules that set a more specific pointer (e.g., ".../enum/0") will still win
            // via null-coalescing.
            $fieldDefaults = $locationDefaults !== null
                ? new FindingLocation(jsonPointer: $field->pointer())->withDefaults($locationDefaults)
                : new FindingLocation(jsonPointer: $field->pointer());

            $contextStamp = $sourceClass !== null
                ? [
                    Finding::CONTEXT_SOURCE_CLASS => $sourceClass,
                    Finding::CONTEXT_SOURCE_MEMBER => $field->name,
                ]
                : [];

            foreach ($this->enrichAll($this->dispatchField($field, $context), $fieldDefaults) as $finding) {
                yield $contextStamp === [] ? $finding : $finding->withMergedContext($contextStamp);
            }

            foreach ($field->examples as $example) {
                foreach ($this->enrichAll($this->dispatchExample($example, $context), $fieldDefaults) as $finding) {
                    yield $contextStamp === [] ? $finding : $finding->withMergedContext($contextStamp);
                }
            }

            // Recurse into nested object fields, carrying the same sourceClass — nested fields
            // belong to the same component schema. The MEMBER part is overwritten at each level
            // by the next iteration's $contextStamp.
            yield from $this->walkFields(
                $field->children,
                $context,
                $fieldDefaults,
                sourceClass: $sourceClass,
            );
        }
    }

    /**
     * @return iterable<Finding>
     */
    private function dispatchField(FieldNode $node, LintContext $context): iterable
    {
        foreach ($this->visitors[FieldRule::class] ?? [] as $rule) {
            assert($rule instanceof FieldRule);
            yield from $rule->checkField($node, $context);
        }
    }

    /**
     * @return iterable<Finding>
     */
    private function dispatchResponse(ResponseNode $node, LintContext $context): iterable
    {
        foreach ($this->visitors[ResponseRule::class] ?? [] as $rule) {
            assert($rule instanceof ResponseRule);
            yield from $rule->checkResponse($node, $context);
        }
    }

    /**
     * @return iterable<Finding>
     */
    private function dispatchHeader(HeaderNode $node, LintContext $context): iterable
    {
        foreach ($this->visitors[HeaderRule::class] ?? [] as $rule) {
            assert($rule instanceof HeaderRule);
            yield from $rule->checkHeader($node, $context);
        }
    }

    /**
     * @return iterable<Finding>
     */
    private function dispatchLink(LinkNode $node, LintContext $context): iterable
    {
        foreach ($this->visitors[LinkRule::class] ?? [] as $rule) {
            assert($rule instanceof LinkRule);
            yield from $rule->checkLink($node, $context);
        }
    }

    /**
     * @return iterable<Finding>
     */
    private function dispatchComponentSchema(ComponentSchemaNode $node, LintContext $context): iterable
    {
        foreach ($this->visitors[ComponentSchemaRule::class] ?? [] as $rule) {
            assert($rule instanceof ComponentSchemaRule);
            yield from $rule->checkComponentSchema($node, $context);
        }
    }

    /**
     * @return iterable<Finding>
     */
    private function dispatchWebhook(WebhookNode $node, LintContext $context): iterable
    {
        foreach ($this->visitors[WebhookRule::class] ?? [] as $rule) {
            assert($rule instanceof WebhookRule);
            yield from $rule->checkWebhook($node, $context);
        }
    }

    /**
     * Dispatch Finalize to all Finalizable rules (deduplicated — a rule implementing multiple
     * interfaces is only finalized once).
     *
     * @return iterable<Finding>
     */
    private function dispatchFinalize(LintContext $context): iterable
    {
        foreach ($this->uniqueRules() as $rule) {
            if ($rule instanceof Finalizable) {
                yield from $rule->finalize($context);
            }
        }
    }
}
