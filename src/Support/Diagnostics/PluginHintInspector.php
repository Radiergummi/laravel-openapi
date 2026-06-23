<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Diagnostics;

use function class_exists;
use function in_array;
use function sprintf;
use function strrchr;
use function substr;

/**
 * Detects integration packages that are installed but whose plugin is not enabled.
 *
 * Plugin-agnostic by design: the package-to-plugin map is supplied by the caller (the Laravel
 * service provider, the only layer allowed to name plugin classes), so this class references no
 * plugin class itself.
 *
 * @internal
 *
 * @phpstan-type IntegrationEntry array{markers: list<string>, package: string, plugin: class-string}
 */
final class PluginHintInspector
{
    /**
     * @var list<IntegrationEntry>
     */
    private array $integrationMap;

    /**
     * @var callable(string): bool
     */
    private $classExistsCheck;

    /**
     * @param list<IntegrationEntry>     $integrationMap   one entry per known integration package
     * @param null|callable(string):bool $classExistsCheck overridable for deterministic testing,
     *                                                     since all integration packages are
     *                                                     installed in the test environment
     */
    public function __construct(array $integrationMap, ?callable $classExistsCheck = null)
    {
        $this->integrationMap = $integrationMap;
        $this->classExistsCheck = $classExistsCheck ?? static fn(string $class): bool => class_exists($class);
    }

    /**
     * Returns one advisory message per integration package that is installed but whose plugin is
     * absent from the enabled plugin list. Deduplicated by plugin class.
     *
     * @param list<string> $enabledPlugins the configured plugin class-strings (`openapi.plugins`)
     *
     * @return list<string>
     */
    public function hints(array $enabledPlugins): array
    {
        $hints = [];
        $seenPlugins = [];

        foreach ($this->integrationMap as $entry) {
            if (in_array($entry['plugin'], $seenPlugins, true)) {
                continue;
            }

            if (in_array($entry['plugin'], $enabledPlugins, true)) {
                continue;
            }

            if (!$this->anyMarkerPresent($entry['markers'])) {
                continue;
            }

            $seenPlugins[] = $entry['plugin'];
            $hints[] = $this->message($entry['package'], $entry['plugin']);
        }

        return $hints;
    }

    /**
     * @param list<string> $markers
     */
    private function anyMarkerPresent(array $markers): bool
    {
        foreach ($markers as $marker) {
            if (($this->classExistsCheck)($marker)) {
                return true;
            }
        }

        return false;
    }

    private function message(string $package, string $pluginClass): string
    {
        $shortName = substr((string) strrchr($pluginClass, '\\'), 1) ?: $pluginClass;

        return sprintf(
            '%s is installed but %s is not enabled. Add it to the \'plugins\' array in '
            . 'config/openapi.php to infer response schemas and parameters from it.',
            $package,
            $shortName,
        );
    }
}
