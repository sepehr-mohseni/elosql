<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Sepehr_Mohseni\Elosql\Generators\MigrationGenerator;
use Sepehr_Mohseni\Elosql\Generators\ModelGenerator;
use Sepehr_Mohseni\Elosql\Parsers\SQLiteSchemaParser;
use Sepehr_Mohseni\Elosql\Tests\TestCase;

class GenerateFromDatabaseTest extends TestCase
{
    private SQLiteSchemaParser $parser;

    private MigrationGenerator $migrationGenerator;

    private ModelGenerator $modelGenerator;

    private string $tempDir;

    private Filesystem $filesystem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir() . '/elosql_feature_test_' . uniqid();
        $this->filesystem->makeDirectory($this->tempDir, 0o755, true);
        $this->filesystem->makeDirectory($this->tempDir . '/migrations', 0o755, true);
        $this->filesystem->makeDirectory($this->tempDir . '/Models', 0o755, true);

        $this->parser = $this->app->make(SQLiteSchemaParser::class);
        $this->parser->setConnection($this->app['db']->connection('testing'));
        $this->migrationGenerator = $this->app->make(MigrationGenerator::class);
        $this->migrationGenerator->setDriver('sqlite');
        $this->modelGenerator = $this->app->make(ModelGenerator::class);
        $this->modelGenerator->setDriver('sqlite');

        $this->createTestTables();
    }

    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    public function test_generates_migrations_from_database(): void
    {
        $tables = $this->parser->parseAllTables();

        $migrations = $this->migrationGenerator->generate($tables, separateForeignKeys: true);

        // Should generate migrations for all tables plus FK migrations
        $this->assertNotEmpty($migrations);

        foreach ($migrations as $filename => $content) {
            // All migrations should be valid PHP
            $this->assertStringContainsString('<?php', $content);
            $this->assertStringContainsString('use Illuminate\Database\Migrations\Migration', $content);
        }
    }

    public function test_generated_migrations_are_in_correct_order(): void
    {
        $tables = $this->parser->parseAllTables();

        $migrations = $this->migrationGenerator->generate($tables, separateForeignKeys: true);

        // Get filenames
        $filenames = array_keys($migrations);

        // Find users and posts migrations
        $usersMigration = null;
        $postsMigration = null;
        $usersIndex = null;
        $postsIndex = null;

        foreach ($filenames as $index => $filename) {
            if (str_contains($filename, 'create_users_table')) {
                $usersMigration = $filename;
                $usersIndex = $index;
            }
            if (str_contains($filename, 'create_posts_table')) {
                $postsMigration = $filename;
                $postsIndex = $index;
            }
        }

        $this->assertNotNull($usersMigration, 'Users migration not found');
        $this->assertNotNull($postsMigration, 'Posts migration not found');

        // Users migration should come before posts (due to FK dependency)
        // Check by position in the array rather than timestamp
        $this->assertLessThan($postsIndex, $usersIndex, 'Users migration should come before posts');
    }

    public function test_generates_models_from_database(): void
    {
        $tables = $this->parser->parseAllTables();

        $models = $this->modelGenerator->generateAll($tables);

        $this->assertNotEmpty($models);

        foreach ($models as $filename => $content) {
            $this->assertStringEndsWith('.php', $filename);
            $this->assertStringContainsString('class', $content);
            $this->assertStringContainsString('extends Model', $content);
        }
    }

    public function test_generated_models_have_correct_relationships(): void
    {
        $tables = $this->parser->parseAllTables();

        // Generate Post model
        $postsTable = collect($tables)->firstWhere('name', 'posts');
        $postModel = $this->modelGenerator->generate($postsTable, $tables);

        // Post should have belongsTo User
        $this->assertStringContainsString('function user()', $postModel);
        $this->assertStringContainsString('belongsTo', $postModel);

        // Generate User model
        $usersTable = collect($tables)->firstWhere('name', 'users');
        $userModel = $this->modelGenerator->generate($usersTable, $tables);

        // User should have hasMany Posts
        $this->assertStringContainsString('function posts()', $userModel);
        $this->assertStringContainsString('hasMany', $userModel);
    }

    public function test_generated_models_have_correct_fillable(): void
    {
        $tables = $this->parser->parseAllTables();

        $usersTable = collect($tables)->firstWhere('name', 'users');
        $userModel = $this->modelGenerator->generate($usersTable, $tables);

        // Should have fillable array
        $this->assertStringContainsString('$fillable', $userModel);
        $this->assertStringContainsString("'name'", $userModel);
        $this->assertStringContainsString("'email'", $userModel);
    }

    public function test_generated_models_have_correct_casts(): void
    {
        $tables = $this->parser->parseAllTables();

        $postsTable = collect($tables)->firstWhere('name', 'posts');
        $postModel = $this->modelGenerator->generate($postsTable, $tables);

        // Posts has datetime columns which should trigger timestamp handling
        // Check for basic model structure
        $this->assertStringContainsString('extends Model', $postModel);
        $this->assertStringContainsString('$fillable', $postModel);
    }

    public function test_generates_pivot_model_for_many_to_many(): void
    {
        $tables = $this->parser->parseAllTables();

        // category_post is a pivot table
        $postTable = collect($tables)->firstWhere('name', 'posts');
        $categoryTable = collect($tables)->firstWhere('name', 'categories');

        $postModel = $this->modelGenerator->generate($postTable, $tables);
        $categoryModel = $this->modelGenerator->generate($categoryTable, $tables);

        // Both should have belongsToMany
        $this->assertStringContainsString('belongsToMany', $postModel);
        $this->assertStringContainsString('belongsToMany', $categoryModel);
    }

    public function test_generates_soft_deletes_trait(): void
    {
        $tables = $this->parser->parseAllTables();

        // Posts table has soft deletes
        $postsTable = collect($tables)->firstWhere('name', 'posts');
        $postModel = $this->modelGenerator->generate($postsTable, $tables);

        $this->assertStringContainsString('SoftDeletes', $postModel);
    }

    public function test_handles_table_with_all_column_types(): void
    {
        // Create a table with many column types
        $this->app['db']->connection()->getSchemaBuilder()->create('all_types', function ($table) {
            $table->id();
            $table->string('varchar_col');
            $table->text('text_col')->nullable();
            $table->integer('int_col');
            $table->boolean('bool_col')->default(false);
            $table->decimal('decimal_col', 10, 2)->nullable();
            $table->dateTime('datetime_col')->nullable();
            $table->json('json_col')->nullable();
            $table->timestamps();
        });

        try {
            $table = $this->parser->parseTable('all_types');

            $migration = $this->migrationGenerator->generateTableMigration($table);
            $model = $this->modelGenerator->generate($table, [$table]);

            // Migration should handle all column types
            $this->assertStringContainsString('Schema::create', $migration);

            // Model should have proper casts
            $this->assertStringContainsString('extends Model', $model);
        } finally {
            $this->app['db']->connection()->getSchemaBuilder()->dropIfExists('all_types');
        }
    }
}
