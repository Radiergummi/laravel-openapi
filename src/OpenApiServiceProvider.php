<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Override;
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
use Radiergummi\OpenApi\Support\Routing\RouteMiddlewareGatherer;
use Radiergummi\OpenApi\Support\Routing\UriParameterResolver;
use Radiergummi\OpenApi\Support\Spec\SpecDefinition;
use Radiergummi\OpenApi\Support\Spec\SpecMatcher;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Radiergummi\OpenApi\Support\Spec\SpecResolver;
use Radiergummi\OpenApi\Support\Spec\SpecRouteConfig;
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
 * Registers and wires all OpenAPI pipeline services.
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
     * Mounts one spec + playground route per defined spec.
     *
     * Reads URIs directly from config rather than resolving {@see SpecRegistry}: the registry
     * would force eager materialisation of `info`/`servers`/`tags` at boot, before later providers
     * can override those keys. Route names: `openapi.spec` / `openapi.playground` for the default
     * spec, `openapi.spec.{name}` / `openapi.playground.{name}` for named ones.
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

        $routeConfig = new SpecRouteConfig(
            rootRouteUri: is_string($config['spec']['uri'] ?? null) ? $config['spec']['uri'] : 'openapi.yaml',
            rootPlaygroundUri: is_string($config['playground']['uri'] ?? null) ? $config['playground']['uri'] : 'docs',
        );

        /** @var array<string, array{route_uri: false|string, playground_uri: false|string}> $entries */
        $entries = [];

        foreach (['default' => [], ...$specs] as $name => $overrides) {
            $name = (string) $name;

            $entries[$name] = [
                'route_uri' => $routeConfig->routeUri($name, $overrides),
                'playground_uri' => $routeConfig->playgroundUri($name, $overrides),
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

    #[Override]
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
     * Binds spec-resolution and route-inclusion services.
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
                    middlewareGatherer: $app->make(RouteMiddlewareGatherer::class),
                );
            },
        );
    }

    /**
     * Binds PHPDoc/type-resolution services used by the route introspection layer.
     */
    private function registerRouting(): void
    {
        // Per-run caches; scoped so Octane resets them between requests.
        $this->app->scoped(DocBlockParser::class, static fn(): DocBlockParser => DocBlockParser::create());
        $this->app->scoped(TypeNodeResolver::class, static fn(): TypeNodeResolver => TypeNodeResolver::create());

        $this->app->scoped(TypeResolver::class, static fn(): TypeResolver => TypeResolver::create());
    }

    /**
     * Binds the OpenAPI registry and the lint rule registry derived from it.
     */
    private function registerRegistries(): void
    {
        // Needs the raw config array; cannot autowire.
        $this->app->scoped(
            OverrideMatcher::class,
            static fn(): OverrideMatcher => new OverrideMatcher(
                (array) config('openapi.overrides', []),
            ),
        );

        // BaselineRegistration::assemble() owns the ordered stage pipeline, rules, and sealing;
        // the provider only supplies Laravel/config-derived inputs (plugin list, lint rules,
        // resolved envelope class).
        $this->app->scoped(
            OpenApiRegistry::class,
            static fn(Container $app): OpenApiRegistry
                => BaselineRegistration::assemble(
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

        // The container can't autowire the resolver list; build it explicitly.
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
     * Maps a preset name or custom FQCN to the concrete {@see ErrorResponseResolver} class.
     * Throws at boot on an unknown preset rather than silently deferring to an autoload error.
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
     * Binds extractors and the findings collector.
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
                    paginatorCallReader: $app->make(Plugins\Core\Support\PaginatorCallReader::class),
                    logger: $app->make(LoggerInterface::class),
                    refSchemaResolvers: self::makeAll($app, $registry->refSchemaResolvers),
                );
            },
        );
    }

    /**
     * Binds request-schema resolvers and related extractors (no plugin convention dependency).
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

        // Core registers no RefSchemaResolver of its own, so no exclusion is needed here.
        $this->app->scoped(
            Plugins\Core\Support\RequestFieldObjectBuilder::class,
            static function (Container $app): Plugins\Core\Support\RequestFieldObjectBuilder {
                $registry = $app->make(OpenApiRegistry::class);

                return new Plugins\Core\Support\RequestFieldObjectBuilder(
                    refSchemaResolvers: self::refSchemaResolverFactory($app, $registry),
                );
            },
        );

        // Closure constructor argument; cannot auto-resolve.
        $this->app->scoped(
            Plugins\Core\Resolvers\RequestFieldRequestSchemaResolver::class,
            static fn(Container $app): Plugins\Core\Resolvers\RequestFieldRequestSchemaResolver
                => new Plugins\Core\Resolvers\RequestFieldRequestSchemaResolver(
                    registry: $app->make(ComponentSchemaRegistry::class),
                    objectBuilder: $app->make(Plugins\Core\Support\RequestFieldObjectBuilder::class),
                ),
        );

        $this->app->scoped(
            Support\Extraction\ModelFactoryExampleReader::class,
            static fn(Container $app): Support\Extraction\ModelFactoryExampleReader
                => new Support\Extraction\ModelFactoryExampleReader(
                    // Null seed when auto-examples are off or no fixed seed is set: factory values
                    // would otherwise be non-deterministic.
                    seed: (bool) (config('openapi.examples.synthesise') ?? true)
                    && config('openapi.examples.faker_seed') !== null
                        ? (int) config('openapi.examples.faker_seed')
                        : null,
                    logger: $app->make(LoggerInterface::class),
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
                    objectBuilder: $app->make(Plugins\Core\Support\RequestFieldObjectBuilder::class),
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
     * Returns a memoized lazy factory over the registry's ref-schema resolver list.
     *
     * Pass `$exclude` to omit a resolver and break cross-plugin construction cycles.
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
     * Binds Spatie Laravel Data plugin services. Skipped when the package is not installed.
     */
    private function registerSpatieDataPlugin(): void
    {
        if (!class_exists(Data::class)) {
            return;
        }

        // FilePropertyChecker is implemented by SchemaFromDataClass; reuse the same instance.
        $this->app->scoped(
            Plugins\SpatieData\Support\FilePropertyChecker::class,
            static fn(Container $app): Plugins\SpatieData\Support\SchemaFromDataClass => $app->make(Plugins\SpatieData\Support\SchemaFromDataClass::class),
        );
    }

    /**
     * Binds ApiResources plugin services. Excludes {@see Plugins\ApiResources\Resolvers\ResourceRefSchemaResolver}
     * from the ref-resolver factory to break the construction cycle with the Fractal plugin.
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
                    toArrayReader: $app->make(Plugins\ApiResources\Support\ResourceToArrayReader::class),
                    wrappedModelLocator: $app->make(Plugins\ApiResources\Support\WrappedModelLocator::class),
                    modelToSchema: $app->make(Support\Extraction\EloquentModelToSchema::class),
                    logger: $app->make(LoggerInterface::class),
                    explicitSchema: $app->make(Support\Generator\ExplicitClassSchema::class),
                );
            },
        );
    }

    /**
     * Binds Fractal plugin services. Excludes {@see TransformerRefSchemaResolver} from the
     * ref-resolver factory to break the construction cycle with the ApiResources plugin.
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
                    transformReader: $app->make(Plugins\Fractal\Support\TransformerTransformReader::class),
                    logger: $app->make(LoggerInterface::class),
                );
            },
        );
    }

    /**
     * Binds SwaggerPhp plugin services. The binding is always registered (swagger-php is a hard
     * dependency); the plugin is inert until listed in `config/openapi.plugins`.
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
     * Binds the operation builder and the top-level generator.
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
                    middlewareGatherer: $app->make(RouteMiddlewareGatherer::class),
                    refSchemaResolvers: self::makeAll($app, $registry->refSchemaResolvers),
                    queryParameterResolvers: self::makeAll($app, $registry->queryParameterResolvers),
                    primaryResponseResolvers: self::makeAll($app, $registry->primaryResponseResolvers),
                    operationConventionResolvers: self::makeAll($app, $registry->operationConventionResolvers),
                );
            },
        );
    }
}
