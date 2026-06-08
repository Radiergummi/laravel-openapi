<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use ReflectionClass;

use function array_any;
use function is_a;

final readonly class LintContext
{
    /**
     * @param list<ActionDescriptor>         $actionDescriptors
     * @param list<SuppressionDirective>     $suppressions
     * @param list<class-string>             $payloadClasses          Base types whose subtypes Core
     *                                                                treats as request payloads.
     * @param array<class-string, OA\Schema> $inferenceSchemasByClass Inference-only component schema
     *                                                                per source class, for rules
     *                                                                comparing authored annotations
     *                                                                against inference (the
     *                                                                migration family, via
     *                                                                {@see NeedsInferenceDocument}).
     *                                                                Built once per spec by the
     *                                                                runner; empty unless an active
     *                                                                rule asked for it.
     * @param ReflectionAttributeCache       $reflectionCache         Per-walk cache for sibling
     *                                                                rules to share `getAttributes()`
     *                                                                results and {@see ReflectionClass}
     *                                                                instances. A fresh cache per
     *                                                                context keeps the lifecycle tied
     *                                                                to one walk.
     */
    public function __construct(
        public ApiNode $api,
        public TreeIndex $index,
        public OA\OpenApi $rawSpec,
        public array $actionDescriptors,
        public array $suppressions,
        public array $payloadClasses = [],
        public array $inferenceSchemasByClass = [],
        public ReflectionAttributeCache $reflectionCache = new ReflectionAttributeCache(),
    ) {}

    /**
     * Whether the given class is a request-payload class whose properties Core
     * should introspect for field attributes — i.e. a subtype of one of the
     * payload base classes contributed by plugins.
     *
     * @param class-string $class
     */
    public function isPayloadClass(string $class): bool
    {
        return array_any(
            $this->payloadClasses,
            static fn(string $base): bool => is_a($class, $base, allow_string: true),
        );
    }
}
