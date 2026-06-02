<?php

declare(strict_types=1);

// strict_types — CLAUDE.md: "Every PHP file has a strict-types declaration."
arch('source files declare strict types')
    ->expect('Radiergummi\OpenApi')
    ->toUseStrictTypes();

arch('test files declare strict types')
    ->expect('Radiergummi\OpenApi\Tests')
    ->toUseStrictTypes();

// Plugin isolation — no plugin may import a sibling plugin (Core included: it is the
// foundation, but the four convention plugins reach shared logic through Support/, not Core).
$plugins = ['ApiResources', 'Core', 'Fractal', 'QueryBuilder', 'SpatieData'];

foreach ($plugins as $plugin) {
    $siblings = array_values(array_filter(
        $plugins,
        static fn(string $other): bool => $other !== $plugin,
    ));

    arch("plugin {$plugin} must not import a sibling plugin")
        ->expect("Radiergummi\\OpenApi\\Plugins\\{$plugin}")
        ->not->toUse(array_map(
            static fn(string $sibling): string => "Radiergummi\\OpenApi\\Plugins\\{$sibling}",
            $siblings,
        ));
}

// @internal isolation — the documented public extension surface (Contracts/) must not leak
// implementation types tagged @internal. Reflection-based rather than a namespace denylist:
// Contracts legitimately uses non-@internal classes that live alongside @internal ones (e.g.
// Support\Extraction\RuleDocumentation, Support\Registry\ResolvedSchema).
test('public Contracts surface must not reference any @internal class', function (): void {
    $internal = internalSourceClasses();
    $referenced = importsUnderDirectory(srcPath('Contracts'));

    expect(array_values(array_intersect($referenced, $internal)))->toBe([]);
});

/**
 * Absolute path to a directory under src/.
 */
function srcPath(string $relative = ''): string
{
    return dirname(__DIR__, 2) . '/src' . ($relative === '' ? '' : '/' . $relative);
}

/**
 * Every PHP file under src/ that declares a class-level @internal tag, mapped to its FQCN.
 *
 * @return list<string>
 */
function internalSourceClasses(): array
{
    $classes = [];

    foreach (phpFilesUnder(srcPath()) as $file) {
        $contents = file_get_contents($file);

        if ($contents === false || ! hasClassLevelInternalTag($contents)) {
            continue;
        }

        $fqcn = fqcnFromFile($contents);

        if ($fqcn !== null) {
            $classes[] = $fqcn;
        }
    }

    return $classes;
}

/**
 * Whether the file's type declaration carries a class-level @internal tag in its own docblock.
 * A bare str_contains('@internal') would also match method- and property-level @internal tags
 * (e.g. the `linkParent()` methods on Lint\Tree nodes), wrongly marking otherwise-public classes
 * as internal. We require the @internal docblock to immediately precede the class/interface/enum/
 * trait declaration (allowing intervening attributes and modifiers).
 */
function hasClassLevelInternalTag(string $contents): bool
{
    return preg_match(
        '~/\*\*(?:[^*]|\*(?!/))*?@internal\b(?:[^*]|\*(?!/))*?\*/\s*'
        . '(?:#\[[^\]]*\]\s*)*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|enum|trait)\s+\w+~',
        $contents,
    ) === 1;
}

/**
 * All `Radiergummi\OpenApi\…` symbols imported via `use` statements across a directory tree.
 *
 * @return list<string>
 */
function importsUnderDirectory(string $directory): array
{
    $imports = [];

    foreach (phpFilesUnder($directory) as $file) {
        $contents = file_get_contents($file);

        if ($contents === false) {
            continue;
        }

        preg_match_all(
            '/^use\s+(Radiergummi\\\\OpenApi\\\\[^\s;]+)\s*;/m',
            $contents,
            $matches,
        );

        foreach ($matches[1] as $import) {
            $imports[] = $import;
        }
    }

    return $imports;
}

/**
 * @return list<string>
 */
function phpFilesUnder(string $directory): array
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    $files = [];

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

/**
 * Derive the FQCN from a PHP file's namespace and the single type it declares.
 */
function fqcnFromFile(string $contents): ?string
{
    if (
        preg_match('/^namespace\s+([^\s;]+)\s*;/m', $contents, $namespace) !== 1
        || preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|enum|trait)\s+(\w+)/m', $contents, $type) !== 1
    ) {
        return null;
    }

    /** @var class-string */
    return $namespace[1] . '\\' . $type[1];
}
