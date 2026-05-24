<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi;

use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Console\ClearCommand;
use Radiergummi\OpenApi\Console\GenerateCommand;
use Radiergummi\OpenApi\Console\LintCommand;
use Radiergummi\OpenApi\Console\WhyCommand;
use Radiergummi\OpenApi\Core\Extractors;
use Radiergummi\OpenApi\Core\Extractors\PaginatorResponseResolver;
use Radiergummi\OpenApi\Core\Extractors\PayloadParameterScanner;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Generator\ExampleFileLoader;
use Radiergummi\OpenApi\Core\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerationOrchestrator;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Core\Generator\OperationBuilder;
use Radiergummi\OpenApi\Core\Generator\PaginatorSchemaFactory;
use Radiergummi\OpenApi\Core\Inclusion\InclusionEvaluator;
use Radiergummi\OpenApi\Core\Lint\EventDispatchingFindingsCollector;
use Radiergummi\OpenApi\Core\Lint\FindingsCollector;
use Radiergummi\OpenApi\Core\Lint\IdentifierCase;
use Radiergummi\OpenApi\Core\Lint\LintRouteFilter;
use Radiergummi\OpenApi\Core\Lint\LintRunner;
use Radiergummi\OpenApi\Core\Lint\LoggingFindingsCollector;
use Radiergummi\OpenApi\Core\Lint\RuleRegistry;
use Radiergummi\OpenApi\Core\Lint\Rules\ComponentNameNamingInconsistent;
use Radiergummi\OpenApi\Core\Lint\Rules\FieldNameNamingInconsistent;
use Radiergummi\OpenApi\Core\Lint\Rules\HeaderNameNamingInconsistent;
use Radiergummi\OpenApi\Core\Lint\Rules\OperationIdNamingInconsistent;
use Radiergummi\OpenApi\Core\Lint\Rules\ParameterNameNamingInconsistent;
use Radiergummi\OpenApi\Core\Lint\Rules\PathSegmentNamingInconsistent;
use Radiergummi\OpenApi\Core\Lint\Rules\TagNameNamingInconsistent;
use Radiergummi\OpenApi\Core\Lint\SuppressionCollector;
use Radiergummi\OpenApi\Core\Registry\CoreRegistration;
use Radiergummi\OpenApi\Core\Registry\OpenApiRegistry;
use Radiergummi\OpenApi\Core\Registry\Plugin;
use Radiergummi\OpenApi\Core\Routing\DocCommentParser;
use Radiergummi\OpenApi\Core\Routing\Filters\RouteFilter;
use Radiergummi\OpenApi\Core\Routing\Filters\SkipIgnitionRoutes;
use Radiergummi\OpenApi\Core\Routing\Filters\SkipNovaRoutes;
use Radiergummi\OpenApi\Core\Routing\Filters\SkipPassportRoutes;
use Radiergummi\OpenApi\Core\Routing\Filters\SkipTelescopeRoutes;
use Radiergummi\OpenApi\Core\Routing\ReturnTypeExtractor;
use Radiergummi\OpenApi\Core\Routing\RouteIntrospector;
use Radiergummi\OpenApi\Core\Routing\ThrowsExtractor;
use Radiergummi\OpenApi\Core\Routing\UriParameterResolver;
use Radiergummi\OpenApi\Core\Spec\SpecDefinition;
use Radiergummi\OpenApi\Core\Spec\SpecMatcher;
use Radiergummi\OpenApi\Core\Spec\SpecRegistry;
use Radiergummi\OpenApi\Core\Spec\SpecResolver;
use Radiergummi\OpenApi\Core\Visibility\VisibilityMode;
use Radiergummi\OpenApi\Core\Visibility\VisibilityResolver;
use Radiergummi\OpenApi\Http\DocsController;
use Radiergummi\OpenApi\Plugins\SpatieData\DataRefSchemaResolver;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\DataConfig;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

use function class_exists;

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
 * The routing bindings ({@see ThrowsExtractor}, {@see RouteIntrospector},
 * {@see UriParameterResolver}) are registered here too, since {@see RouteIntrospector} and
 * {@see UriParameterResolver} are resolved from the container by the pipeline.
 */
class OpenApiServiceProvider extends ServiceProvider
{
    /**
     * @throws BindingResolutionException
     */
    public function boot(): void
    {
        $this->optimizes(
            optimize: 'openapi:generate',
            clear: 'openapi:clear',
            key: 'openapi',
        );

        $this->publishes(
            [__DIR__.'/../config/openapi.php' => config_path('openapi.php')],
            'openapi-config',
        );

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'openapi');

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
        $this->mergeConfigFrom(__DIR__.'/../config/openapi.php', 'openapi');

