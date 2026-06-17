<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Override;
use Radiergummi\OpenApi\Contracts\Lint\Severity;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class Finding implements Arrayable, JsonSerializable
{
    /**
     * Context keys stamped by {@see Rules\AbstractFieldRule} for property-scoped suppression matching.
     */
    public const string CONTEXT_SOURCE_CLASS = 'sourceClass';

    public const string CONTEXT_SOURCE_MEMBER = 'sourceMember';

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $ruleId,
        public Severity $severity,
        public string $message,
        public FindingLocation $location = new FindingLocation(),
        public ?string $fixHint = null,
        public array $context = [],
        public ?string $spec = null,
    ) {}

    /**
     * Return a copy with location fields back-filled from `$defaults`; explicitly-set fields are preserved.
     */
    public function withLocationDefaults(FindingLocation $defaults): self
    {
        return new self(
            ruleId: $this->ruleId,
            severity: $this->severity,
            message: $this->message,
            location: $this->location->withDefaults($defaults),
            fixHint: $this->fixHint,
            context: $this->context,
            spec: $this->spec,
        );
    }

    /**
     * Return a copy with a different severity (used to apply config `severity_overrides`).
     */
    public function withSeverity(Severity $severity): self
    {
        return new self(
            ruleId: $this->ruleId,
            severity: $severity,
            message: $this->message,
            location: $this->location,
            fixHint: $this->fixHint,
            context: $this->context,
            spec: $this->spec,
        );
    }

    /**
     * Return a copy with extra context entries merged in; existing keys are overwritten.
     *
     * @param array<string, mixed> $extra
     */
    public function withMergedContext(array $extra): self
    {
        return new self(
            ruleId: $this->ruleId,
            severity: $this->severity,
            message: $this->message,
            location: $this->location,
            fixHint: $this->fixHint,
            context: [...$this->context, ...$extra],
            spec: $this->spec,
        );
    }

    public function withSpec(?string $spec): self
    {
        return new self(
            ruleId: $this->ruleId,
            severity: $this->severity,
            message: $this->message,
            location: $this->location,
            fixHint: $this->fixHint,
            context: $this->context,
            spec: $spec,
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    #[Override]
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'level' => $this->severity->value,
            'message' => $this->message,
            'fix_hint' => $this->fixHint,
            'location' => $this->location->toArray(),
            'context' => $this->context,
            'spec' => $this->spec,
        ];
    }
}
