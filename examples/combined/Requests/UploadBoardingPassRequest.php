<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\Combined\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation contract for `POST /bookings/{booking}/boarding-pass`.
 *
 * Carries a single `image` file field. Because the rules array includes a
 * `file` rule, the FormRequest schema resolver auto-detects that the body
 * must be sent as `multipart/form-data` and emits the correct content-type
 * + binary schema in the spec.
 */
final class UploadBoardingPassRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'mimes:png,jpg,pdf', 'max:5120'],
        ];
    }
}
