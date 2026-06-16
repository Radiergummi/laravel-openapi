<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

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
            SuppressionScope::ClassScope => $this->classMatches($finding),
            SuppressionScope::MethodScope => $this->fileMatches(
                $finding->location->file,
            ) && $this->coversLine($finding->location->line),
            SuppressionScope::PropertyScope => $this->structurallyMatches($finding),
        };
    }

    /**
     * Matches by source class stamped on the finding by the tree walker.
     */
    private function classMatches(Finding $finding): bool
    {
        $sourceClass = $finding->context[Finding::CONTEXT_SOURCE_CLASS] ?? null;

        return $sourceClass !== null && $sourceClass === $this->targetClass;
    }

    /**
     * Compares file paths, normalizing via realpath when the raw strings differ.
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
     * Whether the line falls inside the targeted method's body.
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
     * Matches by source class and member recorded in the finding's context.
     */
    private function structurallyMatches(Finding $finding): bool
    {
        $context = $finding->context;

        return ($context[Finding::CONTEXT_SOURCE_CLASS] ?? null) === $this->targetClass
            && ($context[Finding::CONTEXT_SOURCE_MEMBER] ?? null) === $this->targetMember;
    }
}
