# Database to Model

A Laravel package to generate models and migrations from existing database structure. This package allows you to reverse engineer your database into Laravel models and migrations, making it easier to work with existing databases or to create a backup of your database structure.

## Installation

You can install the package via composer:
```bash
composer require syahmi-jalil/database-to-model
```

The package will automatically register its service provider.

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=database-to-model-config
```

This will create a `config/database-to-model.php` file where you can configure:

- Tables to ignore when generating models and migrations
- Default paths for generated files
- Maximum number of columns per migration file

## Customization

You can publish the stub files to customize the generated models and migrations:

```bash
php artisan vendor:publish --tag=database-to-model-stubs
```

This will publish the stubs to `stubs/vendor/database-to-model/` in your project root.

## Usage

Generate models and migrations for all tables:

```bash
php artisan generate:models-migrations
```

### Available Options

- `--tables`: Specific tables to generate (comma-separated)
- `--ignore`: Additional tables to ignore (comma-separated)
- `--connection`: Database connection to use (defaults to your default connection)
- `--path-model`: Custom path for generated models
- `--path-migration`: Custom path for generated migrations

### Examples

Generate for specific tables:

```bash
php artisan generate:models-migrations --tables=users,products,orders
```

Ignore specific tables:

```bash
php artisan generate:models-migrations --ignore=cache,logs,temp_data
```

Use a different database connection:

```bash
php artisan generate:models-migrations --connection=mysql
```

Specify custom paths:

```bash
php artisan generate:models-migrations --path-model=app/Models/Generated --path-migration=database/migrations/from-existing
```

## Features

- Automatically generates models with proper relationships
- Creates migrations that match your existing database structure
- Handles foreign key constraints properly
- Sorts tables by dependencies to ensure proper migration order
- Customizable through configuration and stubs

## Requirements

- PHP ^7.3|^8.0
- Laravel ^8.0|^9.0|^10.0|^11.0

## Author

- [Syahmi Jalil](mailto:syahmijalil.my@gmail.com)
