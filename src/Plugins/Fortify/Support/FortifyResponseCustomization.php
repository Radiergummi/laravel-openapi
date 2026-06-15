<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fortify\Support;

use Closure;
use Illuminate\Contracts\Container\Container;
use ReflectionException;
use ReflectionFunction;

use function is_string;
use function str_starts_with;

/**
 * Best-effort detector for whether a Fortify response contract still maps to a stock Fortify
 * implementation. Inspects the registered binding's concrete class name WITHOUT resolving it; a
 * binding we cannot read as a concrete class (or an unbound contract) is treated as customized —
 * we never emit a possibly-wrong stock body.
 *
 * Note on the binding shape: Laravel's container wraps a string concrete in a closure inside
 * `bind()`/`singleton()` (see `Container::getClosure()`), so `getBindings()[...]['concrete']` is a
 * Closure even for `singleton($contract, ConcreteClass::class)` — the form Fortify uses for every
 * response. We therefore recover the original class name from the wrapper's captured `concrete`
 * variable, falling back to a directly-stored string concrete for other binding paths.
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

        if (!$this->container->bound($responseContract)) {
            // Unbound: Fortify's service provider binds all of these, so an unbound contract means
            // Fortify isn't fully wired — be conservative.
            return false;
        }

        $concrete = $this->concreteClassName($responseContract);

        return $concrete !== null && str_starts_with($concrete, self::FORTIFY_NAMESPACE);
    }

    /**
     * Recovers the bound concrete *class name* without resolving the binding, or null when it
     * cannot be read statically (a user closure that constructs an instance inline).
     */
    private function concreteClassName(string $responseContract): ?string
    {
        $concrete = $this->container->getBindings()[$responseContract]['concrete'] ?? null;

        // A directly-stored string concrete (older/alternative binding paths).
        if (is_string($concrete)) {
            return $concrete;
        }

        if (!$concrete instanceof Closure) {
            return null;
        }

        // Laravel wraps a string concrete in a closure that captures it as `$concrete`; recover it.
        // Reflecting a Closure never throws, but degrade to null rather than propagate if it ever does.
        try {
            $captured = (new ReflectionFunction($concrete))->getClosureUsedVariables();
        } catch (ReflectionException) {
            return null;
        }

        $value = $captured['concrete'] ?? null;

        return is_string($value) ? $value : null;
    }
}
