<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\ErrorContributors;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Override;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseContributor;
use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use ReflectionNamedType;

use function class_exists;
use function is_subclass_of;
use function preg_match;
use function preg_quote;
use function sprintf;

/**
 * Infers a 404 error from an implicit route-model binding: when a controller parameter is an
 * Eloquent {@see Model} subclass matching a URI segment, Laravel throws {@see ModelNotFoundException}
 * on miss. Detection is signature + URI only (no body parsing). Custom-key (`{article:slug}`)
 * and optional (`{article?}`) bindings are recognised.
 */
#[Scoped]
final readonly class RouteModelBindingErrorContributor implements ErrorResponseContributor
{
    /**
     * @param array<string, array{status: int, description: string}> $exceptionMap
     */
    public function __construct(
        #[Config('openapi.exception_responses', default: [])]
        private array $exceptionMap = [],
    ) {}

    /**
     * @return list<ErrorDescriptor>
     */
    #[Override]
    public function contribute(ActionDescriptor $descriptor): array
    {
        if ($descriptor->method === null) {
            return [];
        }

        $entry = $this->exceptionMap[ModelNotFoundException::class] ?? null;

        if ($entry === null) {
            return [];
        }

        $uri = $descriptor->route->uri();

        foreach ($descriptor->method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $className = $type->getName();

            if (!class_exists($className) || !is_subclass_of($className, Model::class)) {
                continue;
            }

            if (!$this->isBoundUriSegment($uri, $parameter->getName())) {
                continue;
            }

            // One 404 covers the whole action; the framework always throws ModelNotFoundException.
            return [
                new ErrorDescriptor(
                    status: (int) $entry['status'],
                    exceptionClass: ModelNotFoundException::class,
                    description: (string) $entry['description'],
                    action: $descriptor,
                ),
            ];
        }

        return [];
    }

    /**
     * Whether the URI contains a `{name}`, `{name:field}`, or `{name?}` segment.
     */
    private function isBoundUriSegment(string $uri, string $name): bool
    {
        $pattern = sprintf('/\{%s(?::[^}]+)?\??\}/', preg_quote($name, '/'));

        return preg_match($pattern, $uri) === 1;
    }
}
