<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use OpenApi\Annotations\ServerVariable;
use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\Visitors\ApiRule as ApiRuleVisitor;

use function array_key_exists;
use function is_array;
use function is_string;
use function preg_match_all;
use function Radiergummi\OpenApi\is_defined;
use function Radiergummi\OpenApi\is_undefined;
use function sprintf;

final class ServerVariableUndeclared implements Rule, ApiRuleVisitor
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

            if (preg_match_all('/\{([^}]+)}/', $url, $matches) === 0) {
                continue;
            }

            /** @var list<non-empty-string> $templateVars */
            $templateVars = $matches[1];

            $variables = $server->variables;
            $declaredKeys = [];

            if (is_array($variables)) {
                foreach ($variables as $key => $variable) {
                    if ($variable instanceof ServerVariable && is_defined($variable->serverVariable)) {
                        $declaredKeys[$variable->serverVariable] = true;
                    } else {
                        $declaredKeys[(string) $key] = true;
                    }
                }
            }

            foreach ($templateVars as $varName) {
                if (array_key_exists($varName, $declaredKeys)) {
                    continue;
                }

                yield new Finding(
                    ruleId: $this->id(),
                    severity: $this->severity(),
                    message: sprintf(
                        'Server URL "%s" uses template variable "{%s}" but no matching entry exists in servers[].variables.',
                        $url,
                        $varName,
                    ),
                    fixHint: sprintf(
                        'Add a variables entry for "%s" on the server object.',
                        $varName,
                    ),
                );
            }
        }
    }

    #[Override]
    public function id(): string
    {
        return 'server.variable-undeclared';
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Broken;
    }

    #[Override]
    public function description(): string
    {
        return 'A server URL template uses a {var} with no matching variables entry.';
    }
}
