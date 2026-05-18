<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use OpenApi\Annotations\ServerVariable;
use OpenApi\Generator;
use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\ApiRule as ApiRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;

use function array_key_exists;
use function is_array;
use function is_string;
use function preg_match_all;
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

            if ($url === Generator::UNDEFINED || !is_string($url)) {
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
                    if ($variable instanceof ServerVariable && $variable->serverVariable !== Generator::UNDEFINED) {
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
                    level: $this->level(),
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
    public function level(): int
    {
        return 0;
    }

    #[Override]
    public function description(): string
    {
        return 'A server URL template uses a {var} with no matching variables entry.';
    }
}
