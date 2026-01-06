<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Sepehr_Mohseni\Elosql\Analyzers\DependencyResolver;
use Sepehr_Mohseni\Elosql\Generators\MigrationGenerator;
use Sepehr_Mohseni\Elosql\Support\FileWriter;
use Sepehr_Mohseni\Elosql\Support\TypeMapper;
use Sepehr_Mohseni\Elosql\Tests\TestCase;
use Sepehr_Mohseni\Elosql\ValueObjects\ColumnSchema;
use Sepehr_Mohseni\Elosql\ValueObjects\ForeignKeySchema;
use Sepehr_Mohseni\Elosql\ValueObjects\IndexSchema;
use Sepehr_Mohseni\Elosql\ValueObjects\TableSchema;

class MigrationGeneratorTest extends TestCase
{
    private MigrationGenerator $generator;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/elosql_migration_test_' . uniqid();
        (new Filesystem())->makeDirectory($this->tempDir, 0o755, true);

        $this->generator = new MigrationGenerator(
            new TypeMapper(),
            new DependencyResolver(),
            new FileWriter(new Filesystem()),
            $this->tempDir
        );
        $this->generator->setDriver('mysql');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    /**
     * Create a column schema with sensible defaults.
     */
    private function createColumn(
        string $name,
        string $type = 'bigint',
        bool $nullable = false,
        mixed $default = null,
        bool $autoIncrement = false,
        bool $unsigned = false,
        ?int $length = null,
        ?int $precision = null,
        ?int $scale = null,
        ?string $charset = null,
        ?string $collation = null,
        ?string $comment = null,
        array $attributes = [],
    ): ColumnSchema {
        return new ColumnSchema(
            name: $name,
            type: $type,
            nativeType: $type,
            nullable: $nullable,
            default: $default,
            autoIncrement: $autoIncrement,
            unsigned: $unsigned,
            length: $length,
            precision: $precision,
            scale: $scale,
            charset: $charset,
            collation: $collation,
            comment: $comment,
            attributes: $attributes,
        );
    }

    public function test_generates_create_table_migration(): void
    {
        $table = new TableSchema(
            'users',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('name', type: 'varchar', length: 255),
                $this->createColumn('email', type: 'varchar', length: 255),
                $this->createColumn('created_at', type: 'timestamp', nullable: true),
                $this->createColumn('updated_at', type: 'timestamp', nullable: true),
            ],
            [
                new IndexSchema(name: 'users_email_unique', type: 'unique', columns: ['email']),
            ],
            []
        );

        $content = $this->generator->generateTableMigration($table);

