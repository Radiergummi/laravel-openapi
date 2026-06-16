<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Resolvers;

use Illuminate\Support\Str;
use Override;
use Radiergummi\OpenApi\Contracts\Registry\OperationConvention;
use Radiergummi\OpenApi\Contracts\Registry\OperationConventionResolver;
use Radiergummi\OpenApi\Routing\ActionDescriptor;

use function in_array;
use function preg_replace;

/**
 * Derives the conventional success status code and summary for resourceful controller actions.
 * Fires only when the method name is a known resource action and the HTTP verb matches.
 * The noun is derived from the controller short name, pluralised for collection actions.
 *
 * @internal
 */
final readonly class ResourceConventionResolver implements OperationConventionResolver
{
    /**
     * @var array<string, array{verbs: list<string>, status: int, verb: string, plural: bool}>
     */
    private const array CONVENTIONS = [
        'index' => ['verbs' => ['GET'], 'status' => 200, 'verb' => 'List', 'plural' => true],
        'show' => ['verbs' => ['GET'], 'status' => 200, 'verb' => 'Show', 'plural' => false],
        'store' => ['verbs' => ['POST'], 'status' => 201, 'verb' => 'Create', 'plural' => false],
        'update' => ['verbs' => ['PUT', 'PATCH'], 'status' => 200, 'verb' => 'Update', 'plural' => false],
        'destroy' => ['verbs' => ['DELETE'], 'status' => 204, 'verb' => 'Delete', 'plural' => false],
    ];

    #[Override]
    public function resolve(ActionDescriptor $descriptor): ?OperationConvention
    {
        $action = $descriptor->method?->getName();
        $convention = $action !== null ? (self::CONVENTIONS[$action] ?? null) : null;

        if ($convention === null) {
            return null;
        }

        $emittedVerb = $descriptor->httpMethod?->forDisplay();

        if ($emittedVerb === null || !in_array($emittedVerb, $convention['verbs'], true)) {
            return null;
        }

        $noun = $this->resourceNoun($descriptor);
        $summary = $noun !== null
            ? $convention['verb'] . ' ' . ($convention['plural'] ? Str::plural($noun) : $noun)
            : $convention['verb'];

        return new OperationConvention(
            successStatusCode: $convention['status'],
            summary: $summary,
        );
    }

    /**
     * Resource noun from the controller short name (`PostController` → `Post`), or null if unavailable.
     */
    private function resourceNoun(ActionDescriptor $descriptor): ?string
    {
        $shortName = $descriptor->controller?->getShortName();

        if ($shortName === null) {
            return null;
        }

        $base = preg_replace('/Controller$/', '', $shortName);

        if ($base === null || $base === '') {
            return null;
        }

        return Str::singular($base);
    }
}
