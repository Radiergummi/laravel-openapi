<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic;

use Illuminate\Foundation\Http\FormRequest;
use RuntimeException;

/**
 * The `$rules = [ … ]; … return $rules;` shape with the assignment and return separated by more than
 * ten statements; the wider return scan still recovers the base literal.
 */
class VariableReturnBeyondWindowFormRequest extends FormRequest
{
    /**
     * @return array<string, list<string>|string>
     */
    public function rules(): array
    {
        if ($this->input('action') === null) {
            throw new RuntimeException('rules() requires request input at runtime');
        }

        $rules = [
            'action' => 'sometimes|string',
            'ids' => 'required|array',
        ];

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

        return $rules;
    }
}
