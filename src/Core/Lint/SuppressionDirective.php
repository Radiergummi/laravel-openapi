<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint;

use function realpath;

/**
 * A single `#[IgnoreLint]` attribute resolved against its reflection target.
 *
 * Collected by {@see SuppressionCollector}; consumed by the lint command and the `meta.*` rules.
 */
final readonly class SuppressionDirective
{
    public function __construct(
        public string $ruleId,
        public ?string $reason,
        public SuppressionScope $scope,
        public string $file,
        public ?int $line,
        public string $targetClass,
        /** Method or property name; null for class scope. */
        public ?string $targetMember = null,
        /** Source line range of the target method (method scope only). */
        public ?int $methodStartLine = null,
        public ?int $methodEndLine = null,
    ) {}

    /**
     * Determine whether this directive silences the given finding.
     */
    public function suppresses(Finding $finding): bool
    {
        if ($finding->ruleId !== $this->ruleId) {
            return false;
        }

        return match ($this->scope) {
            SuppressionScope::ClassScope => $this->fileMatches(
                $finding->location->file,
            ),
            SuppressionScope::MethodScope => $this->fileMatches(
                $finding->location->file,
            ) && $this->coversLine($finding->location->line),
            SuppressionScope::PropertyScope => $this->structurallyMatches($finding),
        };
    }

    /**
     * Compare a finding's source file to the directive's, normalizing via realpath when the raw
     * strings differ.
     */
    private function fileMatches(?string $findingFile): bool
    {
        if ($findingFile === null) {
            return false;
        }

        if ($findingFile === $this->file) {
            return true;
        }

        $a = realpath($findingFile);
        $b = realpath($this->file);

        return $a !== false && $b !== false && $a === $b;
    }

    /**
     * Whether the given source line falls inside the targeted method's body.
     */
    private function coversLine(?int $line): bool
    {
        if ($this->methodStartLine === null || $this->methodEndLine === null) {
            return false;
        }

        return $line !== null
            && $line >= $this->methodStartLine
            && $line <= $this->methodEndLine;
    }

    /**
     * Property scope matches structurally: the finding must record the same source class and
     * member in its context. `field.*` findings carry these keys for exactly this purpose.
     */
    private function structurallyMatches(Finding $finding): bool
    {
        $context = $finding->context;

        return ($context[Finding::CONTEXT_SOURCE_CLASS] ?? null) === $this->targetClass
            && ($context[Finding::CONTEXT_SOURCE_MEMBER] ?? null) === $this->targetMember;
    }
}
