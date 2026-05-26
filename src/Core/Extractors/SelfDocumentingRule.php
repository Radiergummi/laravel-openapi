<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Extractors;

/**
 * Extension interface for Laravel validation rule objects that can describe themselves to the
 * OpenAPI generator.
 *
 * Implement this on a custom `Rule` or `ValidationRule` class to declare the schema constraint
 * the rule represents. Without it, custom rule objects emit a `rule.unknown` lint finding and
 * the field gets no constraint from that rule.
 *
 * ```php
 * use Illuminate\Contracts\Validation\ValidationRule;
 *
 * final class IsbnRule implements ValidationRule, SelfDocumentingRule
 * {
 *     public function validate(string $attribute, mixed $value, Closure $fail): void { … }
 *
 *     public function documentation(): RuleDocumentation
 *     {
 *         return new RuleDocumentation(
 *             description: 'ISBN-10 or ISBN-13 with optional hyphens.',
 *             type: 'string',
 *             pattern: '^(\\d{9}[\\dX]|\\d{13})$',
 *         );
 *     }
 * }
 * ```
 */
interface SelfDocumentingRule
{
    public function documentation(): RuleDocumentation;
}
