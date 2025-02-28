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

## Usage

Generate models and migrations for all tables:

```bash
php artisan generate:models-migrations
```

### Available Options

- `--tables`: Specific tables to generate (comma-separated)
- `--connection`: Database connection to use (defaults to your default connection)

### Examples

Generate for specific tables:

```bash
php artisan generate:models-migrations --tables=users,products,orders
```

Use a different database connection:

```bash
php artisan generate:models-migrations --connection=mysql
```

## Features

- Automatically generates models with proper relationships
- Creates migrations that match your existing database structure
- Handles foreign key constraints properly
- Sorts tables by dependencies to ensure proper migration order
- Customizable through configuration

## Requirements

- PHP ^8.0
- Laravel ^9.0|^10.0|^11.0

## Author

- [Syahmi Jalil](mailto:syahmijalil.my@gmail.com)
