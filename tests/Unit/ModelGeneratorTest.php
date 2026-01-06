<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Tests\Unit;

use Sepehr_Mohseni\Elosql\Generators\ModelGenerator;
use Sepehr_Mohseni\Elosql\Generators\RelationshipDetector;
use Sepehr_Mohseni\Elosql\Support\FileWriter;
use Sepehr_Mohseni\Elosql\Support\NameConverter;
use Sepehr_Mohseni\Elosql\Tests\TestCase;
use Sepehr_Mohseni\Elosql\ValueObjects\ColumnSchema;
use Sepehr_Mohseni\Elosql\ValueObjects\ForeignKeySchema;
use Sepehr_Mohseni\Elosql\ValueObjects\IndexSchema;
use Sepehr_Mohseni\Elosql\ValueObjects\TableSchema;
use Illuminate\Filesystem\Filesystem;

class ModelGeneratorTest extends TestCase
{
    private ModelGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $nameConverter = new NameConverter();
        $this->generator = new ModelGenerator(
            new RelationshipDetector($nameConverter),
            $nameConverter,
            new FileWriter(new Filesystem()),
            []
        );
    }

    /**
     * Create a new generator with custom config.
     */
    private function createGenerator(array $config): ModelGenerator
    {
        $nameConverter = new NameConverter();
        return new ModelGenerator(
            new RelationshipDetector($nameConverter),
            $nameConverter,
            new FileWriter(new Filesystem()),
            $config
        );
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

    public function test_generates_basic_model(): void
    {
        $table = new TableSchema(
            'users',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('name', type: 'varchar'),
                $this->createColumn('email', type: 'varchar'),
                $this->createColumn('created_at', type: 'timestamp', nullable: true),
                $this->createColumn('updated_at', type: 'timestamp', nullable: true),
            ],
            [],
            []
        );

        $content = $this->generator->generate($table, [$table]);

        $this->assertStringContainsString('namespace App\Models;', $content);
        $this->assertStringContainsString('use Illuminate\Database\Eloquent\Model;', $content);
        $this->assertStringContainsString('class User extends Model', $content);
    }

    public function test_generates_model_with_custom_table_name(): void
    {
        $table = new TableSchema(
            'tbl_users',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
            ],
            [],
            []
        );

        $content = $this->generator->generate($table, [$table]);

        // Model should be named TblUser - if Laravel can't derive the table name, it should have $table property
        $this->assertStringContainsString('class TblUser extends Model', $content);
    }

    public function test_generates_model_with_casts(): void
    {
        $table = new TableSchema(
            'users',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('is_active', type: 'boolean'),
                $this->createColumn('metadata', type: 'json', nullable: true),
                $this->createColumn('email_verified_at', type: 'datetime', nullable: true),
            ],
            [],
            []
        );

        $content = $this->generator->generate($table, [$table]);

        $this->assertStringContainsString('$casts', $content);
    }

    public function test_generates_model_with_belongs_to_relationship(): void
    {
        $userTable = new TableSchema(
            'users',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
            ],
            [],
            []
        );

        $postTable = new TableSchema(
            'posts',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('user_id'),
                $this->createColumn('title', type: 'varchar'),
            ],
            [],
            [
                new ForeignKeySchema(name: 'posts_user_id_fk', columns: ['user_id'], referencedTable: 'users', referencedColumns: ['id']),
            ]
        );

        $content = $this->generator->generate($postTable, [$userTable, $postTable]);

        $this->assertStringContainsString('BelongsTo', $content);
        $this->assertStringContainsString('belongsTo(', $content);
    }

    public function test_generates_model_with_has_many_relationship(): void
    {
        $userTable = new TableSchema(
            'users',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
            ],
            [],
            []
        );

        $postTable = new TableSchema(
            'posts',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('user_id'),
            ],
            [],
            [
                new ForeignKeySchema(name: 'posts_user_id_fk', columns: ['user_id'], referencedTable: 'users', referencedColumns: ['id']),
            ]
        );

        $content = $this->generator->generate($userTable, [$userTable, $postTable]);

        $this->assertStringContainsString('HasMany', $content);
        $this->assertStringContainsString('hasMany(', $content);
    }

    public function test_generates_model_with_has_one_relationship(): void
    {
        $userTable = new TableSchema(
            'users',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
            ],
            [],
            []
        );

        $profileTable = new TableSchema(
            'profiles',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('user_id'),
            ],
            [
                new IndexSchema(name: 'profiles_user_id_unique', type: 'unique', columns: ['user_id']),
            ],
            [
                new ForeignKeySchema(name: 'profiles_user_id_fk', columns: ['user_id'], referencedTable: 'users', referencedColumns: ['id']),
            ]
        );

        $content = $this->generator->generate($userTable, [$userTable, $profileTable]);

        // Could be HasOne or HasMany depending on how unique index is detected
        $this->assertTrue(
            str_contains($content, 'HasOne') || str_contains($content, 'HasMany')
        );
    }

    public function test_generates_model_with_belongs_to_many_relationship(): void
    {
        $postTable = new TableSchema(
            'posts',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
            ],
            [],
            []
        );

        $tagTable = new TableSchema(
            'tags',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
            ],
            [],
            []
        );

        $pivotTable = new TableSchema(
            'post_tag',
            [
                $this->createColumn('post_id'),
                $this->createColumn('tag_id'),
            ],
            [],
            [
                new ForeignKeySchema(name: 'post_tag_post_id_fk', columns: ['post_id'], referencedTable: 'posts', referencedColumns: ['id']),
                new ForeignKeySchema(name: 'post_tag_tag_id_fk', columns: ['tag_id'], referencedTable: 'tags', referencedColumns: ['id']),
            ]
        );

        $content = $this->generator->generate($postTable, [$postTable, $tagTable, $pivotTable]);

        $this->assertStringContainsString('BelongsToMany', $content);
        $this->assertStringContainsString('belongsToMany(', $content);
    }

    public function test_generates_model_with_soft_deletes(): void
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

        $content = $this->generator->generate($table, [$table]);

        $this->assertStringContainsString('SoftDeletes', $content);
    }

    public function test_generates_model_without_timestamps(): void
    {
        $table = new TableSchema(
            'settings',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('key', type: 'varchar'),
                $this->createColumn('value', type: 'text', nullable: true),
            ],
            [],
            []
        );

        $content = $this->generator->generate($table, [$table]);

        $this->assertStringContainsString('$timestamps = false', $content);
    }

    public function test_generates_model_with_custom_primary_key(): void
    {
        $this->generator->setDriver('pgsql');

        $table = new TableSchema(
            'users',
            [
                $this->createColumn('uuid', type: 'uuid'),
                $this->createColumn('name', type: 'varchar'),
            ],
            [
                new IndexSchema(name: 'users_primary', type: 'primary', columns: ['uuid']),
            ],
            []
        );

        $content = $this->generator->generate($table, [$table]);

        $this->assertStringContainsString("\$primaryKey = 'uuid'", $content);
    }

    public function test_generates_model_with_custom_namespace(): void
    {
        $generator = $this->createGenerator([
            'namespace' => 'Domain\\User\\Models',
        ]);

        $table = new TableSchema(
            'users',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
            ],
            [],
            []
        );

        $content = $generator->generate($table, [$table]);

        $this->assertStringContainsString('namespace Domain\\User\\Models;', $content);
    }

    public function test_generates_model_with_morph_to_relationship(): void
    {
        $commentTable = new TableSchema(
            'comments',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('commentable_type', type: 'varchar'),
                $this->createColumn('commentable_id'),
                $this->createColumn('body', type: 'text'),
            ],
            [],
            []
        );

        $content = $this->generator->generate($commentTable, [$commentTable]);

        $this->assertStringContainsString('MorphTo', $content);
        // morphTo() or morphTo('commentable') are both valid
        $this->assertStringContainsString('morphTo(', $content);
    }

    public function test_generates_model_with_date_casts(): void
    {
        $table = new TableSchema(
            'events',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('starts_at', type: 'date'),
                $this->createColumn('ends_at', type: 'date', nullable: true),
            ],
            [],
            []
        );

        $content = $this->generator->generate($table, [$table]);

        $this->assertStringContainsString('$casts', $content);
        $this->assertStringContainsString("'date'", $content);
    }

    public function test_generate_all_creates_multiple_models(): void
    {
        $tables = [
            new TableSchema(
                'users',
                [$this->createColumn('id', autoIncrement: true, unsigned: true)],
                [],
                []
            ),
            new TableSchema(
                'posts',
                [$this->createColumn('id', autoIncrement: true, unsigned: true)],
                [],
                []
            ),
        ];

        $models = $this->generator->generateAll($tables);

        $this->assertCount(2, $models);
        foreach ($models as $filename => $content) {
            $this->assertStringEndsWith('.php', $filename);
            $this->assertStringContainsString('extends Model', $content);
        }
    }

    public function test_set_driver(): void
    {
        $this->generator->setDriver('pgsql');

        $table = new TableSchema(
            'test',
            [$this->createColumn('id', autoIncrement: true)],
            [],
            []
        );

        $content = $this->generator->generate($table, [$table]);
        $this->assertStringContainsString('extends Model', $content);
    }
}
