<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Radiergummi\OpenApi\Tests\Fixtures\Validation\Validator;

/**
 * Negative probes for the inline-validation call-shape whitelist: same names, wrong targets.
 */
class InlineValidationImpostorController
{
    public function viaImportedValidator(Request $request): JsonResponse
    {
        $result = Validator::make($request->all(), [
            'not-rules' => 'just-options',
        ]);

        return new JsonResponse($result);
    }

    public function viaOwnValidateHelper(): JsonResponse
    {
        $data = ['name' => 'x'];
        $result = $this->validate($data, ['strict' => 'required|boolean']);

        return new JsonResponse($result);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function validate(array $data, array $options): array
    {
        return $data + $options;
    }
}
