<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix;

use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Fix\Fix;
use Radiergummi\OpenApi\Lint\Fix\FixContext;
use Radiergummi\OpenApi\Lint\Fix\Fixer;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\OaRedundantOperationWithInference;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\AuthoredSchemaShape;

use function is_string;

/**
 * Removes a controller method's redundant operation-level swagger-php annotation, whichever shape it
 * took — the operation-level counterpart of {@see RedundantOaAnnotationFixer}.
 *
 * For the **attribute** shape it deletes every `#[OA\*]` group on the method (the `#[OA\Get]` and any
 * `#[OA\Response]`/`#[OA\Parameter]` siblings), delegating the per-group edits to
 * {@see OaAttributeRemover}. For the **docblock** shape it delegates to
 * {@see DocblockAnnotationRemover::removeForMethod()}, which strips every `@OA\…` block from the
 * method's PHPDoc.
 *
 * When reflection and the parsed source disagree, or the offending construct can't be located, the
 * fixer yields nothing and the finding is reported as unfixed.
 *
 * @internal
 */
final readonly class RedundantOaOperationFixer implements Fixer
{
    use ResolvesDeclaringFile;

    public function __construct(
        private DocblockAnnotationRemover $docblockRemover = new DocblockAnnotationRemover(),
        private OaAttributeRemover $attributeRemover = new OaAttributeRemover(),
    ) {}

    /**
     * @return iterable<Fix>
     */
    public function fix(Finding $finding, FixContext $context): iterable
    {
        $class = $finding->context[Finding::CONTEXT_SOURCE_CLASS] ?? null;
        $method = $finding->context[Finding::CONTEXT_SOURCE_MEMBER] ?? null;
        $shapeValue = $finding->context[OaRedundantOperationWithInference::CONTEXT_SHAPE] ?? null;

        if (!is_string($class) || !is_string($method) || !is_string($shapeValue)) {
            return [];
        }

        $shape = AuthoredSchemaShape::tryFrom($shapeValue);
        $file = $this->fileFor($class);

        if ($shape === null || $file === null) {
            return [];
        }

        return match ($shape) {
            AuthoredSchemaShape::Docblock => $this->docblockRemover->removeForMethod($finding, $class, $method, $file, $context),
            AuthoredSchemaShape::Attribute => $this->removeAttributes($finding, $class, $method, $file, $context),
        };
    }

    /**
     * @return list<Fix>
     */
    private function removeAttributes(Finding $finding, string $class, string $method, string $file, FixContext $context): array
    {
        $methodNode = $context->classNode($file, $class)?->getMethod($method);

        if ($methodNode === null) {
            return [];
        }

        $source = $context->source($file);
        $fixes = [];

        foreach ($methodNode->attrGroups as $group) {
            foreach ($this->attributeRemover->fixesForGroup($source, $group) as $operation) {
                $fixes[] = new Fix(
                    file: $file,
                    description: "Remove redundant #[OA\\*] operation attribute on {$class}::{$method}",
                    ruleId: $finding->ruleId,
                    operation: $operation,
                );
            }
        }

        return $fixes;
    }
}
