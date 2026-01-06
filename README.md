# Elosql

[![Tests](https://github.com/sepehr-mohseni/elosql/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/sepehr-mohseni/elosql/actions/workflows/tests.yml)
[![Latest Stable Version](https://poser.pugx.org/sepehr-mohseni/elosql/v/stable)](https://packagist.org/packages/sepehr-mohseni/elosql)
[![Total Downloads](https://poser.pugx.org/sepehr-mohseni/elosql/downloads)](https://packagist.org/packages/sepehr-mohseni/elosql)
[![License](https://poser.pugx.org/sepehr-mohseni/elosql/license)](https://packagist.org/packages/sepehr-mohseni/elosql)
[![PHP Version](https://img.shields.io/packagist/php-v/sepehr-mohseni/elosql)](https://packagist.org/packages/sepehr-mohseni/elosql)

**Elosql** is a production-grade Laravel package that intelligently analyzes existing database schemas and generates precise migrations and Eloquent models. It supports MySQL, PostgreSQL, SQLite, and SQL Server, making it perfect for legacy database integration, reverse engineering, and rapid application scaffolding.

## Features

- 🔍 **Smart Schema Analysis** - Automatically detects columns, indexes, foreign keys, and table relationships
- 🚀 **Multi-Database Support** - Works with MySQL/MariaDB, PostgreSQL, SQLite, and SQL Server
- 📁 **Migration Generation** - Creates Laravel migrations with proper dependency ordering
- 🏗️ **Model Scaffolding** - Generates Eloquent models with relationships, casts, and fillable attributes
- 🔗 **Relationship Detection** - Automatically detects `belongsTo`, `hasMany`, `hasOne`, `belongsToMany`, and polymorphic relationships
- 📊 **Schema Diff** - Compare database schema with existing migrations
- ⚙️ **Highly Configurable** - Customize every aspect of generation through config or command options
- ✅ **Production Ready** - Comprehensive test suite with 90%+ coverage

## Requirements

- PHP 8.1 or higher
- Laravel 10.0 or 11.0

## Installation

Install via Composer:

```bash
composer require sepehr-mohseni/elosql
```

The package will auto-register its service provider. Optionally, publish the configuration file:

```bash
php artisan vendor:publish --tag=elosql-config
```

## Quick Start

### Generate Everything

Generate migrations and models for your entire database:

```bash
php artisan elosql:schema
```

### Preview Schema

See what will be generated without creating any files:

```bash
php artisan elosql:preview
```

### Generate Migrations Only

```bash
php artisan elosql:migrations
```

### Generate Models Only

```bash
php artisan elosql:models
```

## Commands

### `elosql:schema`

The main command that generates both migrations and models.

```bash
php artisan elosql:schema [options]

Options:
  --connection=       Database connection to use (default: default connection)
  --table=            Generate for specific table(s), comma-separated
  --exclude=          Exclude specific table(s), comma-separated
  --migrations-path=  Custom path for migrations (default: database/migrations)
  --models-path=      Custom path for models (default: app/Models)
  --models-namespace= Custom namespace for models (default: App\Models)
  --no-migrations     Skip migration generation
  --no-models         Skip model generation
  --force             Overwrite existing files
```

**Examples:**

```bash
# Generate for specific tables
php artisan elosql:schema --table=users,posts,comments

# Exclude certain tables
php artisan elosql:schema --exclude=migrations,cache,sessions

# Custom output paths
php artisan elosql:schema --migrations-path=database/generated --models-path=app/Domain/Models

# Use a different database connection
php artisan elosql:schema --connection=legacy_db
```

### `elosql:migrations`

Generate migration files from database schema.

```bash
php artisan elosql:migrations [options]

Options:
  --connection=   Database connection to use
  --table=        Generate for specific table(s)
  --exclude=      Exclude specific table(s)
  --path=         Custom output path
  --fresh         Generate fresh migrations (ignore existing)
  --diff          Only generate migrations for schema differences
  --force         Overwrite existing files
```

**Examples:**

```bash
# Generate migrations for a legacy database
php artisan elosql:migrations --connection=legacy --path=database/legacy-migrations

# Generate only new/changed tables
php artisan elosql:migrations --diff
```

### `elosql:models`

Generate Eloquent model files.

```bash
php artisan elosql:models [options]

Options:
  --connection=   Database connection to use
  --table=        Generate for specific table(s)
  --exclude=      Exclude specific table(s)
  --path=         Custom output path
  --namespace=    Custom namespace
  --preview       Preview generated code without writing files
  --force         Overwrite existing files
```

**Examples:**

```bash
# Preview model generation
php artisan elosql:models --preview --table=users

# Generate with custom namespace
php artisan elosql:models --namespace="Domain\\User\\Models"
```

### `elosql:preview`

Preview the schema analysis without generating any files.

```bash
php artisan elosql:preview [options]

Options:
  --connection=   Database connection to use
  --table=        Preview specific table(s)
  --format=       Output format: table, json, yaml (default: table)
```

**Examples:**

```bash
# JSON output for processing
php artisan elosql:preview --format=json > schema.json

# View specific table structure
php artisan elosql:preview --table=users
```

### `elosql:diff`

Show differences between database schema and existing migrations.

```bash
php artisan elosql:diff [options]

Options:
  --connection=   Database connection to use
  --format=       Output format: table, json (default: table)
```

## Configuration

After publishing the config file (`config/elosql.php`), you can customize:

### Database Connection

```php
'connection' => env('ELOSQL_CONNECTION', null), // null = default connection
```

### Table Filtering

```php
'exclude_tables' => [
    'migrations',
    'failed_jobs',
    'password_resets',
    'personal_access_tokens',
    'cache',
    'sessions',
],
```

### Migration Settings

```php
'migrations' => [
    'path' => database_path('migrations'),
    'separate_foreign_keys' => true, // Generate FK migrations separately
    'include_drop_tables' => true,   // Include down() method
],
```

### Model Settings

```php
'models' => [
    'path' => app_path('Models'),
    'namespace' => 'App\\Models',
    'base_class' => \Illuminate\Database\Eloquent\Model::class,
    'use_guarded' => false,           // Use $guarded instead of $fillable
    'generate_phpdoc' => true,        // Generate PHPDoc blocks
    'detect_soft_deletes' => true,    // Auto-detect SoftDeletes trait
    'detect_timestamps' => true,      // Auto-detect timestamp columns
],
```

### Type Mappings

Customize how database types map to Laravel migration methods:

```php
'type_mappings' => [
    'mysql' => [
        'tinyint(1)' => 'boolean',
        'json' => 'json',
        // Add custom mappings
    ],
    'pgsql' => [
        'jsonb' => 'jsonb',
        'uuid' => 'uuid',
    ],
],
```

### Relationship Detection

```php
'relationships' => [
    'detect_belongs_to' => true,
    'detect_has_many' => true,
    'detect_has_one' => true,
    'detect_belongs_to_many' => true,
    'detect_morph' => true,
    'pivot_table_patterns' => [
        // Regex patterns for detecting pivot tables
        '/^([a-z]+)_([a-z]+)$/',
    ],
],
```

## Generated Code Examples

### Migration Example

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title', 255);
            $table->text('content');
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('status');
            $table->fullText('content');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
```

### Model Example

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string $content
 * @property string $status
 * @property array|null $metadata
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 * 
 * @property-read User $user
 * @property-read \Illuminate\Database\Eloquent\Collection|Comment[] $comments
 * @property-read \Illuminate\Database\Eloquent\Collection|Tag[] $tags
 */
class Post extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'title',
        'content',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'post_tag');
    }
}
```

## Programmatic Usage

You can also use Elosql programmatically:

```php
use Sepehr_Mohseni\Elosql\Parsers\SchemaParserFactory;
use Sepehr_Mohseni\Elosql\Generators\MigrationGenerator;
use Sepehr_Mohseni\Elosql\Generators\ModelGenerator;

// Get the parser for your database
$parser = app(SchemaParserFactory::class)->make('mysql');

// Parse all tables
$tables = $parser->getTables();

// Or parse specific tables
$tables = $parser->getTables([
    'include' => ['users', 'posts'],
    'exclude' => ['migrations'],
]);

// Generate migrations
$migrationGenerator = app(MigrationGenerator::class);
$files = $migrationGenerator->generateAll($tables, 'mysql', database_path('migrations'));

// Generate models
$modelGenerator = app(ModelGenerator::class);
foreach ($tables as $table) {
    $content = $modelGenerator->generate($table, 'mysql', $tables);
    // Write to file or process as needed
}
```

## Handling Foreign Keys

Elosql handles foreign key dependencies intelligently:

1. **Dependency Resolution** - Tables are ordered based on their foreign key dependencies using topological sorting
2. **Separate FK Migrations** - Foreign keys are generated in separate migration files that run after all tables are created
3. **Circular Dependencies** - Detected and reported with suggestions for resolution

This ensures migrations can be run without foreign key constraint violations.

## Supported Column Types

### MySQL/MariaDB
- Integers: `tinyint`, `smallint`, `mediumint`, `int`, `bigint`
- Floating point: `float`, `double`, `decimal`
- Strings: `char`, `varchar`, `text`, `mediumtext`, `longtext`
- Binary: `binary`, `varbinary`, `blob`
- Date/Time: `date`, `datetime`, `timestamp`, `time`, `year`
- Special: `json`, `enum`, `set`, `boolean`
- Spatial: `point`, `linestring`, `polygon`, `geometry`

### PostgreSQL
- All standard types plus: `uuid`, `jsonb`, `inet`, `macaddr`, `cidr`
- Array types
- Range types

### SQLite
- `integer`, `real`, `text`, `blob`, `numeric`

### SQL Server
- All standard types plus: `uniqueidentifier`, `nvarchar`, `ntext`

## Testing

Run the test suite:

```bash
composer test
```

Run with coverage:

```bash
composer test-coverage
```

Run static analysis:

```bash
composer analyse
```

Fix code style:

```bash
composer format
```

## Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## Security

If you discover any security-related issues, please email isepehrmohseni@gmail.com instead of using the issue tracker.

## Credits

- [Sepehr Mohseni](https://github.com/sepehr-mohseni)
- [All Contributors](../../contributors)

## Author

- **Sepehr Mohseni**
- GitHub: [@sepehr-mohseni](https://github.com/sepehr-mohseni)
- LinkedIn: [sepehr-mohseni](https://www.linkedin.com/in/sepehr-mohseni/)
- Email: isepehrmohseni@gmail.com

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
