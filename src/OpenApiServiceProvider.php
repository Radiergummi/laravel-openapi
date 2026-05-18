<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi;

use Radiergummi\OpenApi\Core\Extractors;
use Radiergummi\OpenApi\Core\Extractors\PayloadParameterScanner;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Generator\ExampleFileLoader;
use Radiergummi\OpenApi\Core\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Core\Generator\OperationBuilder;
use Radiergummi\OpenApi\Core\Lint\FindingsCollector;
use Radiergummi\OpenApi\Core\Lint\IdentifierCase;
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
use Radiergummi\OpenApi\Console\ClearCommand;
use Radiergummi\OpenApi\Console\GenerateCommand;
use Radiergummi\OpenApi\Console\LintCommand;
use Radiergummi\OpenApi\Core\Registry\CoreRegistration;
use Radiergummi\OpenApi\Core\Registry\OpenApiRegistry;
use Radiergummi\OpenApi\Core\Registry\Plugin;
use Radiergummi\OpenApi\Core\Routing\DocCommentParser;
use Radiergummi\OpenApi\Core\Routing\Filters\RouteFilter;
use Radiergummi\OpenApi\Core\Routing\Filters\SkipIgnitionRoutes;
use Radiergummi\OpenApi\Core\Routing\Filters\SkipNovaRoutes;
use Radiergummi\OpenApi\Core\Routing\Filters\SkipTelescopeRoutes;
use Radiergummi\OpenApi\Core\Routing\RouteIntrospector;
use Radiergummi\OpenApi\Core\Routing\ThrowsExtractor;
use Radiergummi\OpenApi\Core\Routing\UriParameterResolver;
use Radiergummi\OpenApi\Http\DocsController;
use Radiergummi\OpenApi\Plugins;
use Radiergummi\OpenApi\Plugins\SpatieData\DataRefSchemaResolver;
use Illuminate\Container\Container;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Spatie\LaravelData\Support\DataConfig;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

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
 * bindings between requests, giving each generation run its own fresh instances without any
 * explicit `reset()` call. The `reset()` methods on the stateful classes are preserved for
 * backward-compat but are now redundant under the scoped lifecycle.
 *
 * The routing bindings ({@see ThrowsExtractor}, {@see RouteIntrospector},
 * {@see UriParameterResolver}) are registered here too, since {@see RouteIntrospector} and
 * {@see UriParameterResolver} are resolved from the container by the pipeline.
 */
