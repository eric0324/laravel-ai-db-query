<?php

namespace Eric0324\AIDBQuery;

use Eric0324\AIDBQuery\Commands\AskCommand;
use Eric0324\AIDBQuery\Commands\IndexSchemaCommand;
use Eric0324\AIDBQuery\LLM\EmbeddingService;
use Eric0324\AIDBQuery\LLM\LLMManager;
use Eric0324\AIDBQuery\Schema\SchemaIndexer;
use Eric0324\AIDBQuery\Schema\SchemaManager;
use Eric0324\AIDBQuery\Security\QueryGuard;
use Eric0324\AIDBQuery\Security\QueryLogger;
use Illuminate\Support\ServiceProvider;

class SmartQueryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge config
        $this->mergeConfigFrom(__DIR__ . '/../config/smart-query.php', 'smart-query');

        // Register LLM Manager
        $this->app->singleton(LLMManager::class, function ($app) {
            return new LLMManager($app);
        });

        // Register Embedding Service (optional - only if API key is configured)
        $this->app->singleton(EmbeddingService::class, function ($app) {
            $apiKey = $app['config']->get('smart-query.llm.drivers.openai.api_key');

            if (empty($apiKey)) {
                return null;
            }

            return new EmbeddingService([
                'api_key' => $apiKey,
                'model' => $app['config']->get('smart-query.schema.embedding_model', 'text-embedding-3-small'),
            ]);
        });

        // Register Schema Manager first (needed by SchemaIndexer)
        $this->app->singleton(SchemaManager::class, function ($app) {
            return new SchemaManager(
                $app['config']->get('smart-query.schema', [])
            );
        });

        // Register Schema Indexer
        $this->app->singleton(SchemaIndexer::class, function ($app) {
            $indexer = new SchemaIndexer(
                $app['config']->get('smart-query.schema', [])
            );

            // Set embedding service if available
            $embeddingService = $app->make(EmbeddingService::class);
            if ($embeddingService) {
                $indexer->setEmbeddingService($embeddingService);
            }

            // Set schema manager
            $indexer->setSchemaManager($app->make(SchemaManager::class));

            return $indexer;
        });

        // After SchemaIndexer is registered, set it on SchemaManager
        $this->app->afterResolving(SchemaManager::class, function ($manager, $app) {
            $manager->setIndexer($app->make(SchemaIndexer::class));
        });

        // Register Query Guard
        $this->app->singleton(QueryGuard::class, function ($app) {
            return new QueryGuard(
                $app['config']->get('smart-query.security', [])
            );
        });

        // Register Query Logger
        $this->app->singleton(QueryLogger::class, function ($app) {
            return new QueryLogger(
                $app['config']->get('smart-query.security.logging', true)
            );
        });

        // Register SmartQuery
        $this->app->singleton('smart-query', function ($app) {
            return new SmartQuery(
                $app->make(LLMManager::class),
                $app->make(SchemaManager::class),
                $app->make(QueryGuard::class),
                $app->make(QueryLogger::class),
                $app['config']->get('smart-query', [])
            );
        });

        $this->app->alias('smart-query', SmartQuery::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish config
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/smart-query.php' => config_path('smart-query.php'),
            ], 'smart-query-config');

            // Register commands
            $this->commands([
                AskCommand::class,
                IndexSchemaCommand::class,
            ]);
        }
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            'smart-query',
            SmartQuery::class,
            LLMManager::class,
            EmbeddingService::class,
            SchemaManager::class,
            SchemaIndexer::class,
            QueryGuard::class,
            QueryLogger::class,
        ];
    }
}
