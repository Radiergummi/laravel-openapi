<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint;

use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Stages\HarvestAuthoredAnnotationsStage;

/**
 * Registration stub for the `component.schema-name-collision` finding.
 *
 * Detection runs in {@see HarvestAuthoredAnnotationsStage}: when a hand-authored `@OA\Schema`
 * collides with a convention-derived component, the harvester keeps the convention schema and
 * emits this rule ID. This stub registers the ID so `#[IgnoreLint]` is accepted, severity
 * overrides apply, and the ID appears in the lint catalog.
 */
final class SchemaNameCollision implements Rule
{
    public string $id = self::ID;
    public Severity $severity = self::SEVERITY;
    public string $description = 'A hand-authored @OA\Schema collides with a convention-derived component of the same name; the authored definition was dropped and that name resolves to the convention schema.';

    public const string ID = 'component.schema-name-collision';

    public const Severity SEVERITY = Severity::Degraded;

    /** Context key carrying the colliding schema name on the emitted finding. */
    public const string CONTEXT_SCHEMA = 'schema';

    public const string FIX_HINT = 'A hand-authored @OA\Schema is named the same as a component the generator already derives from your code, so the authored definition was dropped and references to that name resolve to the convention schema. Rename the authored schema (e.g., @OA\Schema(schema="...")), or remove it once inference covers it.';



}
