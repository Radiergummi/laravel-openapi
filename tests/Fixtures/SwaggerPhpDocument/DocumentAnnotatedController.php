<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpDocument;

use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

/**
 * Mirrors the conventional l5-swagger pattern: document-level `@OA\*` annotations parked on a base
 * controller. The migration rule should map each to a `config/openapi.php` key.
 */
#[OA\Info(
    version: '2.1.0',
    title: 'Flights API',
    description: 'Book and manage flights.',
)]
#[OA\Server(
    url: 'https://api.example.com',
    description: 'Production',
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
)]
#[OA\Tag(
    name: 'Flights',
    description: 'Flight booking and management.',
)]
class DocumentAnnotatedController extends Controller
{
    public function index(): array
    {
        return [];
    }
}
