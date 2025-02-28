<?php

namespace SyahmiJalil\DatabaseToModel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Database\Schema\Blueprint;

class GenerateModelsAndMigrations extends Command
{
    protected $signature = 'generate:models-migrations
                          {--tables= : Specific tables to generate, comma-separated}
                          {--connection= : Database connection to use}';

    protected $description = 'Generate models and migrations from existing database tables';

    protected $connection;
    protected $ignoredTables;

    public function __construct()
    {
        parent::__construct();

        $this->ignoredTables = config('database-to-model.ignored_tables', ['migrations', 'failed_jobs', 'password_resets', 'audits', 'sessions', 'sp_password_resets', 'email_logs']);
    }

    public function handle()
    {
        $this->connection = $this->option('connection') ?: config('database.default');

        $tables = $this->getTables();

        // Pre-create directories to avoid checking in each iteration
        $modelPath = base_path(config('database-to-model.paths.model') ?? 'app/Models');
        $migrationPath = base_path(config('database-to-model.paths.migration') ?? 'database/migrations');

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
            $this->generateModel($table);
            $this->generateBaseMigration($table);
        }

        // Then create a separate migration for all foreign keys
        $this->generateForeignKeyMigration($sortedTables);

        $this->info('Generation completed!');
    }

    protected function getTables()
    {
        if ($this->option('tables')) {
            return explode(',', $this->option('tables'));
        }

        $connection = DB::connection($this->connection);
        $tables = [];

        // Cache the database name to avoid repeated calls
        $databaseName = $connection->getDatabaseName();

        switch ($connection->getDriverName()) {
            case 'mysql':
                $tables = array_column($connection->select('SHOW TABLES'), 'Tables_in_' . $databaseName);
                break;

            case 'pgsql':
                $tables = array_column($connection->select("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public'"), 'tablename');
                break;

            case 'sqlite':
                $tables = array_column($connection->select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"), 'name');
                break;

            case 'sqlsrv':
                $tables = array_column($connection->select("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE'"), 'TABLE_NAME');
                break;

            default:
                throw new \Exception("Database driver not supported for table listing");
        }

        return array_diff($tables, $this->ignoredTables);
    }

    protected function generateModel($table)
    {
        $modelName = Str::studly(Str::singular($table));

        // Get all column data in a single query instead of multiple queries
        $columns = Schema::connection($this->connection)->getColumnListing($table);
        $primaryKey = $this->getPrimaryKey($table);
        $relationships = $this->getRelationships($table);
        $fillable = array_diff($columns, ['id', 'created_at', 'updated_at']);

        $modelPath = base_path(config('database-to-model.paths.model', 'app/Models'));
        $modelContent = $this->getModelContent($modelName, $table, $primaryKey, $fillable, $relationships);
        file_put_contents("{$modelPath}/{$modelName}.php", $modelContent);

        $this->info("Model {$modelName} created successfully!");
    }

    protected function getModelContent($modelName, $table, $primaryKey, $fillable, $relationships)
    {
        $columns = Schema::connection($this->connection)->getColumnListing($table);
        $hasSoftDeletes = in_array('deleted_at', $columns);

        $content = "<?php\n\nnamespace App\\Models;\n\n";

        // Add SoftDeletes import if needed
        if ($hasSoftDeletes) {
            $content .= "use Illuminate\\Database\\Eloquent\\SoftDeletes;\n";
        }

        $content .= "use Illuminate\\Database\\Eloquent\\Model;\n\nclass {$modelName} extends Model\n{\n";

        // Add SoftDeletes trait if needed
        if ($hasSoftDeletes) {
            $content .= "    use SoftDeletes;\n\n";
        }

        // Table name
        $content .= "    protected \$table = '{$table}';\n";

        // Primary key (if not 'id')
        if ($primaryKey !== 'id') {
            $content .= "    protected \$primaryKey = '{$primaryKey}';\n";
        }

        // Fillable
        $content .= "\n    protected \$fillable = [\n";
        foreach ($fillable as $column) {
            $content .= "        '{$column}',\n";
        }
        $content .= "    ];\n";

        // Relationships
        if (!empty($relationships)) {
            $content .= "\n{$relationships}\n";
        }

        $content .= "}\n";

        return $content;
    }

    protected function generateBaseMigration($table)
    {
        $className = 'Create' . Str::studly($table) . 'Table';
        $columns = Schema::connection($this->connection)->getColumnListing($table);

        $migrationPath = base_path(config('database-to-model.paths.migration', 'database/migrations'));
        if (!file_exists($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }

        // Check if we need to split the migration due to large row size
        $columnCount = count($columns);
        $maxColumnsPerMigration = config('database-to-model.max_columns_per_migration', 15); // Adjust this value based on your database configuration

        if ($columnCount > $maxColumnsPerMigration) {
            $this->generateSplitMigrations($table, $columns, $maxColumnsPerMigration);
        } else {
            $timestamp = date('Y_m_d_His');
            $filename = "{$timestamp}_create_{$table}_table.php";
            $migrationContent = $this->getBaseMigrationContent($className, $table, $columns);

            file_put_contents("{$migrationPath}/{$filename}", $migrationContent);
            $this->info("Base migration for {$table} created successfully!");
        }

        // Sleep for 1 second to ensure unique timestamps
        sleep(1);
    }

    protected function generateSplitMigrations($table, $columns, $maxColumnsPerMigration)
    {
        $migrationPath = base_path(config('database-to-model.paths.migration') ?? 'database/migrations');
        $chunks = array_chunk($columns, $maxColumnsPerMigration);

        // First migration creates the table with essential columns
        $timestamp = date('Y_m_d_His');
        $className = 'Create' . Str::studly($table) . 'Table';
        $filename = "{$timestamp}_create_{$table}_table.php";

        // Identify essential columns that should be in the first migration
        $essentialColumns = [];
        $primaryKey = $this->getPrimaryKey($table);
        $hasTimestamps = in_array('created_at', $columns) && in_array('updated_at', $columns);
        $hasSoftDeletes = in_array('deleted_at', $columns);

        // Always include primary key in the first migration
        if (in_array($primaryKey, $columns)) {
            $essentialColumns[] = $primaryKey;
        }

        // Include timestamp columns if they exist
        if ($hasTimestamps) {
            $essentialColumns[] = 'created_at';
            $essentialColumns[] = 'updated_at';
        }

        // Include soft delete column if it exists
        if ($hasSoftDeletes) {
            $essentialColumns[] = 'deleted_at';
        }

        // Create first chunk with essential columns
        $firstChunk = array_unique(array_merge($essentialColumns, $chunks[0]));

        // Remove essential columns from other chunks to avoid duplication
        $remainingColumns = array_diff($columns, $firstChunk);
        $remainingChunks = array_chunk($remainingColumns, $maxColumnsPerMigration);

        // Generate first migration with table creation
        $migrationContent = $this->getBaseMigrationContent($className, $table, $firstChunk);
        file_put_contents("{$migrationPath}/{$filename}", $migrationContent);
        $this->info("Initial migration for {$table} created successfully!");

        sleep(1);

        // Generate additional migrations to add remaining columns
        foreach ($remainingChunks as $index => $chunk) {
            if (empty($chunk)) continue;

            $timestamp = date('Y_m_d_His');
            $chunkClassName = 'Add' . Str::studly("Columns_Part_" . ($index + 1)) . 'To' . Str::studly($table) . 'Table';
            $chunkFilename = "{$timestamp}_add_columns_part_" . ($index + 1) . "_to_{$table}_table.php";

            $migrationContent = $this->getAddColumnsMigrationContent($chunkClassName, $table, $chunk);
            file_put_contents("{$migrationPath}/{$chunkFilename}", $migrationContent);
            $this->info("Additional columns migration part " . ($index + 1) . " for {$table} created successfully!");

            sleep(1);
        }
    }

    protected function getAddColumnsMigrationContent($className, $table, $columns)
    {
        $content = "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nclass {$className} extends Migration\n{\n";

        // Up method
        $content .= "    public function up()\n    {\n";
        $content .= "        Schema::table('{$table}', function (Blueprint \$table) {\n";

        foreach ($columns as $column) {
            $columnDefinition = $this->getColumnDefinition($table, $column, false);
            $content .= "            {$columnDefinition}\n";
        }

        $content .= "        });\n";
        $content .= "    }\n\n";

        // Down method
        $content .= "    public function down()\n    {\n";
        $content .= "        Schema::table('{$table}', function (Blueprint \$table) {\n";

        foreach ($columns as $column) {
            $content .= "            \$table->dropColumn('{$column}');\n";
        }

        $content .= "        });\n";
        $content .= "    }\n";

        $content .= "}\n";

        return $content;
    }

    protected function getBaseMigrationContent($className, $table, $columns)
    {
        $content = "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nclass {$className} extends Migration\n{\n";

        // Up method
        $content .= "    public function up()\n    {\n";
        $content .= "        Schema::create('{$table}', function (Blueprint \$table) {\n";

        // Check if the table has both created_at and updated_at columns
        $hasCreatedAt = in_array('created_at', $columns);
        $hasUpdatedAt = in_array('updated_at', $columns);
        $hasTimestamps = $hasCreatedAt && $hasUpdatedAt;
        $hasSoftDeletes = in_array('deleted_at', $columns);

        // Filter out timestamp columns if we're going to use $table->timestamps()
        $columnsToProcess = $columns;
        if ($hasTimestamps) {
            $columnsToProcess = array_filter($columnsToProcess, function($col) {
                return $col !== 'created_at' && $col !== 'updated_at';
            });
        }

        // Filter out deleted_at if we're going to use $table->softDeletes()
        if ($hasSoftDeletes) {
            $columnsToProcess = array_filter($columnsToProcess, function($col) {
                return $col !== 'deleted_at';
            });
        }

        foreach ($columnsToProcess as $column) {
            // Skip foreign key columns for now, just create the basic column
            $columnDefinition = $this->getColumnDefinition($table, $column, false);
            $content .= "            {$columnDefinition}\n";
        }

        // Add timestamps if both created_at and updated_at exist
        if ($hasTimestamps) {
            $content .= "            \$table->timestamps();\n";
        }

        // Add softDeletes if deleted_at exists
        if ($hasSoftDeletes) {
            $content .= "            \$table->softDeletes();\n";
        }

        $content .= "        });\n";
        $content .= "    }\n\n";

        // Down method
        $content .= "    public function down()\n    {\n";
        $content .= "        Schema::dropIfExists('{$table}');\n";
        $content .= "    }\n";

        $content .= "}\n";

        return $content;
    }

    protected function getColumnDefinition($table, $column, $includeForeignKeys = true)
    {
        $connection = DB::connection($this->connection);
        $columnType = $this->getColumnType($table, $column);

        // Handle SoftDeletes
        if ($column === 'deleted_at') {
            return "\$table->softDeletes();";
        }

        $definition = "\$table->{$columnType}('{$column}')";

        // Check if nullable
        if ($this->isNullable($table, $column)) {
            $definition .= "->nullable()";
        }

        // Check if has default value
        $default = $this->getDefaultValue($table, $column);
        if ($default !== null) {
            // Handle different types of default values
            if (is_numeric($default) || $default === '0' || $default === '1') {
                // Numeric default or '0'/'1' which are often used as boolean flags
                $definition .= "->default('{$default}')";
            } else if (strtolower($default) === 'null') {
                // NULL default
                $definition .= "->default(null)";
            } else if (strtolower($default) === 'true' || strtolower($default) === 'false') {
                // Boolean default
                $definition .= "->default(" . strtolower($default) . ")";
            } else if (strtolower($default) === 'current_timestamp' || strtolower($default) === 'current_timestamp()') {
                // Timestamp default
                $definition .= "->useCurrent()";
            } else {
                // String default - properly escape single quotes
                $escapedDefault = str_replace("'", "\\'", $default);
                $definition .= "->default('{$escapedDefault}')";
            }
        }

        // Check if primary key and auto increment
        if ($this->isPrimaryKey($table, $column)) {
            if ($this->isAutoIncrement($table, $column)) {
                // For auto-incrementing primary keys, we should use methods like
                // increments(), bigIncrements(), etc. instead of ->primary()
                if (strpos($definition, "integer('{$column}')") !== false) {
                    // Replace the integer definition with increments
                    $definition = str_replace("integer('{$column}')", "increments('{$column}')", $definition);
                } else if (strpos($definition, "bigInteger('{$column}')") !== false) {
                    // Replace the bigInteger definition with bigIncrements
                    $definition = str_replace("bigInteger('{$column}')", "bigIncrements('{$column}')", $definition);
                } else if (strpos($definition, "smallInteger('{$column}')") !== false) {
                    // Replace the smallInteger definition with smallIncrements
                    $definition = str_replace("smallInteger('{$column}')", "smallIncrements('{$column}')", $definition);
                } else if (strpos($definition, "mediumInteger('{$column}')") !== false) {
                    // Replace the mediumInteger definition with mediumIncrements
                    $definition = str_replace("mediumInteger('{$column}')", "mediumIncrements('{$column}')", $definition);
                } else if (strpos($definition, "tinyInteger('{$column}')") !== false) {
                    // Replace the tinyInteger definition with tinyIncrements
                    $definition = str_replace("tinyInteger('{$column}')", "tinyIncrements('{$column}')", $definition);
                } else {
                    // For other types, add autoIncrement()
                    $definition .= "->autoIncrement()->primary()";
                }
            } else {
                // Non-auto-incrementing primary key
                $definition .= "->primary()";
            }
        }

        // Check if unique
        if ($this->isUnique($table, $column)) {
            $definition .= "->unique()";
        }

        return $definition . ";";
    }

    protected function getColumnType($table, $column)
    {
        $connection = DB::connection($this->connection);

        // Get column type from database
        $columnInfo = $this->getColumnInfo($table, $column);
        $type = strtolower($columnInfo->DATA_TYPE ?? $columnInfo->Type ?? $columnInfo->type ?? 'string');

        // Map database types to Laravel migration methods
        $typeMap = [
            'int' => 'integer',
            'integer' => 'integer',
            'tinyint' => 'tinyInteger',
            'smallint' => 'smallInteger',
            'mediumint' => 'mediumInteger',
            'bigint' => 'bigInteger',
            'varchar' => 'string',
            'nvarchar' => 'string',
            'char' => 'char',
            'text' => 'text',
            'mediumtext' => 'mediumText',
            'longtext' => 'longText',
            'decimal' => 'decimal',
            'numeric' => 'decimal',
            'float' => 'float',
            'double' => 'double',
            'date' => 'date',
            'datetime' => 'dateTime',
            'timestamp' => 'timestamp',
            'time' => 'time',
            'boolean' => 'boolean',
            'bool' => 'boolean',
            'enum' => 'enum',
            'json' => 'json',
            'blob' => 'binary',
            'binary' => 'binary',
            'varbinary' => 'binary',
            'bit' => 'boolean',
        ];

        return $typeMap[$type] ?? 'string';
    }

    protected function getColumnInfo($table, $column)
    {
        static $columnCache = [];

        // Create a cache key for this table and column
        $cacheKey = "{$table}.{$column}";

        // Return cached result if available
        if (isset($columnCache[$cacheKey])) {
            return $columnCache[$cacheKey];
        }

        $connection = DB::connection($this->connection);
        $result = null;

        switch ($connection->getDriverName()) {
            case 'mysql':
                $result = $connection->select("SHOW COLUMNS FROM `{$table}` WHERE Field = ?", [$column]);
                $result = $result[0] ?? null;
                break;

            case 'pgsql':
                $result = $connection->select("
                    SELECT column_name, data_type, is_nullable, column_default
                    FROM information_schema.columns
                    WHERE table_name = ? AND column_name = ?
                ", [$table, $column]);
                $result = $result[0] ?? null;
                break;

            case 'sqlite':
                $result = $connection->select("PRAGMA table_info('{$table}')");
                foreach ($result as $col) {
                    if ($col->name === $column) {
                        $result = $col;
                        break;
                    }
                }
                break;

            case 'sqlsrv':
                $result = $connection->select("
                    SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
                    FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_NAME = ? AND COLUMN_NAME = ?
                ", [$table, $column]);
                $result = $result[0] ?? null;
                break;

            default:
                return null;
        }

        // Cache the result
        $columnCache[$cacheKey] = $result;

        return $result;
    }

    protected function isNullable($table, $column)
    {
        $columnInfo = $this->getColumnInfo($table, $column);

        if (!$columnInfo) {
            return false;
        }

        if (isset($columnInfo->IS_NULLABLE)) {
            return strtoupper($columnInfo->IS_NULLABLE) === 'YES';
        }

        if (isset($columnInfo->Null)) {
            return strtoupper($columnInfo->Null) === 'YES';
        }

        if (isset($columnInfo->notnull)) {
            return !$columnInfo->notnull;
        }

        return false;
    }

    protected function getDefaultValue($table, $column)
    {
        $columnInfo = $this->getColumnInfo($table, $column);

        if (!$columnInfo) {
            return null;
        }

        $default = $columnInfo->COLUMN_DEFAULT ?? $columnInfo->Default ?? $columnInfo->dflt_value ?? null;

        if ($default === null) {
            return null;
        }

        // First, remove the outermost quotes and parentheses
        $default = preg_replace(["/^'(.*)'$/", "/^\"(.*)\"$/", "/^\((.*)\)$/"], '$1', $default);

        // Then remove any escaped quotes that might be in the default value
        $default = str_replace(["\'", '\"', "\\\\"], ["'", '"', "\\"], $default);

        // If the value is still wrapped in quotes, remove them again (for nested quotes)
        $default = preg_replace(["/^'(.*)'$/", "/^\"(.*)\"$/"], '$1', $default);

        return $default;
    }

    protected function isPrimaryKey($table, $column)
    {
        $connection = DB::connection($this->connection);

        switch ($connection->getDriverName()) {
            case 'mysql':
                $result = $connection->select("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY' AND Column_name = ?", [$column]);
                return !empty($result);

            case 'pgsql':
                $result = $connection->select("
                    SELECT a.attname
                    FROM pg_index i
                    JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
                    WHERE i.indrelid = ?::regclass AND i.indisprimary AND a.attname = ?
                ", [$table, $column]);
                return !empty($result);

            case 'sqlite':
                $result = $connection->select("PRAGMA table_info('{$table}')");
                foreach ($result as $col) {
                    if ($col->name === $column && $col->pk) {
                        return true;
                    }
                }
                return false;

            case 'sqlsrv':
                $result = $connection->select("
                    SELECT ku.COLUMN_NAME
                    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS AS tc
                    JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE AS ku
                        ON tc.CONSTRAINT_TYPE = 'PRIMARY KEY'
                        AND tc.CONSTRAINT_NAME = ku.CONSTRAINT_NAME
                    WHERE ku.TABLE_NAME = ? AND ku.COLUMN_NAME = ?
                ", [$table, $column]);
                return !empty($result);

            default:
                return false;
        }
    }

    protected function isUnique($table, $column)
    {
        $connection = DB::connection($this->connection);

        switch ($connection->getDriverName()) {
            case 'mysql':
                $result = $connection->select("
                    SELECT COUNT(*) as count
                    FROM information_schema.statistics
                    WHERE table_schema = DATABASE()
                    AND table_name = ?
                    AND column_name = ?
                    AND non_unique = 0
                    AND index_name != 'PRIMARY'
                ", [$table, $column]);
                return $result[0]->count > 0;

            case 'pgsql':
                $result = $connection->select("
                    SELECT COUNT(*) as count
                    FROM pg_indexes
                    JOIN pg_class idx ON idx.relname = indexname
                    JOIN pg_index i ON i.indexrelid = idx.oid
                    JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
                    WHERE schemaname = 'public'
                    AND tablename = ?
                    AND a.attname = ?
                    AND i.indisunique
                    AND NOT i.indisprimary
                ", [$table, $column]);
                return $result[0]->count > 0;

            case 'sqlite':
                $result = $connection->select("PRAGMA index_list('{$table}')");
                foreach ($result as $index) {
                    if ($index->unique) {
                        $indexInfo = $connection->select("PRAGMA index_info('{$index->name}')");
                        foreach ($indexInfo as $indexColumn) {
                            if ($indexColumn->name === $column) {
                                return true;
                            }
                        }
                    }
                }
                return false;

            case 'sqlsrv':
                $result = $connection->select("
                    SELECT COUNT(*) as count
                    FROM sys.indexes i
                    JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id
                    JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id
                    JOIN sys.tables t ON i.object_id = t.object_id
                    WHERE t.name = ?
                    AND c.name = ?
                    AND i.is_unique = 1
                    AND i.is_primary_key = 0
                ", [$table, $column]);
                return $result[0]->count > 0;

            default:
                return false;
        }
    }

    protected function getPrimaryKey($table)
    {
        static $primaryKeyCache = [];

        // Return cached result if available
        if (isset($primaryKeyCache[$table])) {
            return $primaryKeyCache[$table];
        }

        $connection = DB::connection($this->connection);
        $result = 'id'; // Default value

        switch ($connection->getDriverName()) {
            case 'mysql':
                $keys = $connection->select("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
                $result = $keys[0]->Column_name ?? 'id';
                break;

            case 'pgsql':
                $result = $connection->select("
                    SELECT a.attname
                    FROM pg_index i
                    JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
                    WHERE i.indrelid = ?::regclass AND i.indisprimary
                ", [$table]);
                $result = $result[0]->attname ?? 'id';
                break;

            case 'sqlite':
                $result = $connection->select("PRAGMA table_info('{$table}')");
                foreach ($result as $column) {
                    if ($column->pk) {
                        $result = $column->name;
                        break;
                    }
                }
                break;

            case 'sqlsrv':
                $result = $connection->select("
                    SELECT ku.COLUMN_NAME
                    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS AS tc
                    JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE AS ku
                        ON tc.CONSTRAINT_TYPE = 'PRIMARY KEY'
                        AND tc.CONSTRAINT_NAME = ku.CONSTRAINT_NAME
                    WHERE ku.TABLE_NAME = ?
                ", [$table]);
                $result = $result[0]->COLUMN_NAME ?? 'id';
                break;

            default:
                return 'id';
        }

        // Cache the result
        $primaryKeyCache[$table] = $result;

        return $result;
    }

    protected function getForeignKeys($table)
    {
        static $foreignKeyCache = [];

        // Return cached result if available
        if (isset($foreignKeyCache[$table])) {
            return $foreignKeyCache[$table];
        }

        $connection = DB::connection($this->connection);
        $result = [];

        switch ($connection->getDriverName()) {
            case 'mysql':
                $result = $connection->select("
                    SELECT
                        COLUMN_NAME as column_name,
                        REFERENCED_TABLE_NAME as foreign_table_name,
                        REFERENCED_COLUMN_NAME as foreign_column_name
                    FROM
                        INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                    WHERE
                        TABLE_SCHEMA = DATABASE() AND
                        TABLE_NAME = ? AND
                        REFERENCED_TABLE_NAME IS NOT NULL
                ", [$table]);
                break;

            case 'pgsql':
                $result = $connection->select("
                    SELECT
                        kcu.column_name as column_name,
                        ccu.table_name AS foreign_table_name,
                        ccu.column_name AS foreign_column_name
                    FROM
                        information_schema.table_constraints AS tc
                        JOIN information_schema.key_column_usage AS kcu
                          ON tc.constraint_name = kcu.constraint_name
                        JOIN information_schema.constraint_column_usage AS ccu
                          ON ccu.constraint_name = tc.constraint_name
                    WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name = ?
                ", [$table]);
                break;

            case 'sqlite':
                $foreignKeys = [];
                $pragmaForeignKeys = $connection->select("PRAGMA foreign_key_list('{$table}')");

                foreach ($pragmaForeignKeys as $fk) {
                    $foreignKeys[] = (object)[
                        'column_name' => $fk->from,
                        'foreign_table_name' => $fk->table,
                        'foreign_column_name' => $fk->to
                    ];
                }

                $result = $foreignKeys;
                break;

            case 'sqlsrv':
                $result = $connection->select("
                    SELECT
                        fk.name AS constraint_name,
                        OBJECT_NAME(fk.parent_object_id) AS table_name,
                        c1.name AS column_name,
                        OBJECT_NAME(fk.referenced_object_id) AS foreign_table_name,
                        c2.name AS foreign_column_name
                    FROM
                        sys.foreign_keys fk
                        INNER JOIN sys.foreign_key_columns fkc ON fkc.constraint_object_id = fk.object_id
                        INNER JOIN sys.columns c1 ON fkc.parent_column_id = c1.column_id AND fkc.parent_object_id = c1.object_id
                        INNER JOIN sys.columns c2 ON fkc.referenced_column_id = c2.column_id AND fkc.referenced_object_id = c2.object_id
                    WHERE
                        OBJECT_NAME(fk.parent_object_id) = ?
                ", [$table]);
                break;

            default:
                return [];
        }

        // Cache the result
        $foreignKeyCache[$table] = $result;

        return $result;
    }

    protected function getForeignKeyDefinitions($table)
    {
        $foreignKeys = $this->getForeignKeys($table);
        $definitions = [];

        foreach ($foreignKeys as $fk) {
            $definition = "\$table->foreign('{$fk->column_name}')";
            $definition .= "->references('{$fk->foreign_column_name}')";
            $definition .= "->on('{$fk->foreign_table_name}')";
            $definition .= "->onDelete('cascade')";

            $definitions[] = $definition . ";";
        }

        return $definitions;
    }

    protected function getRelationships($table)
    {
        $relationships = '';
        $foreignKeys = $this->getForeignKeys($table);

        // Add belongsTo relationships
        foreach ($foreignKeys as $fk) {
            $relatedModel = Str::studly(Str::singular($fk->foreign_table_name));
            $methodName = Str::camel(Str::singular($fk->foreign_table_name));

            $relationships .= "    public function {$methodName}()\n";
            $relationships .= "    {\n";
            $relationships .= "        return \$this->belongsTo(\\App\\Models\\{$relatedModel}::class, '{$fk->column_name}', '{$fk->foreign_column_name}');\n";
            $relationships .= "    }\n\n";
        }

        // Add hasMany relationships (tables that reference this table)
        $connection = DB::connection($this->connection);
        $referencingTables = $this->getReferencingTables($table);

        foreach ($referencingTables as $ref) {
            $relatedModel = Str::studly(Str::singular($ref->table_name));
            $methodName = Str::camel(Str::plural($ref->table_name));

            $relationships .= "    public function {$methodName}()\n";
            $relationships .= "    {\n";
            $relationships .= "        return \$this->hasMany(\\App\\Models\\{$relatedModel}::class, '{$ref->column_name}', '{$ref->foreign_column_name}');\n";
            $relationships .= "    }\n\n";
        }

        return $relationships;
    }

    protected function getReferencingTables($table)
    {
        static $referencingCache = [];

        // Return cached result if available
        if (isset($referencingCache[$table])) {
            return $referencingCache[$table];
        }

        $connection = DB::connection($this->connection);
        $primaryKey = $this->getPrimaryKey($table);
        $result = [];

        switch ($connection->getDriverName()) {
            case 'mysql':
                $result = $connection->select("
                    SELECT
                        TABLE_NAME as table_name,
                        COLUMN_NAME as column_name,
                        REFERENCED_COLUMN_NAME as foreign_column_name
                    FROM
                        INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                    WHERE
                        TABLE_SCHEMA = DATABASE() AND
                        REFERENCED_TABLE_NAME = ? AND
                        REFERENCED_COLUMN_NAME = ?
                ", [$table, $primaryKey]);
                break;

            case 'pgsql':
                $result = $connection->select("
                    SELECT
                        tc.table_name as table_name,
                        kcu.column_name as column_name,
                        ccu.column_name as foreign_column_name
                    FROM
                        information_schema.table_constraints AS tc
                        JOIN information_schema.key_column_usage AS kcu
                          ON tc.constraint_name = kcu.constraint_name
                        JOIN information_schema.constraint_column_usage AS ccu
                          ON ccu.constraint_name = tc.constraint_name
                    WHERE tc.constraint_type = 'FOREIGN KEY' AND ccu.table_name = ? AND ccu.column_name = ?
                ", [$table, $primaryKey]);
                break;

            case 'sqlite':
                $referencingTables = [];
                $allTables = $this->getTables();

                foreach ($allTables as $checkTable) {
                    if ($checkTable === $table) continue;

                    $pragmaForeignKeys = $connection->select("PRAGMA foreign_key_list('{$checkTable}')");

                    foreach ($pragmaForeignKeys as $fk) {
                        if ($fk->table === $table && $fk->to === $primaryKey) {
                            $referencingTables[] = (object)[
                                'table_name' => $checkTable,
                                'column_name' => $fk->from,
                                'foreign_column_name' => $fk->to
                            ];
                        }
                    }
                }

                $result = $referencingTables;
                break;

            case 'sqlsrv':
                $result = $connection->select("
                    SELECT
                        OBJECT_NAME(fk.parent_object_id) AS table_name,
                        c1.name AS column_name,
                        c2.name AS foreign_column_name
                    FROM
                        sys.foreign_keys fk
                        INNER JOIN sys.foreign_key_columns fkc ON fkc.constraint_object_id = fk.object_id
                        INNER JOIN sys.columns c1 ON fkc.parent_column_id = c1.column_id AND fkc.parent_object_id = c1.object_id
                        INNER JOIN sys.columns c2 ON fkc.referenced_column_id = c2.column_id AND fkc.referenced_object_id = c2.object_id
                    WHERE
                        OBJECT_NAME(fk.referenced_object_id) = ? AND
                        c2.name = ?
                ", [$table, $primaryKey]);
                break;

            default:
                return [];
        }

        // Cache the result
        $referencingCache[$table] = $result;

        return $result;
    }

    protected function sortTablesByDependencies($tables)
    {
        $dependencies = [];
        $sorted = [];

        // Build dependency graph
        foreach ($tables as $table) {
            $foreignKeys = $this->getForeignKeys($table);
            $dependencies[$table] = [];

            foreach ($foreignKeys as $fk) {
                if (in_array($fk->foreign_table_name, $tables) && $fk->foreign_table_name !== $table) {
                    $dependencies[$table][] = $fk->foreign_table_name;
                }
            }
        }

        // Helper function for topological sort
        $visit = function($table) use (&$visit, &$sorted, &$dependencies, &$visited, &$temp) {
            // If we've seen this node temporarily, it's a cycle
            if (isset($temp[$table])) {
                // Handle cycles by breaking the dependency
                return;
            }

            // If we've already visited this node, skip it
            if (isset($visited[$table])) {
                return;
            }

            // Mark node as temporarily visited
            $temp[$table] = true;

            // Visit dependencies
            foreach ($dependencies[$table] as $dependency) {
                $visit($dependency);
            }

            // Mark as visited and add to sorted list
            $visited[$table] = true;
            unset($temp[$table]);
            $sorted[] = $table;
        };

        // Perform topological sort
        $visited = [];
        $temp = [];

        foreach ($tables as $table) {
            if (!isset($visited[$table])) {
                $visit($table);
            }
        }

        // Return the sorted list in reverse order so that tables with no dependencies come first
        return array_reverse(array_unique($sorted));
    }

    protected function generateForeignKeyMigration($tables)
    {
        $className = 'AddForeignKeysToAllTables';
        $migrationPath = base_path(config('database-to-model.paths.migration') ?? 'database/migrations');

        $timestamp = date('Y_m_d_His');
        $filename = "{$timestamp}_add_foreign_keys_to_all_tables.php";
        $migrationContent = $this->getForeignKeyMigrationContent($className, $tables);

        file_put_contents("{$migrationPath}/{$filename}", $migrationContent);
        $this->info("Foreign key migration created successfully!");
    }

    protected function getForeignKeyMigrationContent($className, $tables)
    {
        $content = "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nclass {$className} extends Migration\n{\n";

        // Up method
        $content .= "    public function up()\n    {\n";

        foreach ($tables as $table) {
            $foreignKeys = $this->getForeignKeyDefinitions($table);
            if (!empty($foreignKeys)) {
                $content .= "        Schema::table('{$table}', function (Blueprint \$table) {\n";
                foreach ($foreignKeys as $foreignKey) {
                    $content .= "            {$foreignKey}\n";
                }
                $content .= "        });\n\n";
            }
        }

        $content .= "    }\n\n";

        // Down method
        $content .= "    public function down()\n    {\n";

        // Drop foreign keys in reverse order
        foreach (array_reverse($tables) as $table) {
            $foreignKeys = $this->getForeignKeys($table);
            if (!empty($foreignKeys)) {
                $content .= "        Schema::table('{$table}', function (Blueprint \$table) {\n";
                foreach ($foreignKeys as $fk) {
                    $constraintName = "'{$table}_{$fk->column_name}_foreign'";
                    $content .= "            \$table->dropForeign({$constraintName});\n";
                }
                $content .= "        });\n\n";
            }
        }

        $content .= "    }\n";

        $content .= "}\n";

        return $content;
    }

    protected function isAutoIncrement($table, $column)
    {
        $connection = DB::connection($this->connection);

        switch ($connection->getDriverName()) {
            case 'mysql':
                $columnInfo = $this->getColumnInfo($table, $column);
                return isset($columnInfo->Extra) && strpos($columnInfo->Extra, 'auto_increment') !== false;

            case 'pgsql':
                // In PostgreSQL, check for sequences
                $result = $connection->select("
                    SELECT pg_get_serial_sequence(?, ?) IS NOT NULL as is_serial
                ", [$table, $column]);
                return !empty($result) && $result[0]->is_serial;

            case 'sqlite':
                // In SQLite, check for AUTOINCREMENT keyword in table creation SQL
                $result = $connection->select("SELECT sql FROM sqlite_master WHERE type='table' AND name=?", [$table]);
                if (!empty($result)) {
                    $tableSql = $result[0]->sql;
                    // Look for column definition with AUTOINCREMENT
                    return preg_match("/[`\"\[]?{$column}[`\"\]]?.*AUTOINCREMENT/i", $tableSql) === 1;
                }
                return false;

            case 'sqlsrv':
                // In SQL Server, check for IDENTITY property
                $result = $connection->select("
                    SELECT COLUMNPROPERTY(OBJECT_ID(?), ?, 'IsIdentity') as is_identity
                ", [$table, $column]);
                return !empty($result) && $result[0]->is_identity == 1;

            default:
                return false;
        }
    }
}
