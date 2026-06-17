<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\Visitors\ApiRule as ApiRuleVisitor;

use function filter_var;
use function is_array;
use function is_string;
use function preg_replace;
use function Radiergummi\OpenApi\is_undefined;
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

            if (is_undefined($url) || !is_string($url)) {
                continue;
            }

            // OpenAPI 3.x explicitly permits relative paths (e.g., /v1).
            if (str_starts_with($url, '/')) {
                continue;
            }

            // Replace {var} segments before validation; stripping them would leave an invalid host.
            $stripped = preg_replace('/\{[^}]+}/', 'placeholder', $url) ?? $url;

            if (filter_var($stripped, FILTER_VALIDATE_URL) !== false) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                severity: $this->severity(),
                message: sprintf('Server URL "%s" is not a valid URL.', $url),
                fixHint: 'Provide a fully-qualified URL (e.g., https://api.example.com/v1), a relative path (e.g., /api/v0), or a valid URL template.',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'server.invalid-url';
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Broken;
    }

    #[Override]
    public function description(): string
    {
        return 'A servers[].url is malformed.';
    }
}
