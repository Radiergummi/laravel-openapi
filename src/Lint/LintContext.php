<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Routing\ActionDescriptor;

use function array_any;
use function is_a;

final readonly class LintContext
{
    /**
     * @param list<ActionDescriptor>     $actionDescriptors
     * @param list<SuppressionDirective> $suppressions
     * @param list<class-string>         $payloadClasses    Base types whose subtypes are treated as request payloads.
     * @param InferenceView              $inference         For rules comparing authored annotations against inference.
     * @param ReflectionAttributeCache   $reflectionCache   Shared per-walk cache for `getAttributes()` results.
     */
    public function __construct(
        public ApiNode $api,
        public TreeIndex $index,
        public OA\OpenApi $rawSpec,
        public array $actionDescriptors,
        public array $suppressions,
        public array $payloadClasses = [],
        public InferenceView $inference = new InferenceView(),
        public ReflectionAttributeCache $reflectionCache = new ReflectionAttributeCache(),
    ) {}

    /**
     * Whether the class is a subtype of one of the payload base classes contributed by plugins.
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
