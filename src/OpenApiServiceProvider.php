<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\DocBlockFactoryInterface;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Console\ClearCommand;
use Radiergummi\OpenApi\Console\GenerateCommand;
use Radiergummi\OpenApi\Console\LintCommand;
use Radiergummi\OpenApi\Console\WhyCommand;
use Radiergummi\OpenApi\Core\Errors\JsonApiEnvelope;
use Radiergummi\OpenApi\Core\Errors\LaravelEnvelope;
use Radiergummi\OpenApi\Core\Errors\NoneEnvelope;
use Radiergummi\OpenApi\Core\Errors\Rfc7807Envelope;
use Radiergummi\OpenApi\Core\Extractors;
use Radiergummi\OpenApi\Core\Extractors\PaginatorResponseResolver;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Generator\ExampleFileLoader;
use Radiergummi\OpenApi\Core\Generator\Examples\FakerExampleSynthesiser;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Core\Generator\OperationBuilder;
use Radiergummi\OpenApi\Core\Generator\PaginatorSchemaFactory;
use Radiergummi\OpenApi\Core\Inclusion\InclusionEvaluator;
use Radiergummi\OpenApi\Core\Lint\EventDispatchingFindingsCollector;
use Radiergummi\OpenApi\Core\Lint\FindingsCollector;
use Radiergummi\OpenApi\Core\Lint\RuleRegistry;
use Radiergummi\OpenApi\Core\Registry\CoreRegistration;
use Radiergummi\OpenApi\Core\Registry\ErrorResponseResolver;
use Radiergummi\OpenApi\Core\Registry\OpenApiRegistry;
use Radiergummi\OpenApi\Core\Registry\Plugin;
use Radiergummi\OpenApi\Core\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Core\Routing\Filters\RouteFilter;
use Radiergummi\OpenApi\Core\Routing\ReturnTypeExtractor;
use Radiergummi\OpenApi\Core\Routing\UriParameterResolver;
use Radiergummi\OpenApi\Core\Spec\SpecDefinition;
use Radiergummi\OpenApi\Core\Spec\SpecMatcher;
use Radiergummi\OpenApi\Core\Spec\SpecRegistry;
use Radiergummi\OpenApi\Core\Spec\SpecResolver;
use Radiergummi\OpenApi\Core\Visibility\VisibilityResolver;
use Radiergummi\OpenApi\Http\DocsController;
use RuntimeException;
use Spatie\LaravelData\Data;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

use function class_exists;
use function is_a;
use function sprintf;

/**
 * Wires the OpenAPI generation pipeline.
 *
 * Most classes in the pipeline are stateless and could be singletons, but
 * {@see ComponentSchemaRegistry} and {@see ExampleFileLoader} carry mutable per-run state.
 * Under Octane the same PHP process handles many requests, so concurrent calls to
 * {@see OpenApiGenerator::generate()} would corrupt each other's in-progress cycle guards if
 * these were singletons.
 *
 * **Fix (Approach A):** the entire pipeline is registered as `scoped`. Octane resets scoped
 * bindings between requests, giving each generation run its own fresh instances. To re-run
 * generation within a single scope (rare — but exercised by tests and possible from custom
 * tooling), call `$app->forgetScopedInstances()` first.
 *
 * Wiring style: classes whose constructor dependencies are all container-resolvable carry the
 * `#[Scoped]` attribute ({@see Scoped}) and self-register on first resolve. The provider only
 * contains bindings that need explicit closures — config values, factory methods,
 * registry-derived arrays, interface→implementation mappings, or decorated wrappers reflection
 * cannot supply.
 */
