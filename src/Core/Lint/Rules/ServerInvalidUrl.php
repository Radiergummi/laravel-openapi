<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\ApiRule as ApiRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use OpenApi\Generator;
use Override;

use function filter_var;
use function is_array;
use function is_string;
use function preg_replace;
use function sprintf;
use function str_starts_with;

use const FILTER_VALIDATE_URL;

final class ServerInvalidUrl implements Rule, ApiRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkApi(ApiNode $api, LintContext $context): iterable
    {
        $servers = $context->rawSpec->servers;

        if (!is_array($servers)) {
            return;
        }

        foreach ($servers as $server) {
            $url = $server->url;

            if ($url === Generator::UNDEFINED || !is_string($url)) {
                continue;
            }

            // Relative URL references (e.g. /v1, /api/v0) are explicitly
            // permitted by OpenAPI 3.x — accept any non-empty path-rooted string.
            if (str_starts_with($url, '/')) {
                continue;
            }

            // Replace {var} template segments with a placeholder before
            // validation so that https://{region}.example.com is treated
            // as a valid URL (stripping entirely would leave https://.example.com).
            // Guard against preg_replace returning null on PCRE error.
            $stripped = preg_replace('/\{[^}]+}/', 'placeholder', $url) ?? $url;

            if (filter_var($stripped, FILTER_VALIDATE_URL) !== false) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf('Server URL "%s" is not a valid URL.', $url),
                fixHint: 'Provide a fully-qualified URL (e.g. https://api.example.com/v1), a relative path (e.g. /api/v0), or a valid URL template.',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'server.invalid-url';
    }

    #[Override]
    public function level(): int
    {
        return 0;
    }

    #[Override]
    public function description(): string
    {
        return 'A servers[].url is malformed.';
    }
}
