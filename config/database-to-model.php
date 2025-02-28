<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Ignored Tables
    |--------------------------------------------------------------------------
    |
    | These tables will be ignored by default when generating models and migrations.
    |
    */
    'ignored_tables' => [
        'migrations',
        'failed_jobs',
        'password_resets',
        'audits',
        'sessions',
        'sp_password_resets',
        'email_logs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Paths
    |--------------------------------------------------------------------------
    |
    | Default paths for generated files.
    |
    */
    'paths' => [
        'model' => 'app/Models',
        'migration' => 'database/migrations',
    ],

    /*
    |--------------------------------------------------------------------------
    | Max Columns Per Migration
    |--------------------------------------------------------------------------
    |
    | Maximum number of columns to include in a single migration file.
    | Migrations will be split if they exceed this number.
    |
    */
    'max_columns_per_migration' => 15,
]; 