<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator\Appliers;

use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Radiergummi\OpenApi\Attributes\CookieParam as CookieParamAttribute;
use Radiergummi\OpenApi\Attributes\Header as HeaderAttribute;
use Radiergummi\OpenApi\Routing\ActionDescriptor;

use function array_map;
use function array_values;

/**
 * Builds request `#[Header]` and `#[CookieParam]` attributes into `OA\Parameter`s. Method-level
 * entries win on name collision. Owns only the attribute-reading and `OA\*` construction for these
 * two request-parameter families; precedence beyond the name-collision rule is not its concern.
 *
 * @internal
 */
final readonly class RequestParameterApplier
{
    /**
     * @return list<OA\Parameter>
     */
    public function headerParameters(ActionDescriptor $descriptor): array
    {
        /** @var array<string, HeaderAttribute> $byName */
        $byName = [];

        foreach (
            [
                ...$descriptor->controllerAttributes(HeaderAttribute::class),
                ...$descriptor->actionAttributes(HeaderAttribute::class),
            ] as $attribute
        ) {
            $instance = $attribute->newInstance();
            // Header field names are case-insensitive (RFC 9110 §5.1), so two #[Header] entries
            // differing only in case are the same header; the last declared one wins.
            $byName[strtolower($instance->name)] = $instance;
        }

        return array_values(array_map($this->buildHeaderParameter(...), $byName));
    }

    /**
     * @return list<OA\Parameter>
     */
    public function cookieParameters(ActionDescriptor $descriptor): array
    {
        /** @var array<string, CookieParamAttribute> $byName */
        $byName = [];

        foreach (
            [
                ...$descriptor->controllerAttributes(CookieParamAttribute::class),
                ...$descriptor->actionAttributes(CookieParamAttribute::class),
            ] as $attribute
        ) {
            $instance = $attribute->newInstance();
            $byName[$instance->name] = $instance;
        }

        return array_values(array_map($this->buildCookieParameter(...), $byName));
    }

    private function buildHeaderParameter(HeaderAttribute $header): OA\Parameter
    {
        $schemaProps = ['type' => $header->type];

        if ($header->format !== null) {
            $schemaProps['format'] = $header->format;
        }

        if ($header->example !== null) {
            $schemaProps['example'] = $header->example;
        }

        $props = [
            'name' => $header->name,
            'in' => 'header',
            'required' => $header->required,
            'schema' => new OA\Schema($schemaProps),
        ];

        if ($header->description !== null) {
            $props['description'] = $header->description;
        }

        if ($header->deprecated !== null) {
            $props['deprecated'] = $header->deprecated;
        }

        return new OA\Parameter($props);
    }

    private function buildCookieParameter(CookieParamAttribute $cookie): OA\Parameter
    {
        $schema = $cookie->descriptor()->toSchema();

        // Cookies are always string-valued on the wire; default the schema type when unset.
        if ($schema->type === Generator::UNDEFINED) {
            $schema->type = 'string';
        }

        $props = [
            'name' => $cookie->name,
            'in' => 'cookie',
            'required' => $cookie->required,
            'schema' => $schema,
        ];

        if ($cookie->deprecated) {
            $props['deprecated'] = true;
        }

        return new OA\Parameter($props);
    }
}