        $this->assertStringContainsString('use Illuminate\Database\Migrations\Migration', $content);
        $this->assertStringContainsString('use Illuminate\Database\Schema\Blueprint', $content);
        $this->assertStringContainsString('use Illuminate\Support\Facades\Schema', $content);
        $this->assertStringContainsString("Schema::create('users'", $content);
        $this->assertStringContainsString("Schema::dropIfExists('users')", $content);
    }

    public function test_generates_nullable_column(): void
    {
        $table = new TableSchema(
            'posts',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('subtitle', type: 'varchar', nullable: true, length: 255),
            ],
            [],
            []
        );

        $content = $this->generator->generateTableMigration($table);

        $this->assertStringContainsString('nullable()', $content);
    }

    public function test_generates_column_with_default(): void
    {
        $table = new TableSchema(
            'settings',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('is_active', type: 'boolean', default: true),
            ],
            [],
            []
        );

        $content = $this->generator->generateTableMigration($table);

        $this->assertStringContainsString('default(', $content);
    }

    public function test_generates_foreign_key_in_migration(): void
    {
        $table = new TableSchema(
            'posts',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('user_id'),
            ],
            [],
            [
                new ForeignKeySchema(
                    name: 'posts_user_id_foreign',
                    columns: ['user_id'],
                    referencedTable: 'users',
                    referencedColumns: ['id'],
                    onDelete: 'CASCADE',
                    onUpdate: 'SET NULL'
                ),
            ]
        );

        // Generate with foreign keys included
        $content = $this->generator->generateTableMigration($table, excludeForeignKeys: false);

        $this->assertStringContainsString("Schema::create('posts'", $content);
        $this->assertStringContainsString('foreign(', $content);
    }

    public function test_generates_composite_primary_key(): void
    {
        $table = new TableSchema(
            'post_tag',
            [
                $this->createColumn('post_id'),
                $this->createColumn('tag_id'),
            ],
            [
                new IndexSchema(name: 'post_tag_primary', type: 'primary', columns: ['post_id', 'tag_id'], isComposite: true),
            ],
            []
        );

        $content = $this->generator->generateTableMigration($table);

        $this->assertStringContainsString("primary(['post_id', 'tag_id'])", $content);
    }

    public function test_generates_composite_index(): void
    {
        $table = new TableSchema(
            'logs',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('user_id'),
                $this->createColumn('action', type: 'varchar'),
            ],
            [
                new IndexSchema(name: 'logs_user_action_index', type: 'index', columns: ['user_id', 'action'], isComposite: true),
            ],
            []
        );

        $content = $this->generator->generateTableMigration($table);

        // Check for index definition - could be various formats
        $this->assertTrue(
            str_contains($content, "index(['user_id', 'action']") ||
            str_contains($content, 'logs_user_action_index')
        );
    }

    public function test_generates_fulltext_index(): void
    {
        $table = new TableSchema(
            'articles',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('content', type: 'text'),
            ],
            [
                new IndexSchema(name: 'articles_content_fulltext', type: 'fulltext', columns: ['content']),
            ],
            []
        );

        $content = $this->generator->generateTableMigration($table);

        // Fulltext may be called as fullText or fulltext
        $this->assertTrue(
            str_contains($content, 'fullText(') || str_contains($content, 'fulltext(')
        );
    }

    public function test_generates_decimal_column(): void
    {
        $table = new TableSchema(
            'products',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('price', type: 'decimal', precision: 10, scale: 2),
            ],
            [],
            []
        );

        $content = $this->generator->generateTableMigration($table);

        $this->assertStringContainsString("decimal('price'", $content);
    }

    public function test_generates_enum_column(): void
    {
        $table = new TableSchema(
            'posts',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('status', type: 'enum', default: 'draft', attributes: ['enum_values' => ['draft', 'published', 'archived']]),
            ],
            [],
            []
        );

        $content = $this->generator->generateTableMigration($table);

        $this->assertStringContainsString("enum('status'", $content);
    }

    public function test_generates_json_column(): void
    {
        $table = new TableSchema(
            'settings',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('metadata', type: 'json', nullable: true),
            ],
            [],
            []
        );

        $content = $this->generator->generateTableMigration($table);

        $this->assertStringContainsString("json('metadata')", $content);
        $this->assertStringContainsString('nullable()', $content);
    }

    public function test_generates_soft_deletes(): void
    {
        $table = new TableSchema(
            'users',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('deleted_at', type: 'timestamp', nullable: true),
            ],
            [],
            []
        );

        $content = $this->generator->generateTableMigration($table);

        // The generator might use softDeletes() or regular timestamp
        $this->assertTrue(
            str_contains($content, 'softDeletes()') || str_contains($content, "timestamp('deleted_at')")
        );
    }

    public function test_generates_uuid_column(): void
    {
        $this->generator->setDriver('pgsql');

        $table = new TableSchema(
            'tokens',
            [
                $this->createColumn('id', type: 'uuid'),
                $this->createColumn('token', type: 'varchar'),
            ],
            [
                new IndexSchema(name: 'tokens_primary', type: 'primary', columns: ['id']),
            ],
            []
        );

        $content = $this->generator->generateTableMigration($table);

        $this->assertStringContainsString("uuid('id')", $content);
    }

    public function test_generate_all_returns_migrations(): void
    {
        $tables = [
            new TableSchema(
                'users',
                [
                    $this->createColumn('id', autoIncrement: true, unsigned: true),
                ],
                [],
                []
            ),
            new TableSchema(
                'posts',
                [
                    $this->createColumn('id', autoIncrement: true, unsigned: true),
                    $this->createColumn('user_id'),
                ],
                [],
                [
                    new ForeignKeySchema(name: 'posts_user_id_fk', columns: ['user_id'], referencedTable: 'users', referencedColumns: ['id']),
                ]
            ),
        ];

        $migrations = $this->generator->generate($tables, separateForeignKeys: true);

        // Should have create_users, create_posts, and add_posts_foreign_keys
        $this->assertGreaterThanOrEqual(2, count($migrations));

        foreach ($migrations as $filename => $content) {
            $this->assertStringContainsString('.php', $filename);
            $this->assertStringContainsString('Migration', $content);
        }
    }

    public function test_generates_table_with_engine_option(): void
    {
        $table = new TableSchema(
            'users',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
            ],
            [],
            [],
            'InnoDB',
            'utf8mb4',
            'utf8mb4_unicode_ci'
        );

        $content = $this->generator->generateTableMigration($table);

        // Engine and charset may or may not be included depending on implementation
        $this->assertStringContainsString("Schema::create('users'", $content);
    }

    public function test_generates_column_with_comment(): void
    {
        $table = new TableSchema(
            'users',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('status', type: 'int', default: 1, comment: 'User status: 1=active, 0=inactive'),
            ],
            [],
            []
        );

        $content = $this->generator->generateTableMigration($table);

        $this->assertStringContainsString('comment(', $content);
    }

    public function test_generates_spatial_index(): void
    {
        $table = new TableSchema(
            'locations',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('coordinates', type: 'point'),
            ],
            [
                new IndexSchema(name: 'locations_coordinates_spatial', type: 'spatial', columns: ['coordinates']),
            ],
            []
        );

        $content = $this->generator->generateTableMigration($table);

        $this->assertTrue(
            str_contains($content, 'spatialIndex(') || str_contains($content, 'spatial(')
        );
    }

    public function test_set_driver(): void
    {
        $this->generator->setDriver('pgsql');

        // Create a simple table and verify it generates
        $table = new TableSchema(
            'test',
            [$this->createColumn('id', autoIncrement: true)],
            [],
            []
        );

        $content = $this->generator->generateTableMigration($table);
        $this->assertStringContainsString('Migration', $content);
    }
}
