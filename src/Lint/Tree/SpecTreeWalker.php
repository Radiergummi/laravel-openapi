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
        // Reset stateful rules so long-lived processes get a clean slate per run.
        $this->forEachUniqueRule(static function (Rule $rule): void {
            if ($rule instanceof Resettable) {
                $rule->reset();
            }
        });

        yield from $this->dispatchApi($api, $context);

        foreach ($api->operations as $operation) {
            yield from $this->walkOperation($operation, $context);
        }

        foreach ($api->components as $component) {
            yield from $this->dispatchComponentSchema($component, $context);
            yield from $this->walkFields(
                $component->fields,
                $context,
                sourceClass: $component->sourceClass,
            );
        }

        foreach ($api->webhooks as $webhook) {
            yield from $this->dispatchWebhook($webhook, $context);
            yield from $this->walkOperation($webhook->operation, $context);
        }

        yield from $this->dispatchFinalize($context);
    }

    /**
     * @param callable(Rule): void $callback
     */
    private function forEachUniqueRule(callable $callback): void
    {
        foreach ($this->uniqueRules() as $rule) {
            $callback($rule);
        }
    }

    /**
     * Yields each registered rule exactly once, deduplicated across visitor buckets.
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
        $sourceClass = $operation->descriptor?->controller?->getName();

        // Every finding produced under this operation is stamped (as a fallback) with the
        // controller class, so a controller-level #[IgnoreLint] can match it structurally. Findings
        // that already carry a more-specific source class (e.g., response/request-body fields
        // attributed to their component schema) keep theirs.
        yield from $this->stampSourceClass(
            $this->walkOperationNodes($operation, $context),
            $sourceClass,
        );
    }

    /**
     * Stamps `CONTEXT_SOURCE_CLASS` on each finding as a fallback. Findings that already carry
     * the key keep their more-specific value.
     *
     * @param iterable<Finding> $findings
     *
     * @return iterable<Finding>
     */
    private function stampSourceClass(iterable $findings, ?string $sourceClass): iterable
    {
        if ($sourceClass === null) {
            yield from $findings;

            return;
        }

        foreach ($findings as $finding) {
            yield isset($finding->context[Finding::CONTEXT_SOURCE_CLASS])
                ? $finding
                : $finding->withMergedContext([Finding::CONTEXT_SOURCE_CLASS => $sourceClass]);
        }
    }

    /**
     * Emits findings for an operation and all sub-nodes, without source-class stamping (see
     * {@see walkOperation}).
     *
     * @return iterable<Finding>
     */
    private function walkOperationNodes(OperationNode $operation, LintContext $context): iterable
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
     * Merges location defaults into each finding; passes through unchanged when `$defaults` is null.
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
     * @param list<FieldNode>   $fields
     * @param null|class-string $sourceClass When set, stamps findings with CONTEXT_SOURCE_CLASS.
     *
     * @return iterable<Finding>
     */
    private function walkFields(
        array $fields,
        LintContext $context,
        ?FindingLocation $locationDefaults = null,
        ?string $sourceClass = null,
    ): iterable {
        // No rules registered for fields or examples: skip entire subtree.
        if (
            ($this->visitors[FieldRule::class] ?? []) === []
            && ($this->visitors[ExampleRule::class] ?? []) === []
        ) {
            return;
        }

        foreach ($fields as $field) {
            // Extend defaults with the field's JSON pointer; rules may still override with a
            // more specific pointer.
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

            // Recurse carrying the same sourceClass; MEMBER is overwritten at each level.
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
