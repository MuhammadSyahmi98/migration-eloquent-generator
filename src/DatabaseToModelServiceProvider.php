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
        
        // Check for duplicate command file
        $duplicateFile = app_path('Console/Commands/GenerateModelsAndMigrations.php');
        if (file_exists($duplicateFile)) {
            @unlink($duplicateFile);
        }
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
        }
    }
}