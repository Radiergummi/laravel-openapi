<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Routing\Controller;

class FileUploadFixtureController extends Controller
{
    public function upload(FileUploadFormRequest $request): array
    {
        return [];
    }
}