class OpenApiServiceProvider extends ServiceProvider
{
    /**
     * @throws BindingResolutionException
     * @throws RuntimeException
     */
    public function boot(): void
    {
        $this->optimizes(
            optimize: 'openapi:generate',
            clear: 'openapi:clear',
            key: 'openapi',
        );

        $this->publishes(
            [__DIR__ . '/../config/openapi.php' => config_path('openapi.php')],
            'openapi-config',
        );

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'openapi');

        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateCommand::class,
                LintCommand::class,
                ClearCommand::class,
                WhyCommand::class,
            ]);
        }

        $this->registerRoutes();
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/openapi.php', 'openapi');

        $this->registerSpec();
        $this->registerRouting();
        $this->registerRegistries();
        $this->registerExtractors();
        $this->registerRequestSchemas();
        $this->registerSpatieDataPlugin();
        $this->registerApiResourcesPlugin();
        $this->registerFractalPlugin();
        $this->registerGenerator();
    }

    /**
     * Binds the route introspection services and the route exclusion filters.
     */
    private function registerRouting(): void
    {
        // phpDocumentor's only factory call; ThrowsExtractor and ReturnTypeExtractor autowire
        // off this binding plus a container-resolved ContextFactory via `#[Scoped]`.
        $this->app->scoped(
            DocBlockFactoryInterface::class,
            static fn() => DocBlockFactory::createInstance(),
        );

        $this->app->scoped(TypeResolver::class, static fn() => TypeResolver::create());
    }

    /**
     * Binds the plugin/core registry and the lint rule registry derived from it.
     */
    private function registerRegistries(): void
    {
        $this->app->scoped(
            OpenApiRegistry::class,
            static function (Container $app): OpenApiRegistry {
                $registry = new OpenApiRegistry();

                CoreRegistration::register($registry);

                foreach (config('openapi.plugins', []) as $pluginClass) {
                    $plugin = $app->make($pluginClass);
                    assert($plugin instanceof Plugin);
                    $plugin->register($registry);
                }

                foreach (config('openapi.lint.rules', []) as $ruleClass) {
                    $registry->addRule($ruleClass);
                }

                $registry->addErrorResponseResolver(
                    OpenApiServiceProvider::resolveErrorEnvelopeClass(
                        (string) config('openapi.error_envelope', 'none'),
                    ),
                );

                return $registry;
            },
        );

        $this->app->scoped(
            RuleRegistry::class,
            static function (Container $app): RuleRegistry {
                $registry = $app->make(OpenApiRegistry::class);

                return new RuleRegistry(
                    array_map(
                        static fn(string $class) => $app->make($class),
                        $registry->rules(),
                    ),
                    severityOverrides: (array) config('openapi.lint.severity_overrides', []),
                );
            },
        );
    }

    /**
     * Binds the schema registry and the operation-data extractors.
     */
    private function registerExtractors(): void
    {
        $this->app->scoped(
            FindingsCollector::class,
            EventDispatchingFindingsCollector::class,
        );

        $this->app->scoped(
            PaginatorResponseResolver::class,
            static function (Container $app): PaginatorResponseResolver {
                $registry = $app->make(OpenApiRegistry::class);

                return new PaginatorResponseResolver(
                    returnTypeExtractor: $app->make(ReturnTypeExtractor::class),
                    schemaFactory: $app->make(PaginatorSchemaFactory::class),
                    logger: $app->make(LoggerInterface::class),
                    refSchemaResolvers: array_map(
                        static fn(string $class) => $app->make($class),
                        $registry->refSchemaResolvers(),
                    ),
                );
            },
        );
    }

    /**
     * Binds the Core request/response extractors and the FormRequest schema resolver.
     *
     * These bindings carry no dependency on any plugin convention package and are needed
     * regardless of which plugins are enabled.
     */
    private function registerRequestSchemas(): void
    {
        $this->app->scoped(
            FakerExampleSynthesiser::class,
            static fn(): FakerExampleSynthesiser => new FakerExampleSynthesiser(
                enabled: (bool) (config('openapi.examples.synthesise') ?? true),
                seed: config('openapi.examples.faker_seed') !== null
                    ? (int) config('openapi.examples.faker_seed')
                    : null,
            ),
        );

        $this->app->scoped(
            Extractors\RequestBodyExtractor::class,
            static function (Container $app): Extractors\RequestBodyExtractor {
                $registry = $app->make(OpenApiRegistry::class);

                return new Extractors\RequestBodyExtractor(
                    resolvers: array_map(
                        static fn(string $class) => $app->make($class),
                        $registry->requestSchemaResolvers(),
                    ),
                    findings: $app->make(FindingsCollector::class),
                );
            },
        );

        $this->app->scoped(
            Extractors\StandardResponsesExtractor::class,
            static function (Container $app): Extractors\StandardResponsesExtractor {
                $registry = $app->make(OpenApiRegistry::class);

                return new Extractors\StandardResponsesExtractor(
                    registry: $app->make(ComponentSchemaRegistry::class),
                    findings: $app->make(FindingsCollector::class),
                    errorResponseResolvers: array_map(
                        static fn(string $class) => $app->make($class),
                        $registry->errorResponseResolvers(),
                    ),
                    exceptionMap: (array) config('openapi.exception_responses', []),
                    middlewareMap: (array) config('openapi.middleware_responses', []),
                );
            },
        );
    }

    /**
     * Binds the Spatie Laravel Data plugin services.
     *
     * `spatie/laravel-data` is an optional runtime dependency. When the package is not
     * installed every binding is skipped — {@see SpatieDataPlugin::register()} also no-ops
     * under the same guard, so the plugin entry in `config/openapi.plugins` stays inert
     * without producing autoload errors when Spatie types are referenced via the container.
     */
    private function registerSpatieDataPlugin(): void
    {
        if (!class_exists(Data::class)) {
            return;
        }

        // FilePropertyChecker is an interface implemented by SchemaFromDataClass; share the
        // same scoped instance instead of constructing a second one. The concrete classes
        // self-register via `#[Scoped]`.
        $this->app->scoped(
            Plugins\SpatieData\FilePropertyChecker::class,
            static fn(Container $app) => $app->make(Plugins\SpatieData\SchemaFromDataClass::class),
        );
    }

    /**
     * Binds the ApiResources plugin services.
     *
     * `SchemaFromResource` receives a LAZY factory for the ref-resolver
     * list — every registered ref resolver except this plugin's own
     * `ResourceRefSchemaResolver`. Eager construction would form a
     * cross-plugin construction cycle with `SchemaFromTransformer` (each
     * plugin's builder lists the other plugin's resolver). Deferring resolution
     * to first use lets the container finish constructing both sides first; the
     * factory memoises its result with a closure-local static so repeated
     * resolveClassRef calls don't re-walk the registry.
     */
    private function registerApiResourcesPlugin(): void
    {
        $this->app->scoped(
            Plugins\ApiResources\SchemaFromResource::class,
            static function (Container $app): Plugins\ApiResources\SchemaFromResource {
                $registry = $app->make(OpenApiRegistry::class);

                /** @throws BindingResolutionException */
                $resolversFactory = static function () use ($app, $registry): array {
                    /** @var null|list<RefSchemaResolver> $cache */
                    static $cache = null;

                    if ($cache !== null) {
                        return $cache;
                    }

                    /** @var list<RefSchemaResolver> $resolvers */
                    $resolvers = [];

                    foreach ($registry->refSchemaResolvers() as $class) {
                        if ($class === Plugins\ApiResources\ResourceRefSchemaResolver::class) {
                            continue;
                        }

                        $resolvers[] = $app->make($class);
                    }

                    return $cache = $resolvers;
                };

                return new Plugins\ApiResources\SchemaFromResource(
                    registry: $app->make(ComponentSchemaRegistry::class),
                    refSchemaResolvers: $resolversFactory,
                );
            },
        );
    }

    /**
     * Binds the Fractal plugin services.
     *
     * {@see SchemaFromTransformer} receives a LAZY factory for the ref-resolver list — every
     * registered ref resolver except this plugin's own {@see TransformerRefSchemaResolver}.
     * Eager construction would form a cross-plugin construction cycle with
     * {@see SchemaFromResource} (each plugin's builder lists the other plugin's resolver).
     * Deferring resolution to first use lets the container finish constructing both sides first;
     * the factory memoises its result with a closure-local static so repeated `resolveClassRef`
     * calls don't re-walk the registry.
     */
    private function registerFractalPlugin(): void
    {
        $this->app->scoped(
            Plugins\Fractal\SchemaFromTransformer::class,
            static function (Container $app): Plugins\Fractal\SchemaFromTransformer {
                $registry = $app->make(OpenApiRegistry::class);

                /** @throws BindingResolutionException */
                $resolversFactory = static function () use ($app, $registry): array {
                    /** @var null|list<RefSchemaResolver> $cache */
                    static $cache = null;

                    if ($cache !== null) {
                        return $cache;
                    }

                    /** @var list<RefSchemaResolver> $resolvers */
                    $resolvers = [];

                    foreach ($registry->refSchemaResolvers() as $class) {
                        if ($class === Plugins\Fractal\TransformerRefSchemaResolver::class) {
                            continue;
                        }

                        $resolvers[] = $app->make($class);
                    }

                    return $cache = $resolvers;
                };

                return new Plugins\Fractal\SchemaFromTransformer(
                    registry: $app->make(ComponentSchemaRegistry::class),
                    refSchemaResolvers: $resolversFactory,
                );
            },
        );
    }

    /**
     * Binds the operation builder and the top-level generator that drives the pipeline.
     */
    private function registerGenerator(): void
    {
        $this->app->scoped(
            OperationBuilder::class,
            static function (Container $app): OperationBuilder {
                $registry = $app->make(OpenApiRegistry::class);

                return new OperationBuilder(
                    uriResolver: $app->make(UriParameterResolver::class),
                    uriExtractor: $app->make(Extractors\UriParametersExtractor::class),
                    bodyExtractor: $app->make(Extractors\RequestBodyExtractor::class),
                    securityExtractor: $app->make(Extractors\SecurityExtractor::class),
                    standardResponsesExtractor: $app->make(Extractors\StandardResponsesExtractor::class),
                    fileLoader: $app->make(ExampleFileLoader::class),
                    refSchemaResolvers: array_map(
                        static fn(string $class) => $app->make($class),
                        $registry->refSchemaResolvers(),
                    ),
                    queryParameterResolvers: array_map(
                        static fn(string $class) => $app->make($class),
                        $registry->queryParameterResolvers(),
                    ),
                    primaryResponseResolvers: array_map(
                        static fn(string $class) => $app->make($class),
                        $registry->primaryResponseResolvers(),
                    ),
                );
            },
        );
    }

    /**
     * Binds the spec-related services: SpecMatcher, SpecResolver, SpecRegistry, InclusionEvaluator.
     */
    private function registerSpec(): void
    {
        $this->app->scoped(
            InclusionEvaluator::class,
            static function (Container $app): InclusionEvaluator {
                $filterClasses = (array) config('openapi.filters', []);
                $filters = array_values(
                    array_map(
                        static function (mixed $entry) use ($app): RouteFilter {
                            return is_string($entry) ? $app->make($entry) : $entry;
                        },
                        $filterClasses,
                    ),
                );

                return new InclusionEvaluator(
                    globalFilters: $filters,
                    matcher: $app->make(SpecMatcher::class),
                    specResolver: $app->make(SpecResolver::class),
                    visibility: $app->make(VisibilityResolver::class),
                );
            },
        );
    }

    /**
     * Resolve the configured error envelope to its resolver class.
     *
     * Accepts the four preset names (`'none'`, `'laravel'`, `'rfc7807'`, `'json-api'`) or a
     * fully-qualified class name of a custom {@see ErrorResponseResolver}. Throws on an
     * unknown preset name so failures surface at boot, not later as an autoload error.
     *
     * @return class-string<ErrorResponseResolver>
     *
     * @throws InvalidArgumentException
     */
    private static function resolveErrorEnvelopeClass(string $envelope): string
    {
        return match ($envelope) {
            'none'     => NoneEnvelope::class,
            'laravel'  => LaravelEnvelope::class,
            'rfc7807'  => Rfc7807Envelope::class,
            'json-api' => JsonApiEnvelope::class,
            default    => self::validateCustomEnvelopeClass($envelope),
        };
    }

    /**
     * @return class-string<ErrorResponseResolver>
     *
     * @throws InvalidArgumentException
     */
    private static function validateCustomEnvelopeClass(string $envelope): string
    {
        if (!class_exists($envelope)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown error_envelope "%s". Known presets: none, laravel, rfc7807, json-api.'
                . ' Or supply a fully-qualified class name implementing %s.',
                $envelope,
                ErrorResponseResolver::class,
            ));
        }

        if (!is_a($envelope, ErrorResponseResolver::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Class %s does not implement %s.',
                $envelope,
                ErrorResponseResolver::class,
            ));
        }

        /** @var class-string<ErrorResponseResolver> $envelope */
        return $envelope;
    }

    /**
     * Conditionally mounts one spec + playground route per defined spec.
     *
     * Reads route URIs directly from config rather than resolving {@see SpecRegistry}. The registry
     * would force eager materialisation of `info`/`servers`/`tags` at boot, which is too early —
     * later-booting providers (e.g. example flavors) are still entitled to override those keys
     * before generation runs. Only the URI fields are needed here, and URI resolution is replicated
     * inline to keep `default`'s name correct even when an explicit `'specs.default'` entry exists.
     *
     * Route names follow the convention:
     *   - default spec: `openapi.spec` / `openapi.playground`
     *   - named specs:  `openapi.spec.{name}` / `openapi.playground.{name}`
     *
     * @throws RuntimeException
     */
    private function registerRoutes(): void
    {
        $config = (array) config('openapi.routes', []);

        if (($config['enabled'] ?? false) !== true) {
            return;
        }

        /** @var array<string, array<string, mixed>> $specs */
        $specs = is_array(config('openapi.specs')) ? config('openapi.specs') : [];

        $rootRouteUri = is_string($config['spec']['uri'] ?? null)
            ? $config['spec']['uri']
            : 'openapi.yaml';
        $rootPlaygroundUri = is_string($config['playground']['uri'] ?? null)
            ? $config['playground']['uri']
            : 'docs';

        /** @var array<string, array{route_uri: false|string, playground_uri: false|string}> $entries */
        $entries = [];

        foreach (['default' => [], ...$specs] as $name => $overrides) {
            $name = (string) $name;
            $isDefault = $name === 'default';

            $rawRoute = $overrides['route_uri']
                ?? ($isDefault ? $rootRouteUri : sprintf('openapi-%s.yaml', $name));
            $rawPlayground = $overrides['playground_uri']
                ?? ($isDefault ? $rootPlaygroundUri : sprintf('docs/%s', $name));

            $entries[$name] = [
                'route_uri' => $rawRoute === false ? false : (string) $rawRoute,
                'playground_uri' => $rawPlayground === false ? false : (string) $rawPlayground,
            ];
        }

        Route::group([
            'prefix' => $config['prefix'] ?? 'api',
            'middleware' => $config['middleware'] ?? ['web'],
        ], static function () use ($config, $entries): void {
            foreach ($entries as $name => $entry) {
                if (is_string($entry['route_uri']) && ($config['spec']['enabled'] ?? true)) {
                    Route::get($entry['route_uri'], [DocsController::class, 'spec'])
                        ->defaults('spec', $name)
                        ->name(SpecDefinition::specRouteNameFor($name));
                }

                if (is_string($entry['playground_uri']) && ($config['playground']['enabled'] ?? false)) {
                    Route::get($entry['playground_uri'], [DocsController::class, 'playground'])
                        ->defaults('spec', $name)
                        ->name(SpecDefinition::playgroundRouteNameFor($name));
                }
            }
        });
    }
}
