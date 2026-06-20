<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint;

use OpenApi\Annotations as OA;
use Override;
use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Contracts\Lint\NeedsInferenceDocument;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\Visitors\ApiRule;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Stages\HarvestAuthoredAnnotationsStage;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\ConfigSnippetRenderer;

use function sprintf;

/**
 * Flags hand-authored document-level `@OA\*` annotations whose information has no authoring-attribute
 * equivalent and instead belongs in `config/openapi.php`: `@OA\Info` → `openapi.info`, `@OA\Server`
 * → `openapi.servers`, root `@OA\Tag` → `openapi.tags`, and `@OA\SecurityScheme` →
 * `openapi.security_schemes`. Each finding carries a paste-ready config snippet in its fix hint.
 *
 * Report + scaffold only: there is no auto-fix. The AST Fix backend edits PHP classes, and
 * `config/openapi.php` is a classless `return [...]` file; removing the annotation without writing
 * the config would lose the document metadata, so the rule never removes anything.
 *
 * @internal
 */
final class DocumentAnnotationMigration implements Rule, ApiRule, NeedsInferenceDocument
{
    public string $id = 'migration.document-annotation-in-config';
    public Severity $severity = Severity::Improvable;
    public string $description = 'A document-level @OA\\Info / Server / SecurityScheme / root Tag annotation whose metadata belongs in config/openapi.php.';

    private readonly ConfigSnippetRenderer $renderer;

    public function __construct(
        private readonly AuthoredAnnotationScanner $scanner,
    ) {
        $this->renderer = new ConfigSnippetRenderer();
    }

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkApi(ApiNode $api, LintContext $context): iterable
    {
        $authored = $this->scanner->documentAnnotations();

        if ($authored->info !== null) {
            yield $this->finding(
                'an @OA\\Info annotation',
                'openapi.info',
                $this->renderer->info($authored->info),
                $authored->info,
            );
        }

        foreach ($authored->servers as $server) {
            yield $this->finding(
                'an @OA\\Server annotation',
                'openapi.servers',
                $this->renderer->servers($server),
                $server,
            );
        }

        foreach ($authored->securitySchemes as $scheme) {
            yield $this->finding(
                sprintf("an @OA\\SecurityScheme annotation ('%s')", (string) $scheme->securityScheme),
                'openapi.security_schemes',
                $this->renderer->securityScheme($scheme),
                $scheme,
            );
        }

        foreach ($authored->rootTags as $tag) {
            yield $this->finding(
                sprintf("a root @OA\\Tag annotation ('%s')", (string) $tag->name),
                'openapi.tags',
                $this->renderer->tag($tag),
                $tag,
            );
        }
    }

    private function finding(string $what, string $configKey, string $snippet, OA\AbstractAnnotation $annotation): Finding
    {
        return new Finding(
            ruleId: $this->id,
            severity: $this->severity,
            message: sprintf(
                '%s declares document-level metadata that belongs in the `%s` config key.',
                $what,
                $configKey,
            ),
            location: new FindingLocation(file: $annotation->_context->filename ?? null),
            fixHint: sprintf("Move it into config/openapi.php:\n\n%s", $snippet),
        );
    }



    /**
     * @return list<class-string<SpecStage>>
     */
    #[Override]
    public function excludedStages(): array
    {
        return [HarvestAuthoredAnnotationsStage::class];
    }

}
