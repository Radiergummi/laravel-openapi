# Changelog

All notable changes to this project are documented here.

## [Unreleased]

### Added
- Laravel paginator return types (`LengthAwarePaginator`, `Paginator`, `CursorPaginator`) are
  now documented automatically. The paginated item type is resolved from a `#[ResponseResource]`
  attribute or a `@return Paginator<Item>` PHPDoc generic.

### Changed
- PHPStan now runs at level 8 with `treatPhpDocTypesAsCertain` disabled and is a blocking CI check.
- Document generation now skips routes whose controller class cannot be resolved at introspection time, instead of aborting the entire run with a `ReflectionException`.
- Upgraded core dependencies to current major versions: `zircote/swagger-php` 6, `symfony/type-info` 8, and `phpdocumentor/reflection-docblock` 6.

### Fixed
- The `schema.constraints-missing` lint rule now handles OpenAPI 3.1 nullable type arrays (`type: [string, null]`). Previously such schemas caused a `TypeError` and were silently left unchecked.
- The lint spec-tree builder no longer fails when a media type's schema is a plain `$ref` string rather than an inline schema object; such schemas are now handled gracefully.
- Generated documents keep the OpenAPI 3.1 nullable form (`type: ['…', 'null']`). swagger-php 6 defaults its serialisation context to OpenAPI 3.0, which down-converts nullable unions to the removed `nullable` keyword; generation now pins a 3.1 context.

## [0.1.0] - 2026-05-18

### Added
- Initial public release: OpenAPI 3.1 generation and documentation linting for Laravel.
- Bundled Spatie Data plugin and FormRequest request-schema support.
- `openapi:generate`, `openapi:lint`, `openapi:clear` artisan commands.
- Config-driven spec endpoint and Scalar playground routes.
