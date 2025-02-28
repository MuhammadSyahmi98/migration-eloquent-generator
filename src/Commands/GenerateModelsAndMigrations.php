<?php

namespace SyahmiJalil\DatabaseToModel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;


class GenerateModelsAndMigrations extends Command
{
    protected $signature = 'generate:models-migrations
                          {--tables= : Specific tables to generate, comma-separated}
                          {--ignore= : Tables to ignore, comma-separated}
                          {--connection= : Database connection to use}
                          {--path-model= : Path where models should be created}
                          {--path-migration= : Path where migrations should be created}';

    protected $description = 'Generate models and migrations from existing database tables';

    protected $connection;
    protected $ignoredTables = [];

    public function handle()
    {
        // Load default ignored tables from config
        $this->ignoredTables = config('database-to-model.ignored_tables', [
            'migrations', 'failed_jobs', 'password_resets', 'audits', 
            'sessions', 'sp_password_resets', 'email_logs'
        ]);

        $this->connection = $this->option('connection') ?: config('database.default');

        if ($this->option('ignore')) {
            $this->ignoredTables = array_merge(
                $this->ignoredTables,
                explode(',', $this->option('ignore'))
            );
        }

        $tables = $this->getTables();

        // Get paths from options or config
        $modelPath = $this->option('path-model') ?: config('database-to-model.paths.model', 'app/Models');
        $migrationPath = $this->option('path-migration') ?: config('database-to-model.paths.migration', 'database/migrations');

        // Pre-create directories to avoid checking in each iteration
        $modelPath = base_path($modelPath);
        $migrationPath = base_path($migrationPath);

        if (!file_exists($modelPath)) {
            mkdir($modelPath, 0755, true);
        }

        if (!file_exists($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }

        // Sort tables by dependencies first
        $sortedTables = $this->sortTablesByDependencies($tables);

        $this->info("Tables will be processed in the following order:");
        foreach ($sortedTables as $index => $table) {
            $this->info(($index + 1) . ". {$table}");
        }

        // First, create all table migrations without foreign keys
        foreach ($sortedTables as $table) {
            $this->info("Generating model and base migration for table: {$table}");
            $this->generateModel($table, $modelPath);
            $this->generateBaseMigration($table, $migrationPath);
        }

        // Then create a separate migration for all foreign keys
        $this->generateForeignKeyMigration($sortedTables, $migrationPath);

        $this->info('Generation completed!');
    }

    // ... rest of your existing methods, updated to use config values where appropriate ...
    
    protected function generateModel($table, $modelPath)
    {
        $modelName = Str::studly(Str::singular($table));

        // Get all column data in a single query instead of multiple queries
        $columns = Schema::connection($this->connection)->getColumnListing($table);
        $primaryKey = $this->getPrimaryKey($table);
        $relationships = $this->getRelationships($table);
        $fillable = array_diff($columns, ['id', 'created_at', 'updated_at']);

        // Check if custom stub exists
        $stubPath = $this->getStubPath('model.stub');
        $modelStub = file_get_contents($stubPath);

        // Replace placeholders
        $modelContent = str_replace('{{modelName}}', $modelName, $modelStub);
        $modelContent = str_replace('{{table}}', $table, $modelContent);
        
        // Handle primary key
        $primaryKeyContent = $primaryKey !== 'id' ? "\n    protected \$primaryKey = '{$primaryKey}';" : '';
        $modelContent = str_replace('{{primaryKey}}', $primaryKeyContent, $modelContent);
        
        // Handle fillable
        $fillableContent = "    protected \$fillable = [\n";
        foreach ($fillable as $column) {
            $fillableContent .= "        '{$column}',\n";
        }
        $fillableContent .= "    ];";
        $modelContent = str_replace('{{fillable}}', $fillableContent, $modelContent);
        
        // Handle relationships
        $modelContent = str_replace('{{relationships}}', $relationships, $modelContent);

        file_put_contents("{$modelPath}/{$modelName}.php", $modelContent);

        $this->info("Model {$modelName} created successfully!");
    }

    protected function getStubPath($stub)
    {
        // Check if the stub exists in the published stubs directory
        $customStubPath = base_path("stubs/vendor/database-to-model/{$stub}");
        
        if (file_exists($customStubPath)) {
            return $customStubPath;
        }
        
        // Fall back to the package stubs
        return __DIR__ . "/../../stubs/{$stub}";
    }
    
    // ... include all your other methods from the original command ...
} 