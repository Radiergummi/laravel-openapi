<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Http;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerationOrchestrator;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

use function app;
use function file_exists;
use function response;
use function route;
use function view;

final class DocsController extends Controller
{
    /**
     * Serves the spec as YAML. Generates on-the-fly in local; serves a static file elsewhere
     * (must be built by `openapi:generate`).
     *
     * @noinspection PhpUnhandledExceptionInspection
     */
    public function spec(
        OpenApiGenerationOrchestrator $orchestrator,
        SpecRegistry $registry,
        Request $request,
        string $spec = 'default',
    ): BinaryFileResponse|Response {
        $definition = $registry->get($spec);

        if (app()->environment('local')) {
            return response(
                $orchestrator->generateOne($spec, app()->environment())->toYaml(),
                200,
                ['Content-Type' => 'application/yaml', 'Cache-Control' => 'no-store'],
            );
        }

        if (!file_exists($definition->outputPath)) {
            throw new RuntimeException("OpenAPI specification '{$spec}' has not been generated");
        }

        $response = response()
            ->file($definition->outputPath, [
                'Content-Type' => 'application/yaml',
                'Cache-Control' => 'public, max-age=300, must-revalidate',
            ])
            ->setAutoEtag()
            ->setAutoLastModified();

        // Sets conditional-request headers; returns true if the client can use its cache.
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Renders the API playground. Renderer chosen by `openapi.routes.playground.renderer`
     * (`scalar` default, `swagger-ui` opt-in).
     *
     * @noinspection PhpUnhandledExceptionInspection
     */
    public function playground(SpecRegistry $registry, string $spec = 'default'): View
    {
        abort_if(!$registry->has($spec), 404);

        $definition = $registry->get($spec);

        abort_if(!$definition->servesOverHttp(), 404);

        $view = config('openapi.routes.playground.renderer') === 'swagger-ui'
            ? 'openapi::playground-swagger-ui'
            : 'openapi::playground';

        return view($view, ['specUrl' => route($definition->specRouteName())]);
    }
}
