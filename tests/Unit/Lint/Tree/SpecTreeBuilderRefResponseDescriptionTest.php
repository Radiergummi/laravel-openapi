<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Rules\ResponseDescriptionMissing;
use Radiergummi\OpenApi\Lint\Tree\SpecTreeBuilder;
use Radiergummi\OpenApi\Lint\Tree\SpecTreeWalker;
use Radiergummi\OpenApi\Lint\TreeIndex;

uses()->group('openapi', 'lint');

/**
 * Build a single-operation document and return the `response.description-missing`
 * findings the rule emits for it.
 *
 * @param list<OA\Response> $operationResponses
 * @param list<OA\Response> $componentResponses
 *
 * @return list<Finding>
 */
function refResponseDescriptionFindings(array $operationResponses, array $componentResponses): array
{
    $operation = new OA\Get([
        'path' => '/thing',
        'operationId' => 'getThing',
        'responses' => $operationResponses,
    ]);

    $document = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 't', 'version' => '1']),
        'paths' => [new OA\PathItem(['path' => '/thing', 'get' => $operation])],
        'components' => new OA\Components(['responses' => $componentResponses]),
    ]);

    $rule = new ResponseDescriptionMissing();
    $builder = new SpecTreeBuilder();
    $api = $builder->build($document, []);
    $index = TreeIndex::build($api, $document, [$rule->id], []);
    $context = new LintContext(api: $api, index: $index, rawSpec: $document, actionDescriptors: [], suppressions: []);

    return iterator_to_array(
        new SpecTreeWalker([$rule])->walk($api, $context),
        preserve_keys: false,
    );
}

it('does not fire on a $ref response whose referenced component carries a description', function (): void {
    $findings = refResponseDescriptionFindings(
        operationResponses: [
            new OA\Response(['response' => '200', 'description' => 'OK']),
            new OA\Response(['response' => '401', 'ref' => '#/components/responses/Unauthorized']),
        ],
        componentResponses: [
            new OA\Response(['response' => 'Unauthorized', 'description' => 'Unauthenticated']),
        ],
    );

    expect($findings)->toBe([]);
});

it('still fires on an inline response that genuinely has no description', function (): void {
    $findings = refResponseDescriptionFindings(
        operationResponses: [
            new OA\Response(['response' => '200']),
        ],
        componentResponses: [],
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('response.description-missing')
        ->and($findings[0]->message)->toContain('200');
});

it('does not crash and does not silently pass when a $ref response is dangling', function (): void {
    $findings = refResponseDescriptionFindings(
        operationResponses: [
            new OA\Response(['response' => '401', 'ref' => '#/components/responses/Missing']),
        ],
        componentResponses: [],
    );

    // A dangling $ref cannot supply a description, so the description check still fires —
    // the broken ref itself is `ref.broken`'s job, not this rule's.
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('response.description-missing');
});
