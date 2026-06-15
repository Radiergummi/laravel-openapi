<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fortify\Support;

use Illuminate\Contracts\Container\Container;
use ReflectionClass;
use Throwable;

use function str_starts_with;

/**
 * Detects whether a Fortify response contract still maps to a stock Fortify implementation. At
 * generation time the app is booted, so we resolve the contract from the container and check
 * whether the concrete is a `Laravel\Fortify\…` class. Anything else — a custom implementation, an
 * unbound contract, or a resolution that throws — is treated as customized: the body is unknowable,
 * so the response resolver falls back to status-only and never emits a possibly-wrong stock body.
 *
 * @internal
 */
final readonly class FortifyResponseCustomization
{
    private const string FORTIFY_NAMESPACE = 'Laravel\\Fortify\\';

    public function __construct(private Container $container) {}

    /**
     * @param ?class-string $responseContract
     */
    public function isStock(?string $responseContract): bool
    {
        // No contract governs this route's body (e.g. the password-confirmation status endpoint).
        if ($responseContract === null) {
            return true;
        }

        try {
            // Two stock responses (the password-reset {message} bodies) take a required
            // `string $status`; pass an empty one so resolving them doesn't throw. The argument is
            // ignored by responses that don't declare it, including custom implementations.
            $instance = $this->container->make($responseContract, ['status' => '']);

            // An anonymous class implementing a Fortify contract inherits the contract's namespace
            // in its synthetic name (`Laravel\Fortify\Contracts\X@anonymous…`), so a bare namespace
            // check would mistake a custom inline response for stock — exclude anonymous classes.
            $isAnonymous = (new ReflectionClass($instance))->isAnonymous();
        } catch (Throwable) {
            // Unbound, or a binding that throws when constructed — be conservative.
            return false;
        }

        return !$isAnonymous && str_starts_with($instance::class, self::FORTIFY_NAMESPACE);
    }
}
