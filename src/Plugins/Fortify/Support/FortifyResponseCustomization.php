<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fortify\Support;

use Illuminate\Contracts\Container\Container;
use ReflectionClass;
use Throwable;

use function str_starts_with;

/**
 * Detects whether a Fortify response contract still maps to a stock implementation. Custom
 * bindings, unbound contracts, or resolution failures all count as customized: the body is
 * unknowable, so the resolver falls back to status-only to avoid emitting a wrong schema.
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
        // No contract governs this route (e.g., password-confirmation status endpoint).
        if ($responseContract === null) {
            return true;
        }

        try {
            // Some stock responses require `string $status`; pass an empty string so resolution
            // doesn't throw. Responses that don't declare it ignore the argument.
            $instance = $this->container->make($responseContract, ['status' => '']);

            // Anonymous classes inherit the contract namespace in their synthetic name
            // (`Contracts\X@anonymous…`), so exclude them to avoid mistaking inline customizations
            // for stock implementations.
            $isAnonymous = new ReflectionClass($instance)->isAnonymous();
        } catch (Throwable) {
            return false;
        }

        return !$isAnonymous && str_starts_with($instance::class, self::FORTIFY_NAMESPACE);
    }
}