        $this->registerSpec();
        $this->registerRouting();
        $this->registerRegistries();
        $this->registerLintRules();
        $this->registerExtractors();
        $this->registerRequestSchemas();
        $this->registerSpatieDataPlugin();
        $this->registerApiResourcesPlugin();
        $this->registerFractalPlugin();
        $this->registerQueryBuilderPlugin();
        $this->registerGenerator();
    }

    /**
     * Binds the route introspection services and the route exclusion filters.
     */
    private function registerRouting(): void
    {
        $this->app->scoped(
            ThrowsExtractor::class,
            static fn () => ThrowsExtractor::create(),
        );

        $this->app->scoped(
            ReturnTypeExtractor::class,
            static fn () => ReturnTypeExtractor::create(),
        );

        $this->app->scoped(SkipNovaRoutes::class, static fn (): SkipNovaRoutes => SkipNovaRoutes::fromConfig());
        $this->app->scoped(SkipTelescopeRoutes::class, static fn (): SkipTelescopeRoutes => SkipTelescopeRoutes::fromConfig());
        $this->app->scoped(SkipIgnitionRoutes::class, static fn (): SkipIgnitionRoutes => SkipIgnitionRoutes::fromConfig());
        $this->app->scoped(SkipPassportRoutes::class, static fn (): SkipPassportRoutes => SkipPassportRoutes::fromConfig());

        // Filters live in InclusionEvaluator alone; the introspector yields every route.
        $this->app->scoped(
            RouteIntrospector::class,
            static fn (Container $app): RouteIntrospector => new RouteIntrospector(
                router: $app->make(Router::class),
                container: $app,
                parser: new DocCommentParser(),
                throwsExtractor: $app->make(ThrowsExtractor::class),
            ),
        );

        $this->app->scoped(
            UriParameterResolver::class,
            static fn () => new UriParameterResolver(TypeResolver::create()),
        );
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

                return $registry;
            },
        );

        $this->app->scoped(
            RuleRegistry::class,
            static function (Container $app): RuleRegistry {
                $registry = $app->make(OpenApiRegistry::class);

                return new RuleRegistry(
                    array_map(
                        static fn (string $class) => $app->make($class),
                        $registry->rules(),
                    ),
                    severityOverrides: (array) config('openapi.lint.severity_overrides', []),
                );
            },
        );
    }

    /**
     * Binds the naming-convention lint rules, suppression and findings collectors.
     */
    private function registerLintRules(): void
    {
        $namingRules = [
            OperationIdNamingInconsistent::class => ['operation_id_case', 'dot'],
            FieldNameNamingInconsistent::class => ['property_name_case', 'camel'],
            PathSegmentNamingInconsistent::class => ['path_segment_case', 'kebab'],
            ParameterNameNamingInconsistent::class => ['parameter_name_case', 'snake'],
            TagNameNamingInconsistent::class => ['tag_case', 'pascal'],
            HeaderNameNamingInconsistent::class => ['header_case', 'train'],
            ComponentNameNamingInconsistent::class => ['component_name_case', 'pascal'],
        ];

        foreach ($namingRules as $class => [$key, $default]) {
            $this->app->scoped($class, static fn () => new $class(
                IdentifierCase::from((string) config("openapi.lint.style.{$key}", $default)),
            ));
        }

        $this->app->scoped(
            SuppressionCollector::class,
            static function (Container $app): SuppressionCollector {
                $registry = $app->make(OpenApiRegistry::class);

                /** @var list<class-string> $indirectionClasses */
                $indirectionClasses = (array) config('openapi.request_payload_indirection', []);

                return new SuppressionCollector(
                    payloadClasses: $registry->payloadClasses(),
                    indirectionClasses: $indirectionClasses,
                );
            },
        );

        $this->app->scoped(
            JsonSchemaFromType::class,
            static fn (Container $app) => new JsonSchemaFromType(
                logger: $app->make(LoggerInterface::class),
            ),
        );

        $this->app->scoped(
            FindingsCollector::class,
            static fn (Container $app) => new EventDispatchingFindingsCollector(
                inner: new LoggingFindingsCollector(
                    logger: $app->make(LoggerInterface::class),
                ),
                events: $app->make(Dispatcher::class),
            ),
        );

        $this->app->scoped(LintRouteFilter::class, static fn (): LintRouteFilter => new LintRouteFilter());

        $this->app->scoped(
            LintRunner::class,
            static fn (Container $app) => new LintRunner(
                container: $app,
                introspector: $app->make(RouteIntrospector::class),
                registry: $app->make(RuleRegistry::class),
                suppressionCollector: $app->make(SuppressionCollector::class),
                openApiRegistry: $app->make(OpenApiRegistry::class),
                routeFilter: $app->make(LintRouteFilter::class),
                specRegistry: $app->make(SpecRegistry::class),
                orchestrator: $app->make(OpenApiGenerationOrchestrator::class),
                evaluator: $app->make(InclusionEvaluator::class),
                events: $app->make(Dispatcher::class),
            ),
        );
    }

    /**
     * Binds the schema registry and the operation-data extractors.
     */
    private function registerExtractors(): void
    {
        $this->app->scoped(
            ComponentSchemaRegistry::class,
            static fn () => new ComponentSchemaRegistry(),
        );

        $this->app->scoped(
            Extractors\UriParametersExtractor::class,
            static fn (Container $app) => new Extractors\UriParametersExtractor(
                schemaFromType: $app->make(JsonSchemaFromType::class),
            ),
        );

        $this->app->scoped(
            Extractors\SecurityExtractor::class,
            static fn (Container $app) => new Extractors\SecurityExtractor(
                router: $app->make(Router::class),
            ),
        );

        $this->app->scoped(
            Extractors\ValidationRulesToSchema::class,
            static fn (Container $app) => new Extractors\ValidationRulesToSchema(
                findings: $app->make(FindingsCollector::class),
            ),
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
                        static fn (string $class) => $app->make($class),
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
            PayloadParameterScanner::class,
            static function (): PayloadParameterScanner {
                /** @var list<class-string> $indirectionClasses */
                $indirectionClasses = (array) config('openapi.request_payload_indirection', []);

                return new PayloadParameterScanner(
                    indirectionClasses: $indirectionClasses,
                );
            },
        );

        $this->app->scoped(
            Extractors\SchemaFromFormRequest::class,
            static fn (Container $app) => new Extractors\SchemaFromFormRequest(
                rulesMapper: $app->make(Extractors\ValidationRulesToSchema::class),
                registry: $app->make(ComponentSchemaRegistry::class),
                logger: $app->make(LoggerInterface::class),
            ),
        );

        $this->app->scoped(
            Extractors\FormRequestRequestSchemaResolver::class,
            static fn (Container $app) => new Extractors\FormRequestRequestSchemaResolver(
                schemaBuilder: $app->make(Extractors\SchemaFromFormRequest::class),
                registry: $app->make(ComponentSchemaRegistry::class),
                scanner: $app->make(PayloadParameterScanner::class),
            ),
        );

        $this->app->scoped(
            Extractors\RequestBodyExtractor::class,
            static function (Container $app): Extractors\RequestBodyExtractor {
                $registry = $app->make(OpenApiRegistry::class);

                return new Extractors\RequestBodyExtractor(
                    resolvers: array_map(
                        static fn (string $class) => $app->make($class),
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
                    errorResponseFactories: array_map(
                        static fn (string $class) => $app->make($class),
                        $registry->errorResponseFactories(),
                    ),
                    exceptionMap: (array) config('openapi.exception_responses', []),
                    middlewareMap: (array) config('openapi.middleware_responses', []),
                );
            },
        );

        $this->app->scoped(ExampleFileLoader::class, static fn () => new ExampleFileLoader());
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
        if (! class_exists(Data::class)) {
            return;
        }

        $this->app->scoped(
            Plugins\SpatieData\DataSyntheticPayloadBuilder::class,
            static fn (Container $app) => new Plugins\SpatieData\DataSyntheticPayloadBuilder(
                dataConfig: $app->make(DataConfig::class),
            ),
        );

        $this->app->scoped(
            Plugins\SpatieData\SchemaFromDataClass::class,
            static fn (Container $app) => new Plugins\SpatieData\SchemaFromDataClass(
                schemaFromType: $app->make(JsonSchemaFromType::class),
                typeResolver: TypeResolver::create(),
                registry: $app->make(ComponentSchemaRegistry::class),
                payloadBuilder: $app->make(Plugins\SpatieData\DataSyntheticPayloadBuilder::class),
                rulesToSchema: $app->make(Extractors\ValidationRulesToSchema::class),
                dataConfig: $app->make(DataConfig::class),
                logger: $app->make(LoggerInterface::class),
            ),
        );

        $this->app->scoped(
            Plugins\SpatieData\FilePropertyChecker::class,
            static fn (Container $app) => $app->make(Plugins\SpatieData\SchemaFromDataClass::class),
        );

        $this->app->scoped(
            Plugins\SpatieData\DataClassRequestSchemaResolver::class,
            static fn (Container $app) => new Plugins\SpatieData\DataClassRequestSchemaResolver(
                schemaBuilder: $app->make(Plugins\SpatieData\SchemaFromDataClass::class),
                scanner: $app->make(PayloadParameterScanner::class),
            ),
        );

        $this->app->scoped(
            DataRefSchemaResolver::class,
            static fn (Container $app) => new DataRefSchemaResolver(
                schemaFromDataClass: $app->make(Plugins\SpatieData\SchemaFromDataClass::class),
                schemaRegistry: $app->make(ComponentSchemaRegistry::class),
            ),
        );

        $this->app->scoped(
            Plugins\SpatieData\DataResponseResolver::class,
            static fn (Container $app) => new Plugins\SpatieData\DataResponseResolver(
                refResolver: $app->make(DataRefSchemaResolver::class),
                returnTypeExtractor: $app->make(ReturnTypeExtractor::class),
                logger: $app->make(LoggerInterface::class),
            ),
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
                    /** @var null|list<Core\Registry\RefSchemaResolver> $cache */
                    static $cache = null;

                    if ($cache !== null) {
                        return $cache;
                    }

                    /** @var list<Core\Registry\RefSchemaResolver> $resolvers */
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

        $this->app->scoped(
            Plugins\ApiResources\ResourceRefSchemaResolver::class,
            static fn (Container $app) => new Plugins\ApiResources\ResourceRefSchemaResolver(
                schemaFromResource: $app->make(Plugins\ApiResources\SchemaFromResource::class),
            ),
        );

        $this->app->scoped(
            Plugins\ApiResources\ResourceResponseResolver::class,
            static fn (Container $app) => new Plugins\ApiResources\ResourceResponseResolver(
                locator: $app->make(Plugins\ApiResources\ResourceClassLocator::class),
                schemaFromResource: $app->make(Plugins\ApiResources\SchemaFromResource::class),
                envelopeFactory: $app->make(Plugins\ApiResources\ResourceEnvelopeFactory::class),
                logger: $app->make(LoggerInterface::class),
            ),
        );
    }

    /**
     * Binds the Fractal plugin services.
     *
     * `SchemaFromTransformer` receives a LAZY factory for the ref-resolver
     * list — every registered ref resolver except this plugin's own
     * `TransformerRefSchemaResolver`. Eager construction would form a
     * cross-plugin construction cycle with `SchemaFromResource` (each plugin's
     * builder lists the other plugin's resolver). Deferring resolution to
     * first use lets the container finish constructing both sides first; the
     * factory memoises its result with a closure-local static so repeated
     * resolveClassRef calls don't re-walk the registry.
     */
    private function registerFractalPlugin(): void
    {
        $this->app->scoped(
            Plugins\Fractal\SchemaFromTransformer::class,
            static function (Container $app): Plugins\Fractal\SchemaFromTransformer {
                $registry = $app->make(OpenApiRegistry::class);

                /**
                 * @throws BindingResolutionException
                 */
                $resolversFactory = static function () use ($app, $registry): array {
                    /** @var null|list<Core\Registry\RefSchemaResolver> $cache */
                    static $cache = null;

                    if ($cache !== null) {
                        return $cache;
                    }

                    /** @var list<Core\Registry\RefSchemaResolver> $resolvers */
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

        $this->app->scoped(
            Plugins\Fractal\FractalEnvelopeFactory::class,
            static fn (): Plugins\Fractal\FractalEnvelopeFactory => new Plugins\Fractal\FractalEnvelopeFactory(),
        );

        $this->app->scoped(
            Plugins\Fractal\TransformerRefSchemaResolver::class,
            static fn (Container $app) => new Plugins\Fractal\TransformerRefSchemaResolver(
                schemaFromTransformer: $app->make(Plugins\Fractal\SchemaFromTransformer::class),
            ),
        );

        $this->app->scoped(
            Plugins\Fractal\FractalResponseResolver::class,
            static fn (Container $app) => new Plugins\Fractal\FractalResponseResolver(
                schemaFromTransformer: $app->make(Plugins\Fractal\SchemaFromTransformer::class),
                envelopeFactory: $app->make(Plugins\Fractal\FractalEnvelopeFactory::class),
                logger: $app->make(LoggerInterface::class),
            ),
        );
    }

    /**
     * Binds the query-parameter resolver shipped by the QueryBuilder plugin.
     *
     * The resolver currently has no constructor dependencies and would auto-resolve, but the
     * explicit binding keeps the four shipped plugins symmetric — the moment a dependency
     * lands, container resolution still works without a code change here.
     */
    private function registerQueryBuilderPlugin(): void
    {
        $this->app->scoped(
            Plugins\QueryBuilder\QueryBuilderParameterResolver::class,
            static fn (): Plugins\QueryBuilder\QueryBuilderParameterResolver => new Plugins\QueryBuilder\QueryBuilderParameterResolver(),
        );
    }

    /**
     * Binds the operation builder and the top-level generator that drives the pipeline.
     */
    private function registerGenerator(): void
    {
        $this->app->scoped(VisibilityResolver::class, static fn (): VisibilityResolver => new VisibilityResolver(
            VisibilityMode::fromConfig(config('openapi.visibility.default')),
        ));

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
                        static fn (string $class) => $app->make($class),
                        $registry->refSchemaResolvers(),
                    ),
                    queryParameterResolvers: array_map(
                        static fn (string $class) => $app->make($class),
                        $registry->queryParameterResolvers(),
                    ),
                    primaryResponseResolvers: array_map(
                        static fn (string $class) => $app->make($class),
                        $registry->primaryResponseResolvers(),
                    ),
                );
            },
        );

        $this->app->scoped(
            OpenApiGenerator::class,
            static fn (Container $app) => new OpenApiGenerator(
                introspector: $app->make(RouteIntrospector::class),
                operationBuilder: $app->make(OperationBuilder::class),
                schemaRegistry: $app->make(ComponentSchemaRegistry::class),
                evaluator: $app->make(InclusionEvaluator::class),
                events: $app->make(Dispatcher::class),
            ),
        );

        $this->app->scoped(
            OpenApiGenerationOrchestrator::class,
            static fn (Container $app) => new OpenApiGenerationOrchestrator(
                container: $app,
                registry: $app->make(SpecRegistry::class),
            ),
        );
    }

    /**
     * Binds the spec-related services: SpecMatcher, SpecResolver, SpecRegistry, InclusionEvaluator.
     */
    private function registerSpec(): void
    {
        $this->app->scoped(
            SpecMatcher::class,
            static fn () => new SpecMatcher(),
        );

        $this->app->scoped(
            SpecResolver::class,
            static fn () => new SpecResolver(),
        );

        $this->app->scoped(
            SpecRegistry::class,
            static function (): SpecRegistry {
                $rootInfo = (array) config('openapi.info', []);
                $rootInfo['title'] ??= (string) config('app.name', 'API');
                $rootInfo['version'] ??= '0.0.0';

                return new SpecRegistry(
                    rootInfo: $rootInfo,
                    rootServers: array_values((array) config('openapi.servers', [])),
                    rootTags: (array) config('openapi.tags', []),
                    rootOutputPath: (string) config('openapi.output_path'),
                    rootRouteUri: (string) (config('openapi.routes.spec.uri') ?? 'openapi.yaml'),
                    rootPlaygroundUri: (string) (config('openapi.routes.playground.uri') ?? 'docs'),
                    specs: is_array(config('openapi.specs')) ? config('openapi.specs') : null,
                    storagePath: storage_path(),
                );
            },
        );

        $this->app->scoped(
            InclusionEvaluator::class,
            static function (Container $app): InclusionEvaluator {
                $filterClasses = (array) config('openapi.filters', []);
                $filters = array_values(array_map(
                    static function (mixed $entry) use ($app): RouteFilter {
                        return is_string($entry) ? $app->make($entry) : $entry;
                    },
                    $filterClasses,
                ));

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
     * Conditionally mounts one spec + playground route per defined spec.
     *
     * Reads route URIs directly from config rather than resolving {@see SpecRegistry}. The
     * registry would force eager materialisation of `info`/`servers`/`tags` at boot, which
     * is too early — later-booting providers (e.g. example flavors) are still entitled to
     * override those keys before generation runs. Only the URI fields are needed here, and
     * URI resolution is replicated inline to keep `default`'s name correct even when an
     * explicit `'specs.default'` entry exists.
     *
     * Route names follow the convention:
     *   - default spec: `openapi.spec` / `openapi.playground`
     *   - named specs:  `openapi.spec.{name}` / `openapi.playground.{name}`
     */
    private function registerRoutes(): void
    {
        $config = (array) config('openapi.routes', []);

        if (($config['enabled'] ?? false) !== true) {
            return;
        }

        /** @var array<string, array<string, mixed>> $specs */
        $specs = is_array(config('openapi.specs')) ? config('openapi.specs') : [];

        $rootRouteUri = is_string($config['spec']['uri'] ?? null) ? $config['spec']['uri'] : 'openapi.yaml';
        $rootPlaygroundUri = is_string($config['playground']['uri'] ?? null) ? $config['playground']['uri'] : 'docs';

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
