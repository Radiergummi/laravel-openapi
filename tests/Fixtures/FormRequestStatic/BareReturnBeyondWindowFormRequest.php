<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic;

use Illuminate\Foundation\Http\FormRequest;
use RuntimeException;

/**
 * The bare-return rules literal sits behind a run of leading statements past the old ten-statement
 * window; the wider return scan still finds it.
 */
class BareReturnBeyondWindowFormRequest extends FormRequest
{
    /**
     * @return array<string, list<string>|string>
     */
    public function rules(): array
    {
        if ($this->input('action') === null) {
            throw new RuntimeException('rules() requires request input at runtime');
        }

        $s1 = 1;
        $s2 = 2;
        $s3 = 3;
        $s4 = 4;
        $s5 = 5;
        $s6 = 6;
        $s7 = 7;
        $s8 = 8;
        $s9 = 9;
        $s10 = 10;
        $s11 = 11;
        $s12 = 12;

        return [
            'name' => 'required|string',
            'email' => ['required', 'email'],
        ];
    }
}
