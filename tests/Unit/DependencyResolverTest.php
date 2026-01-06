<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Tests\Unit;

use Sepehr_Mohseni\Elosql\Analyzers\DependencyResolver;
use Sepehr_Mohseni\Elosql\Exceptions\SchemaParserException;
use Sepehr_Mohseni\Elosql\Tests\TestCase;
use Sepehr_Mohseni\Elosql\ValueObjects\ColumnSchema;
use Sepehr_Mohseni\Elosql\ValueObjects\ForeignKeySchema;
use Sepehr_Mohseni\Elosql\ValueObjects\TableSchema;

class DependencyResolverTest extends TestCase
{
    private DependencyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new DependencyResolver();
    }

    public function test_resolves_simple_dependency_order(): void
    {
        $tables = [
            $this->createTableWithFK('posts', ['user_id'], ['users']),
            $this->createTable('users'),
        ];

        $ordered = $this->resolver->resolve($tables);

        $tableNames = array_map(fn (TableSchema $t) => $t->name, $ordered);

        $this->assertLessThan(
            array_search('posts', $tableNames),
            array_search('users', $tableNames)
        );
    }

    public function test_resolves_multiple_dependencies(): void
    {
        $tables = [
            $this->createTableWithFK('comments', ['post_id', 'user_id'], ['posts', 'users']),
            $this->createTableWithFK('posts', ['user_id'], ['users']),
            $this->createTable('users'),
        ];

        $ordered = $this->resolver->resolve($tables);

        $tableNames = array_map(fn (TableSchema $t) => $t->name, $ordered);

        // users must come before posts
        $this->assertLessThan(
            array_search('posts', $tableNames),
            array_search('users', $tableNames)
        );

        // posts must come before comments
        $this->assertLessThan(
            array_search('comments', $tableNames),
            array_search('posts', $tableNames)
        );
    }

    public function test_handles_tables_without_dependencies(): void
    {
        $tables = [
            $this->createTable('users'),
            $this->createTable('categories'),
            $this->createTable('tags'),
        ];

        $ordered = $this->resolver->resolve($tables);

        $this->assertCount(3, $ordered);
    }

    public function test_handles_self_referencing_table(): void
    {
        $columns = [
            $this->createColumn('id', 'bigint', true, true),
            $this->createColumn('parent_id', 'bigint', false, false, true),
        ];

        $foreignKeys = [
            new ForeignKeySchema(
                name: 'categories_parent_id_foreign',
                columns: ['parent_id'],
                referencedTable: 'categories',
                referencedColumns: ['id'],
                onDelete: 'CASCADE',
                onUpdate: 'CASCADE'
            ),
        ];

        $table = new TableSchema('categories', $columns, [], $foreignKeys);

        $ordered = $this->resolver->resolve([$table]);

        $this->assertCount(1, $ordered);
        $this->assertEquals('categories', $ordered[0]->name);
    }

    public function test_detects_circular_dependency(): void
    {
        $tableA = $this->createTableWithFK('table_a', ['b_id'], ['table_b']);
        $tableB = $this->createTableWithFK('table_b', ['a_id'], ['table_a']);

        $this->expectException(SchemaParserException::class);
        $this->expectExceptionMessage('Circular');

        $this->resolver->resolve([$tableA, $tableB]);
    }

    public function test_gets_dependency_graph(): void
    {
        $tables = [
            $this->createTableWithFK('posts', ['user_id'], ['users']),
            $this->createTable('users'),
        ];

        $graph = $this->resolver->getDependencyGraph($tables);

        $this->assertArrayHasKey('posts', $graph);
        $this->assertArrayHasKey('users', $graph);
        $this->assertContains('users', $graph['posts']);
        $this->assertEmpty($graph['users']);
    }

    public function test_gets_reverse_dependency_graph(): void
    {
        $tables = [
            $this->createTableWithFK('posts', ['user_id'], ['users']),
            $this->createTable('users'),
        ];

        $graph = $this->resolver->getReverseDependencyGraph($tables);

        $this->assertArrayHasKey('users', $graph);
        $this->assertContains('posts', $graph['users']);
    }

    public function test_identifies_root_tables(): void
    {
        $tables = [
            $this->createTableWithFK('posts', ['user_id'], ['users']),
            $this->createTableWithFK('comments', ['post_id'], ['posts']),
            $this->createTable('users'),
            $this->createTable('tags'),
        ];

        $roots = $this->resolver->getRootTables($tables);

        $this->assertContains('users', $roots);
        $this->assertContains('tags', $roots);
        $this->assertNotContains('posts', $roots);
        $this->assertNotContains('comments', $roots);
    }

    public function test_identifies_leaf_tables(): void
    {
        $tables = [
            $this->createTableWithFK('posts', ['user_id'], ['users']),
            $this->createTableWithFK('comments', ['post_id'], ['posts']),
            $this->createTable('users'),
        ];

        $leaves = $this->resolver->getLeafTables($tables);

        $this->assertContains('comments', $leaves);
        $this->assertNotContains('posts', $leaves);
        $this->assertNotContains('users', $leaves);
    }

    public function test_groups_tables_by_level(): void
    {
        $tables = [
            $this->createTableWithFK('comments', ['post_id'], ['posts']),
            $this->createTableWithFK('posts', ['user_id'], ['users']),
            $this->createTable('users'),
            $this->createTable('tags'),
        ];

        $levels = $this->resolver->groupByLevel($tables);

        // Level 0: users, tags (no dependencies)
        $this->assertContains('users', $levels[0]);
        $this->assertContains('tags', $levels[0]);

        // Level 1: posts (depends on users)
        $this->assertContains('posts', $levels[1]);

        // Level 2: comments (depends on posts)
        $this->assertContains('comments', $levels[2]);
    }

    public function test_detects_many_to_many_pivot_tables(): void
    {
        $pivotTable = new TableSchema(
            'post_tag',
            [
                $this->createColumn('post_id', 'bigint'),
                $this->createColumn('tag_id', 'bigint'),
            ],
            [],
            [
                new ForeignKeySchema(
                    name: 'post_tag_post_id_fk',
                    columns: ['post_id'],
                    referencedTable: 'posts',
                    referencedColumns: ['id']
                ),
                new ForeignKeySchema(
                    name: 'post_tag_tag_id_fk',
                    columns: ['tag_id'],
                    referencedTable: 'tags',
                    referencedColumns: ['id']
                ),
            ]
        );

        $tables = [
            $this->createTable('posts'),
            $this->createTable('tags'),
            $pivotTable,
        ];

        $pivotTables = $this->resolver->getPivotTables($tables);

        $this->assertContains('post_tag', $pivotTables);
    }

    public function test_handles_missing_referenced_table(): void
    {
        // posts references users, but users is not in the list
        $tables = [
            $this->createTableWithFK('posts', ['user_id'], ['users']),
        ];

        // Should not throw, just ignore missing dependencies
        $ordered = $this->resolver->resolve($tables);

        $this->assertCount(1, $ordered);
    }

    /**
     * Create a simple table without foreign keys.
     */
    private function createTable(string $name): TableSchema
    {
        return new TableSchema(
            $name,
            [
                $this->createColumn('id', 'bigint', true, true),
                $this->createColumn('name', 'varchar'),
            ],
            [],
            []
        );
    }

    /**
     * Create a table with foreign keys.
     */
    private function createTableWithFK(string $name, array $fkColumns, array $refTables): TableSchema
    {
        $columns = [
            $this->createColumn('id', 'bigint', true, true),
        ];

        $foreignKeys = [];

        foreach ($fkColumns as $index => $fkColumn) {
            $columns[] = $this->createColumn($fkColumn, 'bigint');
            $foreignKeys[] = new ForeignKeySchema(
                name: "{$name}_{$fkColumn}_foreign",
                columns: [$fkColumn],
                referencedTable: $refTables[$index],
                referencedColumns: ['id'],
                onDelete: 'CASCADE',
                onUpdate: 'CASCADE'
            );
        }

        return new TableSchema($name, $columns, [], $foreignKeys);
    }

    /**
     * Create a column schema with sensible defaults.
     */
    private function createColumn(
        string $name,
        string $type,
        bool $autoIncrement = false,
        bool $unsigned = false,
        bool $nullable = false
    ): ColumnSchema {
        return new ColumnSchema(
            name: $name,
            type: $type,
            nativeType: $type,
            nullable: $nullable,
            default: null,
            autoIncrement: $autoIncrement,
            unsigned: $unsigned,
            length: null,
            precision: null,
            scale: null,
            charset: null,
            collation: null,
            comment: null,
            attributes: $autoIncrement ? ['primary' => true] : []
        );
    }
}
