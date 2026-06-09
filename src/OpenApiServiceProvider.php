<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Console\ClearCommand;
use Radiergummi\OpenApi\Console\DiffConfigCommand;
use Radiergummi\OpenApi\Console\GenerateCommand;
use Radiergummi\OpenApi\Console\LintCommand;
use Radiergummi\OpenApi\Console\WhyCommand;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver;
use Radiergummi\OpenApi\Contracts\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Contracts\Routing\RouteFilter;
use Radiergummi\OpenApi\Http\DocsController;
use Radiergummi\OpenApi\Lint\EventDispatchingFindingsCollector;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Lint\RuleRegistry;
use Radiergummi\OpenApi\Lint\Rules\ResponseRefUnresolvable;
use Radiergummi\OpenApi\Plugins\ApiResources\Support\SchemaFromResource;
use Radiergummi\OpenApi\Plugins\Core\CorePlugin;
use Radiergummi\OpenApi\Plugins\Core\Envelopes\JsonApiEnvelope;
use Radiergummi\OpenApi\Plugins\Core\Envelopes\LaravelEnvelope;
use Radiergummi\OpenApi\Plugins\Core\Envelopes\NoneEnvelope;
use Radiergummi\OpenApi\Plugins\Core\Envelopes\Rfc7807Envelope;
use Radiergummi\OpenApi\Plugins\Core\Resolvers\PaginatorResponseResolver;
use Radiergummi\OpenApi\Plugins\Core\Support\PaginatorSchemaFactory;
use Radiergummi\OpenApi\Plugins\Fractal\Resolvers\TransformerRefSchemaResolver;
use Radiergummi\OpenApi\Plugins\Fractal\Support\SchemaFromTransformer;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;
use Radiergummi\OpenApi\Support\Extraction\FakerExampleSynthesiser;
use Radiergummi\OpenApi\Support\Generator\BaselineRegistration;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\ExampleFileLoader;
use Radiergummi\OpenApi\Support\Generator\OperationBuilder;
use Radiergummi\OpenApi\Support\Generator\OverrideMatcher;
use Radiergummi\OpenApi\Support\Generator\Stages\ErrorResponseInferenceStage;
use Radiergummi\OpenApi\Support\Inclusion\InclusionEvaluator;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Routing\ReturnTypeExtractor;
use Radiergummi\OpenApi\Support\Routing\UriParameterResolver;
use Radiergummi\OpenApi\Support\Spec\SpecDefinition;
use Radiergummi\OpenApi\Support\Spec\SpecMatcher;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Radiergummi\OpenApi\Support\Spec\SpecResolver;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use Radiergummi\OpenApi\Support\Visibility\VisibilityResolver;
use RuntimeException;
use Spatie\LaravelData\Data;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

use function class_exists;
use function config;
use function is_a;
use function sprintf;

