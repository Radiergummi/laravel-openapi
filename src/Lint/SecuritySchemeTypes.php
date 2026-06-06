<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use OpenApi\Annotations as OA;

use function is_array;
use function is_string;
use function Radiergummi\OpenApi\is_defined;
use function Radiergummi\OpenApi\is_undefined;

/**
 * Resolves security-scheme names to their OAS `type` from a document's `components.securitySchemes`,
 * so scope-aware lint rules can gate on whether a requirement's scheme actually carries scopes.
 *
 * @internal
 */
final readonly class SecuritySchemeTypes
{
    /**
     * @param array<string, string> $byName scheme name → OAS type
     */
    private function __construct(private array $byName) {}

    public static function fromSpec(OA\OpenApi $spec): self
    {
        $components = $spec->components;

        if (is_undefined($components) || $components === null) {
            return new self([]);
        }

        $schemes = $components->securitySchemes;

        if (is_undefined($schemes) || !is_array($schemes)) {
            return new self([]);
        }

        $byName = [];

        foreach ($schemes as $scheme) {
            if (
                is_undefined($scheme)
                || !is_defined($scheme->securityScheme)
                || $scheme->securityScheme === null
                || !is_string($scheme->type)
            ) {
                continue;
            }

            $byName[$scheme->securityScheme] = $scheme->type;
        }

        return new self($byName);
    }

    /**
     * Whether a requirement targeting `$schemeName` should have its scopes validated.
     *
     * Scopes only have a document-level registry for `oauth2` / `openIdConnect` schemes; `http` /
     * `apiKey` / `mutualTLS` schemes (e.g. Sanctum's bearer scheme, whose `abilities:` surface as
     * scopes) carry no scope catalogue, so their scopes must not be validated against it. A scheme
     * that is not declared at all has an unknown type — keep validating it (and let
     * `security.scheme-undefined` flag the missing declaration).
     */
    public function carriesScopes(string $schemeName): bool
    {
        $type = $this->byName[$schemeName] ?? null;

        return $type === null || $type === 'oauth2' || $type === 'openIdConnect';
    }
}
