<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Http;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerationOrchestrator;
use Radiergummi\OpenApi\Core\Spec\SpecRegistry;
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
     * Serves the generated OpenAPI 3.1 specification as YAML for the given spec.
     *
     * In local development, the spec is generated on every request so changes to controllers and
     * schemas are reflected immediately. Everywhere else, the spec is built at deploy time by the
     * `openapi:generate` command and served as a static file with etag/last-modified semantics. A
     * missing file in a deployed environment indicates a broken build.
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

        // Despite the name, this method actually modifies the request to set the appropriate
        // headers if the client already knows the current version of the spec, so it doesn't have
        // to download the full file again.
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Render the Scalar API reference page for the given spec.
     *
     * The page is a minimal HTML shell that loads Scalar from a CDN and points it at the OpenAPI
     * YAML endpoint for this spec.
     *
     * @noinspection PhpUnhandledExceptionInspection
     */
    public function playground(SpecRegistry $registry, string $spec = 'default'): View
    {
        abort_if(!$registry->has($spec), 404);

        $definition = $registry->get($spec);

        // The playground needs a spec URL to point at. If this spec opted out of HTTP
        // serving (route_uri: false), there is nothing to render.
        abort_if(!$definition->servesOverHttp(), 404);

        return view('openapi::playground', ['specUrl' => route($definition->specRouteName())]);
    }
}