class OpenApiServiceProvider extends ServiceProvider
{
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
            ]);
        }

        $this->registerRoutes();
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/openapi.php', 'openapi');

        $this->app->scoped(
            ThrowsExtractor::class,
            static fn() => ThrowsExtractor::create(),
        );

        $this->app->scoped(SkipNovaRoutes::class, static fn(): SkipNovaRoutes => SkipNovaRoutes::fromConfig());
        $this->app->scoped(SkipTelescopeRoutes::class, static fn(): SkipTelescopeRoutes => SkipTelescopeRoutes::fromConfig());
        $this->app->scoped(SkipIgnitionRoutes::class, static fn(): SkipIgnitionRoutes => SkipIgnitionRoutes::fromConfig());

        $this->app->scoped(
            RouteIntrospector::class,
            static function (Container $app): RouteIntrospector {
                $filters = array_map(
                    static function (mixed $filter) use ($app): RouteFilter {
                        $instance = is_string($filter) ? $app->make($filter) : $filter;
                        assert($instance instanceof RouteFilter);

                        return $instance;
                    },
                    (array) config('openapi.filters', []),
                );

                return new RouteIntrospector(
                    router: $app->make(Router::class),
                    container: $app,
                    parser: new DocCommentParser(),
                    throwsExtractor: $app->make(ThrowsExtractor::class),
                    filters: array_values($filters),
                );
            },
        );

        $this->app->scoped(
            UriParameterResolver::class,
            static fn() => new UriParameterResolver(TypeResolver::create()),
        );

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
                        static fn(string $class) => $app->make($class),
                        $registry->rules(),
                    ),
                    severityOverrides: (array) config('openapi.lint.severity_overrides', []),
                );
            },
        );

        $namingRules = [
            OperationIdNamingInconsistent::class   => ['operation_id_case', 'dot'],
            FieldNameNamingInconsistent::class     => ['property_name_case', 'camel'],
            PathSegmentNamingInconsistent::class   => ['path_segment_case', 'kebab'],
            ParameterNameNamingInconsistent::class => ['parameter_name_case', 'snake'],
            TagNameNamingInconsistent::class       => ['tag_case', 'pascal'],
            HeaderNameNamingInconsistent::class    => ['header_case', 'train'],
            ComponentNameNamingInconsistent::class => ['component_name_case', 'pascal'],
        ];

        foreach ($namingRules as $class => [$key, $default]) {
            $this->app->scoped($class, static fn() => new $class(
                IdentifierCase::from((string) config("openapi.lint.style.{$key}", $default)),
            ));
        }

        $this->app->scoped(
            SuppressionCollector::class,
            static function (Container $app): SuppressionCollector {
                $registry = $app->make(OpenApiRegistry::class);

                return new SuppressionCollector(
                    payloadClasses: $registry->payloadClasses(),
                    indirectionClasses: (array) config('openapi.request_payload_indirection', []),
                );
            },
        );

        $this->app->scoped(
            JsonSchemaFromType::class,
            static fn(Container $app) => new JsonSchemaFromType(
                logger: $app->make(LoggerInterface::class),
            ),
        );

        $this->app->scoped(
            FindingsCollector::class,
            static fn(Container $app) => new LoggingFindingsCollector(
                logger: $app->make(LoggerInterface::class),
            ),
        );

        $this->app->scoped(
            ComponentSchemaRegistry::class,
            static fn() => new ComponentSchemaRegistry(),
        );

        $this->app->scoped(
            Extractors\UriParametersExtractor::class,
            static fn(Container $app) => new Extractors\UriParametersExtractor(
                schemaFromType: $app->make(JsonSchemaFromType::class),
            ),
        );

        $this->app->scoped(
            Extractors\SecurityExtractor::class,
            static fn(Container $app) => new Extractors\SecurityExtractor(
                router: $app->make(Router::class),
            ),
        );

        $this->app->scoped(
            Extractors\ValidationRulesToSchema::class,
            static fn(Container $app) => new Extractors\ValidationRulesToSchema(
                findings: $app->make(FindingsCollector::class),
            ),
        );

        $this->app->scoped(
            Plugins\SpatieData\DataSyntheticPayloadBuilder::class,
            static fn(Container $app) => new Plugins\SpatieData\DataSyntheticPayloadBuilder(
                dataConfig: $app->make(DataConfig::class),
            ),
        );

        $this->app->scoped(
            Plugins\SpatieData\SchemaFromDataClass::class,
            static fn(Container $app) => new Plugins\SpatieData\SchemaFromDataClass(
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
            static fn(Container $app) => $app->make(Plugins\SpatieData\SchemaFromDataClass::class),
        );

        $this->app->scoped(
            PayloadParameterScanner::class,
            static fn(): PayloadParameterScanner => new PayloadParameterScanner(
                indirectionClasses: (array) config('openapi.request_payload_indirection', []),
            ),
        );

        $this->app->scoped(
            Extractors\SchemaFromFormRequest::class,
            static fn(Container $app) => new Extractors\SchemaFromFormRequest(
                rulesMapper: $app->make(Extractors\ValidationRulesToSchema::class),
                registry: $app->make(ComponentSchemaRegistry::class),
            ),
        );

        $this->app->scoped(
            Extractors\FormRequestRequestSchemaResolver::class,
            static fn(Container $app) => new Extractors\FormRequestRequestSchemaResolver(
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
                        static fn(string $class) => $app->make($class),
                        $registry->requestSchemaResolvers(),
                    ),
                    findings: $app->make(FindingsCollector::class),
                );
            },
        );

        $this->app->scoped(
            Plugins\SpatieData\DataClassRequestSchemaResolver::class,
            static fn(Container $app) => new Plugins\SpatieData\DataClassRequestSchemaResolver(
                schemaBuilder: $app->make(Plugins\SpatieData\SchemaFromDataClass::class),
                scanner: $app->make(PayloadParameterScanner::class),
            ),
        );

        $this->app->scoped(
            Extractors\StandardResponsesExtractor::class,
            static function (Container $app): Extractors\StandardResponsesExtractor {
                $registry = $app->make(OpenApiRegistry::class);

                return new Extractors\StandardResponsesExtractor(
                    registry: $app->make(ComponentSchemaRegistry::class),
                    findings: $app->make(FindingsCollector::class),
                    errorResponseFactories: array_map(
                        static fn(string $class) => $app->make($class),
                        $registry->errorResponseFactories(),
                    ),
                    exceptionMap: (array) config('openapi.exception_responses', []),
                    middlewareMap: (array) config('openapi.middleware_responses', []),
                );
            },
        );

        $this->app->scoped(ExampleFileLoader::class, static fn() => new ExampleFileLoader());

        $this->app->scoped(
            DataRefSchemaResolver::class,
            static fn(Container $app) => new DataRefSchemaResolver(
                schemaFromDataClass: $app->make(Plugins\SpatieData\SchemaFromDataClass::class),
                schemaRegistry: $app->make(ComponentSchemaRegistry::class),
            ),
        );

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

        $this->app->scoped(
            OpenApiGenerator::class,
            static fn(Container $app) => new OpenApiGenerator(
                introspector: $app->make(RouteIntrospector::class),
                operationBuilder: $app->make(OperationBuilder::class),
                schemaRegistry: $app->make(ComponentSchemaRegistry::class),
            ),
        );
    }

    /**
     * Conditionally mounts the spec and playground routes from configuration.
     */
    private function registerRoutes(): void
    {
        $config = (array) config('openapi.routes', []);

        if (($config['enabled'] ?? false) !== true) {
            return;
        }

        Route::group([
            'prefix' => $config['prefix'] ?? 'api',
            'middleware' => $config['middleware'] ?? ['web'],
        ], static function () use ($config): void {
            if (($config['spec']['enabled'] ?? false) === true) {
                Route::get(
                    $config['spec']['uri'] ?? 'openapi.yaml',
                    [DocsController::class, 'spec'],
                )->name('openapi.spec');
            }

            if (($config['playground']['enabled'] ?? false) === true) {
                Route::get(
                    $config['playground']['uri'] ?? 'docs',
                    [DocsController::class, 'playground'],
                )->name('openapi.playground');
            }
        });
    }
}
