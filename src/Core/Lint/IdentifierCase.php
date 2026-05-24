<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint;

/**
 * Identifier casing conventions recognised by convention-aware lint rules.
 */
enum IdentifierCase: string
{
    case Dot = 'dot';
    case Kebab = 'kebab';
    case Snake = 'snake';
    case Camel = 'camel';
    case Pascal = 'pascal';
    case Train = 'train';
    case ScreamingSnake = 'screaming_snake';

    /**
     * Coerces a raw config value into the enum. Strict — an unknown string raises
     * `\ValueError`; naming rules have no safe fallback, so misconfiguration must
     * surface loudly (unlike {@see \Radiergummi\OpenApi\Core\Visibility\VisibilityMode::fromConfig()},
     * which silently defaults to `Public`).
     */
    public static function fromConfig(self|string $value): self
    {
        return $value instanceof self ? $value : self::from($value);
    }

    /** Returns a regex that fully matches a conforming identifier. */
    public function pattern(): string
    {
        return match ($this) {
            self::Dot           => '/^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)*$/',
            self::Kebab         => '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/',
            self::Snake         => '/^[a-z][a-z0-9]*(_[a-z0-9]+)*$/',
            self::Camel         => '/^[a-z][a-zA-Z0-9]*$/',
            self::Pascal        => '/^[A-Z][a-zA-Z0-9]*$/',
            self::Train         => '/^[A-Z][a-zA-Z0-9]*(-[A-Z][a-zA-Z0-9]*)*$/',
            self::ScreamingSnake => '/^[A-Z][A-Z0-9]*(_[A-Z0-9]+)*$/',
        };
    }

    /** Returns a human-readable phrase describing the convention. */
    public function label(): string
    {
        return match ($this) {
            self::Dot           => 'dot-separated lowercase',
            self::Kebab         => 'kebab-case',
            self::Snake         => 'snake_case',
            self::Camel         => 'camelCase',
            self::Pascal        => 'PascalCase',
            self::Train         => 'Train-Case',
            self::ScreamingSnake => 'SCREAMING_SNAKE_CASE',
        };
    }

    /** Returns a representative example identifier. */
    public function example(): string
    {
        return match ($this) {
            self::Dot           => 'api.v0.projects.index',
            self::Kebab         => 'api-v0-projects-index',
            self::Snake         => 'api_v0_projects_index',
            self::Camel         => 'apiV0ProjectsIndex',
            self::Pascal        => 'ApiV0ProjectsIndex',
            self::Train         => 'Api-V0-Projects-Index',
            self::ScreamingSnake => 'API_V0_PROJECTS_INDEX',
        };
    }
}
