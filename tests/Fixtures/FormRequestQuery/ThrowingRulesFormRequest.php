<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\FormRequestQuery;

use Illuminate\Foundation\Http\FormRequest;
use RuntimeException;

/**
 * A FormRequest whose rules() throws, to exercise the degrade path: no query parameters, a notice,
 * and no crash.
 */
class ThrowingRulesFormRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        throw new RuntimeException('rules() depends on runtime state');
    }
}
