<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Visitors\RouteRule;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use ReflectionClass;

use function class_exists;
use function sprintf;

/**
 * Reports routes whose action resolves to a controller method that does not exist. Resourceful
 * route registration (e.g. `Route::apiResource()`) registers all seven actions, but a controller
 * may implement only a few; the remaining routes point at missing methods and would fault at
 * runtime, yet are documented as valid operations. Deterministic reflection, no body parsing.
 *
 * Closures are skipped (no controller method to reflect), as are routes whose controller class
 * does not exist (a different defect, outside this rule's scope).
 */
final class ActionMethodMissing implements Rule, RouteRule
{
    public string $id = self::ID;
    public Severity $severity = Severity::Degraded;
    public string $description = 'Route action resolves to a controller method that does not exist; the operation is documented but would fault at runtime.';

    public const string ID = 'operation.action-method-missing';

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkRoute(ActionDescriptor $descriptor, LintContext $context): iterable
    {
        $controllerClass = $descriptor->route->getControllerClass();

        // Closure routes have no controller class to reflect.
        if ($controllerClass === null || !class_exists($controllerClass)) {
            return;
        }

        $reflection = new ReflectionClass($controllerClass);
        $actionMethod = $descriptor->route->getActionMethod();

        // An invocable controller registers the class name as its action; reflect __invoke().
        $methodName = $actionMethod === $controllerClass ? '__invoke' : $actionMethod;

        if ($reflection->hasMethod($methodName)) {
            return;
        }

        yield new Finding(
            ruleId: $this->id,
            severity: $this->severity,
            message: sprintf(
                '%s::%s() does not exist; the route points at a missing method and would fault at runtime.',
                $reflection->getShortName(),
                $methodName,
            ),
            fixHint: 'Implement the method, or stop registering the route (e.g. limit apiResource() with only:/except:).',
        );
    }
}
