<?php

namespace SyahmiJalil\DatabaseToModel;

use Illuminate\Support\ServiceProvider;
use SyahmiJalil\DatabaseToModel\Commands\GenerateModelsAndMigrations;

class DatabaseToModelServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge config
        $this->mergeConfigFrom(
            __DIR__.'/../config/database-to-model.php', 'database-to-model'
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateModelsAndMigrations::class,
            ]);

            // Publish config
            $this->publishes([
                __DIR__.'/../config/database-to-model.php' => config_path('database-to-model.php'),
            ], 'database-to-model-config');

            // Publish stubs
            $this->publishes([
                __DIR__.'/../stubs' => base_path('stubs/vendor/database-to-model'),
            ], 'database-to-model-stubs');
        }
    }
}