<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Tests\Unit;

use Sepehr_Mohseni\Elosql\Analyzers\SchemaComparator;
use Sepehr_Mohseni\Elosql\Tests\TestCase;
use Sepehr_Mohseni\Elosql\ValueObjects\ColumnSchema;
use Sepehr_Mohseni\Elosql\ValueObjects\ForeignKeySchema;
use Sepehr_Mohseni\Elosql\ValueObjects\IndexSchema;
use Sepehr_Mohseni\Elosql\ValueObjects\TableSchema;

class SchemaComparatorTest extends TestCase
{
    private SchemaComparator $comparator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->comparator = new SchemaComparator();
    }

    public function test_detects_new_table(): void
    {
        $current = [];
        $target = [
            new TableSchema('users', [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
            ], [], []),
        ];

        $diff = $this->comparator->compare($current, $target);

        $this->assertCount(1, $diff['created']);
        $this->assertEquals('users', $diff['created'][0]->name);
        $this->assertEmpty($diff['dropped']);
        $this->assertEmpty($diff['modified']);
    }

    public function test_detects_dropped_table(): void
    {
        $current = [
            new TableSchema('users', [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
            ], [], []),
        ];
        $target = [];

        $diff = $this->comparator->compare($current, $target);

        $this->assertEmpty($diff['created']);
        $this->assertCount(1, $diff['dropped']);
        $this->assertEquals('users', $diff['dropped'][0]->name);
    }

    public function test_detects_added_column(): void
    {
        $current = [
            new TableSchema('users', [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
            ], [], []),
        ];

        $target = [
            new TableSchema('users', [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('email', type: 'varchar', length: 255),
            ], [], []),
        ];

        $diff = $this->comparator->compare($current, $target);

        $this->assertCount(1, $diff['modified']);
        $modification = $diff['modified'][0];
        $this->assertEquals('users', $modification['table']);
        $this->assertCount(1, $modification['columns']['added']);
        $this->assertEquals('email', $modification['columns']['added'][0]->name);
    }

    public function test_detects_dropped_column(): void
    {
        $current = [
            new TableSchema('users', [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('legacy_field', type: 'varchar', nullable: true),
            ], [], []),
        ];

        $target = [
            new TableSchema('users', [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
            ], [], []),
        ];

        $diff = $this->comparator->compare($current, $target);

        $this->assertCount(1, $diff['modified']);
        $modification = $diff['modified'][0];
        $this->assertCount(1, $modification['columns']['dropped']);
        $this->assertEquals('legacy_field', $modification['columns']['dropped'][0]->name);
    }

    public function test_detects_modified_column_type(): void
    {
        $current = [
            new TableSchema('users', [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('status', type: 'varchar', length: 255),
            ], [], []),
        ];

        $target = [
            new TableSchema('users', [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('status', type: 'enum'),
            ], [], []),
        ];

        $diff = $this->comparator->compare($current, $target);

        $this->assertCount(1, $diff['modified']);
        $modification = $diff['modified'][0];
        $this->assertCount(1, $modification['columns']['modified']);
        $this->assertEquals('status', $modification['columns']['modified'][0]['column']->name);
        $this->assertArrayHasKey('type', $modification['columns']['modified'][0]['changes']);
    }

    public function test_detects_modified_column_nullable(): void
    {
        $current = [
            new TableSchema('users', [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('phone', type: 'varchar', nullable: false),
            ], [], []),
        ];

        $target = [
            new TableSchema('users', [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('phone', type: 'varchar', nullable: true),
            ], [], []),
        ];

        $diff = $this->comparator->compare($current, $target);

        $this->assertCount(1, $diff['modified']);
        $modification = $diff['modified'][0];
        $this->assertCount(1, $modification['columns']['modified']);
        $this->assertArrayHasKey('nullable', $modification['columns']['modified'][0]['changes']);
    }

    public function test_detects_added_index(): void
    {
        $current = [
            new TableSchema('users', [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('email', type: 'varchar'),
            ], [], []),
        ];

        $target = [
            new TableSchema('users', [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('email', type: 'varchar'),
            ], [
                new IndexSchema('users_email_unique', 'unique', ['email']),
            ], []),
        ];

        $diff = $this->comparator->compare($current, $target);

        $this->assertCount(1, $diff['modified']);
        $modification = $diff['modified'][0];
        $this->assertCount(1, $modification['indexes']['added']);
        $this->assertEquals('users_email_unique', $modification['indexes']['added'][0]->name);
    }

    public function test_detects_dropped_index(): void
    {
        $current = [
            new TableSchema('users', [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('email', type: 'varchar'),
            ], [
                new IndexSchema('users_email_index', 'index', ['email']),
            ], []),
        ];

        $target = [
            new TableSchema('users', [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('email', type: 'varchar'),
            ], [], []),
        ];

        $diff = $this->comparator->compare($current, $target);

        $this->assertCount(1, $diff['modified']);
        $modification = $diff['modified'][0];
        $this->assertCount(1, $modification['indexes']['dropped']);
    }

    public function test_detects_added_foreign_key(): void
    {
        $current = [
            new TableSchema('posts', [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('user_id'),
            ], [], []),
        ];

        $target = [
            new TableSchema('posts', [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('user_id'),
            ], [], [
                new ForeignKeySchema('posts_user_id_fk', columns: ['user_id'], referencedTable: 'users', referencedColumns: ['id']),
            ]),
        ];

        $diff = $this->comparator->compare($current, $target);

        $this->assertCount(1, $diff['modified']);
        $modification = $diff['modified'][0];
        $this->assertCount(1, $modification['foreign_keys']['added']);
    }

    public function test_detects_dropped_foreign_key(): void
    {
        $current = [
            new TableSchema('posts', [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('user_id'),
            ], [], [
                new ForeignKeySchema('posts_user_id_fk', columns: ['user_id'], referencedTable: 'users', referencedColumns: ['id']),
            ]),
        ];

        $target = [
            new TableSchema('posts', [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('user_id'),
            ], [], []),
        ];

        $diff = $this->comparator->compare($current, $target);

        $this->assertCount(1, $diff['modified']);
        $modification = $diff['modified'][0];
        $this->assertCount(1, $modification['foreign_keys']['dropped']);
    }

    public function test_returns_empty_diff_for_identical_schemas(): void
    {
        $schema = [
            new TableSchema('users', [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('email', type: 'varchar'),
            ], [
                new IndexSchema('users_email_unique', 'unique', ['email']),
            ], []),
        ];

        $diff = $this->comparator->compare($schema, $schema);

        $this->assertEmpty($diff['created']);
        $this->assertEmpty($diff['dropped']);
        $this->assertEmpty($diff['modified']);
    }

    public function test_has_changes_returns_correct_boolean(): void
    {
        $current = [];
        $target = [
            new TableSchema('users', [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
            ], [], []),
        ];

        $this->assertTrue($this->comparator->hasChanges($current, $target));
    }

    public function test_has_changes_returns_false_for_no_changes(): void
    {
        $schema = [
            new TableSchema('users', [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
            ], [], []),
        ];

        $this->assertFalse($this->comparator->hasChanges($schema, $schema));
    }

    public function test_generates_diff_summary(): void
    {
        $current = [];
        $target = [
            new TableSchema('users', [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
            ], [], []),
            new TableSchema('posts', [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
            ], [], []),
        ];

        $summary = $this->comparator->getDiffSummary($current, $target);

        $this->assertEquals(2, $summary['created_tables']);
        $this->assertContains('users', $summary['created_table_names']);
        $this->assertContains('posts', $summary['created_table_names']);
    }

    public function test_detects_column_default_change(): void
    {
        $current = [
            new TableSchema('settings', [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('is_active', type: 'boolean', default: false),
            ], [], []),
        ];

        $target = [
            new TableSchema('settings', [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('is_active', type: 'boolean', default: true),
            ], [], []),
        ];

        $diff = $this->comparator->compare($current, $target);

        $this->assertCount(1, $diff['modified']);
        $modification = $diff['modified'][0];
        $this->assertCount(1, $modification['columns']['modified']);
        $this->assertArrayHasKey('default', $modification['columns']['modified'][0]['changes']);
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
}
