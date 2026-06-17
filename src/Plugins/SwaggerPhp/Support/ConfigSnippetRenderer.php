<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Support;

use OpenApi\Annotations as OA;

use function array_keys;
use function count;
use function implode;
use function is_array;
use function Radiergummi\OpenApi\is_defined;
use function range;
use function str_repeat;
use function var_export;

/**
 * Renders an authored document-level swagger-php annotation as a paste-ready `config/openapi.php`
 * snippet, matching the exact array shape the generator reads ({@see \Radiergummi\OpenApi\Support\Spec\SpecRegistry}
 * for `info`/`servers`/`tags`, {@see \Radiergummi\OpenApi\Support\Extraction\SecurityExtractor}
 * for `security_schemes`). Only authored keys are emitted, so a pasted block carries no defaults.
 *
 * @internal
 */
final readonly class ConfigSnippetRenderer
{
    private const string INDENT = '    ';

    /**
     * `'info' => [ … ]`, mirroring the array passed to `new OA\Info(...)`.
     */
    public function info(OA\Info $info): string
    {
        $entries = $this->scalarEntries($info, ['title', 'version', 'description', 'termsOfService']);

        if (is_defined($info->contact) && $info->contact instanceof OA\Contact) {
            $entries['contact'] = $this->nested($info->contact, ['name', 'url', 'email']);
        }

        if (is_defined($info->license) && $info->license instanceof OA\License) {
            $entries['license'] = $this->nested($info->license, ['name', 'identifier', 'url']);
        }

        return $this->block('info', $entries);
    }

    /**
     * `'servers' => [ [ … ] ]`, a list of arrays each passed to `new OA\Server(...)`.
     */
    public function servers(OA\Server $server): string
    {
        $entries = $this->scalarEntries($server, ['url', 'description']);

        return $this->block('servers', [$entries]);
    }

    /**
     * `'security_schemes' => [ '<name>' => [ … ] ]`, keyed by scheme name.
     */
    public function securityScheme(OA\SecurityScheme $scheme): string
    {
        $shape = $this->scalarEntries(
            $scheme,
            ['type', 'description', 'name', 'in', 'scheme', 'bearerFormat', 'openIdConnectUrl'],
        );

        return $this->block('security_schemes', [(string) $scheme->securityScheme => $shape]);
    }

    /**
     * `'tags' => [ '<name>' => ['description' => …] ]`, keyed by tag name.
     */
    public function tag(OA\Tag $tag): string
    {
        $shape = $this->scalarEntries($tag, ['description']);

        return $this->block('tags', [(string) $tag->name => $shape]);
    }

    /**
     * The authored, scalar (non-object) properties of an annotation, in the given order.
     *
     * @param list<string> $properties
     *
     * @return array<string, scalar>
     */
    private function scalarEntries(OA\AbstractAnnotation $annotation, array $properties): array
    {
        $entries = [];

        foreach ($properties as $property) {
            $value = $annotation->{$property} ?? null;

            if (is_defined($value) && !is_array($value)) {
                $entries[$property] = $value;
            }
        }

        return $entries;
    }

    /**
     * @param list<string> $properties
     *
     * @return array<string, scalar>
     */
    private function nested(OA\AbstractAnnotation $annotation, array $properties): array
    {
        return $this->scalarEntries($annotation, $properties);
    }

    /**
     * Renders `'<key>' => [ … ],` with the value array pretty-printed at config depth.
     *
     * @param array<array-key, mixed> $value
     */
    private function block(string $key, array $value): string
    {
        return "'{$key}' => " . $this->renderArray($value, 1) . ',';
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function renderArray(array $value, int $depth): string
    {
        if ($value === []) {
            return '[]';
        }

        $isList = array_keys($value) === range(0, count($value) - 1);
        $indent = str_repeat(self::INDENT, $depth);
        $lines = [];

        foreach ($value as $entryKey => $entryValue) {
            $rendered = is_array($entryValue)
                ? $this->renderArray($entryValue, $depth + 1)
                : var_export($entryValue, true);

            $lines[] = $isList
                ? "{$indent}{$rendered},"
                : "{$indent}'{$entryKey}' => {$rendered},";
        }

        $closingIndent = str_repeat(self::INDENT, $depth - 1);

        return "[\n" . implode("\n", $lines) . "\n{$closingIndent}]";
    }
}
