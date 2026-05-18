<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Http;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerator;
use ReflectionException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;

use function config;
use function file_exists;
use function response;
use function route;
use function view;

final class DocsController extends Controller
{
    /**
     * Serves the generated OpenAPI 3.1 specification as YAML.
     *
     * In local development, the spec is generated on every request so changes to controllers and
     * schemas are reflected immediately. Everywhere else, the spec is built at deploy time by the
     * `openapi:generate` command and served as a static file with etag/last-modified semantics. A
     * missing file in a deployed environment indicates a broken build.
     *
     * @throws HttpException
     * @throws HttpResponseException
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnsupportedException
     */
    public function spec(OpenApiGenerator $generator, Request $request): BinaryFileResponse|Response
    {
        if ((string) config('app.env', 'production') === 'local') {
            return response($generator->generate()->toYaml(), 200, [
                'Content-Type' => 'application/yaml',
                'Cache-Control' => 'no-store',
            ]);
        }

        $path = (string) config('openapi.output_path');

        if (!file_exists($path)) {
            throw new RuntimeException('OpenAPI specification has not been generated');
        }

        $response = response()
            ->file($path, [
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
     * Render the Scalar API reference page.
     *
     * The page is a minimal HTML shell that loads Scalar from a CDN and points it at the OpenAPI
     * YAML endpoint.
     */
    public function playground(): View
    {
        return view('openapi::playground', ['specUrl' => route('openapi.spec')]);
    }
}
