<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Override;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class Finding implements Arrayable, JsonSerializable
{
    /**
     * Context keys identifying the source property a finding was derived from.
     * Stamped by {@see Rules\AbstractFieldRule} so
     * a property-scoped suppression can match the finding structurally.
     */
    public const string CONTEXT_SOURCE_CLASS = 'sourceClass';

    public const string CONTEXT_SOURCE_MEMBER = 'sourceMember';

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $ruleId,
        public int $level,
        public string $message,
        public FindingLocation $location = new FindingLocation(),
        public ?string $fixHint = null,
        public array $context = [],
    ) {}

    /**
     * Return a copy of this finding whose location has been enriched with
     * default values (file, line, route info) from `$defaults`.
     *
     * Fields that the rule already set explicitly are preserved.
     */
    public function withLocationDefaults(FindingLocation $defaults): self
    {
        return new self(
            ruleId: $this->ruleId,
            level: $this->level,
            message: $this->message,
            location: $this->location->withDefaults($defaults),
            fixHint: $this->fixHint,
            context: $this->context,
        );
    }

    /**
     * Return a copy of this finding with a different severity level.
     *
     * All other fields are preserved. Used by the lint command to apply
     * `severity_overrides` from config after findings are collected.
     */
    public function withLevel(int $level): self
    {
        return new self(
            ruleId: $this->ruleId,
            level: $level,
            message: $this->message,
            location: $this->location,
            fixHint: $this->fixHint,
            context: $this->context,
        );
    }

    /**
     * Return a copy of this finding with extra context entries merged in.
     * Existing keys are overwritten by `$extra`.
     *
     * @param array<string, mixed> $extra
     */
    public function withMergedContext(array $extra): self
    {
        return new self(
            ruleId: $this->ruleId,
            level: $this->level,
            message: $this->message,
            location: $this->location,
            fixHint: $this->fixHint,
            context: [...$this->context, ...$extra],
        );
    }

    #[Override]
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'level' => $this->level,
            'message' => $this->message,
            'fix_hint' => $this->fixHint,
            'location' => $this->location->toArray(),
            'context' => $this->context,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
