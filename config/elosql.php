<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | The database connection to analyze. Defaults to the application's
    | default connection if not specified.
    |
    */
    'connection' => env('ELOSQL_CONNECTION', config('database.default')),

    /*
    |--------------------------------------------------------------------------
    | Excluded Tables
    |--------------------------------------------------------------------------
    |
    | Tables that should be excluded from schema analysis and generation.
    | Laravel's internal tables are excluded by default.
    |
    */
    'exclude_tables' => [
        'migrations',
        'password_resets',
        'password_reset_tokens',
        'failed_jobs',
        'personal_access_tokens',
        'jobs',
        'job_batches',
        'cache',
        'cache_locks',
        'sessions',
    ],

    /*
    |--------------------------------------------------------------------------
    | Migrations Path
    |--------------------------------------------------------------------------
    |
    | The directory where generated migrations will be placed.
    |
    */
    'migrations_path' => database_path('migrations'),

    /*
    |--------------------------------------------------------------------------
    | Model Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for generated Eloquent models.
    |
    */
    'models' => [
        // Directory where models will be generated
        'path' => app_path('Models'),

        // Namespace for generated models
        'namespace' => 'App\\Models',

        // Base class for all generated models
        'base_class' => 'Illuminate\\Database\\Eloquent\\Model',

        // Whether to generate relationship methods
        'generate_relationships' => true,

        // Whether to generate query scopes
        'generate_scopes' => true,

        // Whether to generate accessor/mutator suggestions as comments
        'generate_accessor_suggestions' => true,

        // Use $fillable (whitelist) or $guarded (blacklist)
        'use_fillable' => true,

        // Columns to always exclude from fillable
        'guarded_columns' => [
            'id',
            'created_at',
            'updated_at',
            'deleted_at',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Type Mappings
    |--------------------------------------------------------------------------
    |
    | Custom mappings from database column types to Laravel migration methods.
    | Use this to override default mappings or add support for custom types.
    |
    | Format: 'database_type' => 'laravel_method'
    | Or: 'database_type' => ['method' => 'laravel_method', 'cast' => 'php_cast']
    |
    */
    'type_mappings' => [
        // Example custom mappings:
        // 'citext' => 'string',
        // 'money' => ['method' => 'decimal', 'precision' => 19, 'scale' => 4],
    ],

    /*
    |--------------------------------------------------------------------------
    | Formatting Options
    |--------------------------------------------------------------------------
    |
    | Code formatting preferences for generated files.
    |
    */
    'formatting' => [
        // Indentation string (spaces or tab)
        'indent' => '    ',

        // Maximum line length before wrapping
        'line_length' => 120,

        // Sort use statements alphabetically
        'sort_imports' => true,

        // Add trailing commas in arrays
        'trailing_commas' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Enable or disable specific features of the generator.
    |
    */
    'features' => [
        // Automatically detect pivot tables by naming convention
        'detect_pivot_tables' => true,

        // Generate factory files (not yet implemented)
        'generate_factories' => false,

        // Generate seeder files (not yet implemented)
        'generate_seeders' => false,

        // Add PHPDoc blocks to generated code
        'add_docblocks' => true,

        // Detect and generate polymorphic relationships
        'detect_polymorphic' => true,

        // Generate separate foreign key migrations
        'separate_foreign_keys' => true,

        // Add table and column comments to migrations
        'include_comments' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Timestamp Detection
    |--------------------------------------------------------------------------
    |
    | Column names that indicate Laravel timestamp columns.
    |
    */
    'timestamps' => [
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
        'deleted_at' => 'deleted_at',
    ],

    /*
    |--------------------------------------------------------------------------
    | Pivot Table Detection
    |--------------------------------------------------------------------------
    |
    | Patterns and rules for detecting many-to-many pivot tables.
    |
    */
    'pivot_detection' => [
        // Naming patterns (regex) for pivot tables
        'patterns' => [
            '/^[a-z]+_[a-z]+$/',  // table1_table2
        ],

        // Required column patterns for pivot tables
        'required_columns' => [
            '/_id$/',  // Must have at least 2 foreign key columns
        ],

        // Maximum extra columns allowed (excluding timestamps)
        'max_extra_columns' => 3,
    ],
];