/**
 * OpenAPI Service Provider
 *
 * The pipeline is registered as `scoped `, so Octane resets it between requests —
 * {@see ComponentSchemaRegistry} and {@see ExampleFileLoader} carry mutable per-run state that
 * would otherwise corrupt concurrent runs. To re-run generation within a single scope (e.g. tests),
 * call `$app->forgetScopedInstances()` first.
 *
 * Classes with fully container-resolvable constructors carry `#[Scoped]` and self-register;
 * this provider only contains bindings that need explicit closures.
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
                DiffConfigCommand::class,
            ]);
        }

        $this->registerRoutes();
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
        $this->registerSwaggerPhpPlugin();
        $this->registerGenerator();
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
     * Binds the route introspection services and the route exclusion filters.
     */
    private function registerRouting(): void
    {
        // PHPDoc parsing + type resolution. ThrowsExtractor and ReturnTypeExtractor autowire
        // these via #[Scoped]; both carry per-run caches, so they are scoped (Octane-reset).
        $this->app->scoped(DocBlockParser::class, static fn(): DocBlockParser => DocBlockParser::create());
        $this->app->scoped(TypeNodeResolver::class, static fn(): TypeNodeResolver => TypeNodeResolver::create());

        $this->app->scoped(TypeResolver::class, static fn() => TypeResolver::create());
    }

    /**
     * Binds the plugin/core registry and the lint rule registry derived from it.
     */
    private function registerRegistries(): void
    {
        // OverrideMatcher needs the raw config array, so it cannot autowire from an empty
        // constructor. Scoped to match the rest of the pipeline (Octane-reset per run).
        $this->app->scoped(
            OverrideMatcher::class,
            static fn(): OverrideMatcher => new OverrideMatcher(
                (array) config('openapi.overrides', []),
            ),
        );

        // BaselineRegistration::assemble() owns the ordered stage pipeline + rules + sealing. The
        // provider supplies only the Laravel/config-derived inputs (the plugin list — Core first —,
        // the config lint rules, and the resolved error-envelope class), keeping the plugin-agnostic
        // assembly in Support while the plugin references stay here.
        $this->app->scoped(
            OpenApiRegistry::class,
            static fn(Container $app): OpenApiRegistry => BaselineRegistration::assemble(
                $app,
                plugins: [CorePlugin::class, ...config('openapi.plugins', [])],
                configRules: config('openapi.lint.rules', []),
                errorEnvelopeResolver: OpenApiServiceProvider::resolveErrorEnvelopeClass(
                    (string) config('openapi.error_envelope', 'none'),
                ),
            ),
        );

        $this->app->scoped(
            RuleRegistry::class,
            static function (Container $app): RuleRegistry {
                $registry = $app->make(OpenApiRegistry::class);

                return new RuleRegistry(
                    self::makeAll($app, $registry->rules),
                    severityOverrides: (array) config('openapi.lint.severity_overrides', []),
                );
            },
        );

        // The rule needs the registered ref-schema resolver chain to answer "would this ref
        // resolve?" — the container can't autowire the resolver list, so build it explicitly.
        $this->app->scoped(
            ResponseRefUnresolvable::class,
            static function (Container $app): ResponseRefUnresolvable {
                $registry = $app->make(OpenApiRegistry::class);

                return new ResponseRefUnresolvable(
                    refSchemaResolvers: self::makeAll($app, $registry->refSchemaResolvers),
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
            'none' => NoneEnvelope::class,
            'laravel' => LaravelEnvelope::class,
            'rfc7807' => Rfc7807Envelope::class,
            'json-api' => JsonApiEnvelope::class,
            default => self::validateCustomEnvelopeClass($envelope),
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
            throw new InvalidArgumentException(
                sprintf(
                    'Unknown error_envelope "%s". Known presets: none, laravel, rfc7807, json-api.'
                    . ' Or supply a fully-qualified class name implementing %s.',
                    $envelope,
                    ErrorResponseResolver::class,
                ),
            );
        }

        if (!is_a($envelope, ErrorResponseResolver::class, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Class %s does not implement %s.',
                    $envelope,
                    ErrorResponseResolver::class,
                ),
            );
        }

        /** @var class-string<ErrorResponseResolver> $envelope */
        return $envelope;
    }

    /**
     * Instantiate each class-string through the container, preserving order. Centralises the
     * resolver-list hydration repeated across the extractor and generator bindings.
     *
     * @template TInstance of object
     *
     * @param list<class-string<TInstance>> $classes
     *
     * @return list<TInstance>
     *
     * @throws BindingResolutionException
     */
    private static function makeAll(Container $app, array $classes): array
    {
        $instances = [];

        foreach ($classes as $class) {
            $instances[] = $app->make($class);
        }

        return $instances;
    }

    /**
     * Build a memoised lazy factory over the registry's ref-schema resolver list, optionally
     * skipping one resolver class — a plugin passes its own resolver to break the cross-plugin
     * construction cycle (see {@see registerApiResourcesPlugin()} and {@see registerFractalPlugin()}).
     * Resolution is deferred to first use so the container can finish constructing both sides; the
     * result is cached per returned factory.
     *
     * @param null|class-string<RefSchemaResolver> $exclude
     *
     * @return Closure(): list<RefSchemaResolver>
     */
    private static function refSchemaResolverFactory(
        Container $app,
        OpenApiRegistry $registry,
        ?string $exclude = null,
    ): Closure {
        /** @var null|list<RefSchemaResolver> $cache */
        $cache = null;

        return static function () use ($app, $registry, $exclude, &$cache) {
            if ($cache !== null) {
                return $cache;
            }

            $resolvers = [];

            foreach ($registry->refSchemaResolvers as $class) {
                if ($class === $exclude) {
                    continue;
                }

                $resolvers[] = $app->make($class);
            }

            return $cache = $resolvers;
        };
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
                    refSchemaResolvers: self::makeAll($app, $registry->refSchemaResolvers),
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
            Plugins\Core\Resolvers\DiscriminatedRequestSchemaResolver::class,
            static function (Container $app): Plugins\Core\Resolvers\DiscriminatedRequestSchemaResolver {
                $registry = $app->make(OpenApiRegistry::class);

                return new Plugins\Core\Resolvers\DiscriminatedRequestSchemaResolver(
                    registry: $app->make(ComponentSchemaRegistry::class),
                    refSchemaResolvers: self::refSchemaResolverFactory($app, $registry),
                    findings: $app->make(FindingsCollector::class),
                );
            },
        );

        $this->app->scoped(
            Support\Extraction\RequestBodyExtractor::class,
            static function (Container $app): Support\Extraction\RequestBodyExtractor {
                $registry = $app->make(OpenApiRegistry::class);

                return new Support\Extraction\RequestBodyExtractor(
                    resolvers: self::makeAll($app, $registry->requestSchemaResolvers),
                    findings: $app->make(FindingsCollector::class),
                    faultBoundary: $app->make(Support\Registry\ResolverFaultBoundary::class),
                );
            },
        );

        $this->app->scoped(
            ErrorResponseInferenceStage::class,
            static function (Container $app): ErrorResponseInferenceStage {
                $registry = $app->make(OpenApiRegistry::class);

                return new ErrorResponseInferenceStage(
                    contributors: self::makeAll($app, $registry->errorResponseContributors),
                    errorResponseResolvers: self::makeAll($app, $registry->errorResponseResolvers),
                    registry: $app->make(ComponentSchemaRegistry::class),
                    findings: $app->make(FindingsCollector::class),
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
            Plugins\SpatieData\Support\FilePropertyChecker::class,
            static fn(Container $app) => $app->make(Plugins\SpatieData\Support\SchemaFromDataClass::class),
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
            Contracts\Routing\ResourceTargetLocator::class,
            Plugins\ApiResources\Support\ResourceClassLocator::class,
        );

        $this->app->scoped(
            SchemaFromResource::class,
            static function (Container $app): SchemaFromResource {
                $registry = $app->make(OpenApiRegistry::class);

                return new SchemaFromResource(
                    registry: $app->make(ComponentSchemaRegistry::class),
                    refSchemaResolvers: self::refSchemaResolverFactory(
                        $app,
                        $registry,
                        Plugins\ApiResources\Resolvers\ResourceRefSchemaResolver::class,
                    ),
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
            SchemaFromTransformer::class,
            static function (Container $app): SchemaFromTransformer {
                $registry = $app->make(OpenApiRegistry::class);

                return new SchemaFromTransformer(
                    registry: $app->make(ComponentSchemaRegistry::class),
                    refSchemaResolvers: self::refSchemaResolverFactory(
                        $app,
                        $registry,
                        TransformerRefSchemaResolver::class,
                    ),
                );
            },
        );
    }

    /**
     * Binds the SwaggerPhp plugin services.
     *
     * The scanner runs swagger-php over the host app once per generation, so it is scoped (the
     * per-run scan is shared across stages and reset between Octane requests). Its scan path is an
     * internal default — the app directory — not user config; tests rebind this to a fixture
     * directory. swagger-php is a hard dependency, so the binding is always registered; the plugin
     * stays inert until listed in `config/openapi.plugins`.
     */
    private function registerSwaggerPhpPlugin(): void
    {
        $this->app->scoped(
            Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner::class,
            static fn(Container $app): Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner
                => new Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner(
                    [app_path()],
                    $app->make(LoggerInterface::class),
                ),
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
                    uriExtractor: $app->make(Support\Extraction\UriParametersExtractor::class),
                    bodyExtractor: $app->make(Support\Extraction\RequestBodyExtractor::class),
                    securityExtractor: $app->make(Support\Extraction\SecurityExtractor::class),
                    fileLoader: $app->make(ExampleFileLoader::class),
                    faultBoundary: $app->make(Support\Registry\ResolverFaultBoundary::class),
                    docBlockParser: $app->make(DocBlockParser::class),
                    refSchemaResolvers: self::makeAll($app, $registry->refSchemaResolvers),
                    queryParameterResolvers: self::makeAll($app, $registry->queryParameterResolvers),
                    primaryResponseResolvers: self::makeAll($app, $registry->primaryResponseResolvers),
                    operationConventionResolvers: self::makeAll($app, $registry->operationConventionResolvers),
                );
            },
        );
    }
}
