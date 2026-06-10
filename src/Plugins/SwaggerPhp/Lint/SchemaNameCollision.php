<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Lint\RuleRegistry;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Stages\HarvestAuthoredAnnotationsStage;

/**
 * Registration stub for the `component.schema-name-collision` finding.
 *
 * Detection runs during spec generation in {@see HarvestAuthoredAnnotationsStage}: when a
 * hand-authored `#[OA\Schema]` / `@OA\Schema` is named the same as a convention-derived component
 * already registered under that key, the harvester keeps the existing (convention) schema and
 * emits this rule ID into the {@see FindingsCollector} — so the dropped authored definition is
 * diagnosable instead of silently shadowing the spec.
 *
 * This class exists solely to register the rule ID with the {@see RuleRegistry} so that:
 * - `#[IgnoreLint('component.schema-name-collision')]` is not flagged by `meta.unknown-rule`
 * - severity overrides in `config/openapi.lint.severity_overrides` apply
 * - the ID appears in the lint catalog
 */
final class SchemaNameCollision implements Rule
{
    public const string ID = 'component.schema-name-collision';

    public const int LEVEL = 1;

    /** Context key carrying the colliding schema name on the emitted finding. */
    public const string CONTEXT_SCHEMA = 'schema';

    public const string FIX_HINT = 'A hand-authored @OA\Schema is named the same as a component the generator already derives from your code, so the authored definition was dropped and references to that name resolve to the convention schema. Rename the authored schema (e.g. @OA\Schema(schema="...")), or remove it once inference covers it.';

    #[Override]
    public function id(): string
    {
        return self::ID;
    }

    #[Override]
    public function level(): int
    {
        return self::LEVEL;
    }

    #[Override]
    public function description(): string
    {
        return 'A hand-authored @OA\Schema collides with a convention-derived component of the same name; the authored definition was dropped and that name resolves to the convention schema.';
    }
}
