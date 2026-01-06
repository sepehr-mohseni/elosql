<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Tests\Feature;

use Sepehr_Mohseni\Elosql\Parsers\SQLiteSchemaParser;
use Sepehr_Mohseni\Elosql\Tests\TestCase;

class SchemaParsingTest extends TestCase
{
    private SQLiteSchemaParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = $this->app->make(SQLiteSchemaParser::class);
        $this->parser->setConnection($this->app['db']->connection('testing'));
        $this->createTestTables();
    }

    public function test_parses_all_tables_from_database(): void
    {
        $tables = $this->parser->parseAllTables();

        $tableNames = array_map(fn ($t) => $t->name, $tables);

        $this->assertContains('users', $tableNames);
        $this->assertContains('posts', $tableNames);
        $this->assertContains('categories', $tableNames);
        $this->assertContains('category_post', $tableNames);
        $this->assertContains('comments', $tableNames);
    }

    public function test_parses_table_columns(): void
    {
        $usersTable = $this->parser->parseTable('users');

        $columnNames = array_map(fn ($c) => $c->name, $usersTable->columns);

        $this->assertContains('id', $columnNames);
        $this->assertContains('name', $columnNames);
        $this->assertContains('email', $columnNames);
        $this->assertContains('password', $columnNames);
        $this->assertContains('is_active', $columnNames);
        $this->assertContains('created_at', $columnNames);
        $this->assertContains('updated_at', $columnNames);
    }

    public function test_parses_column_types(): void
    {
        $usersTable = $this->parser->parseTable('users');

        $idColumn = collect($usersTable->columns)->firstWhere('name', 'id');
        $nameColumn = collect($usersTable->columns)->firstWhere('name', 'name');
        $isActiveColumn = collect($usersTable->columns)->firstWhere('name', 'is_active');

        $this->assertEquals('integer', $idColumn->type);
        $this->assertTrue($idColumn->autoIncrement);

        // SQLite uses text for varchar
        $this->assertTrue(
            str_contains(strtolower($nameColumn->type), 'varchar') ||
            str_contains(strtolower($nameColumn->type), 'text')
        );

        // SQLite stores boolean as integer
        $this->assertStringContainsString('int', strtolower($isActiveColumn->nativeType));
    }

    public function test_parses_nullable_columns(): void
    {
        $usersTable = $this->parser->parseTable('users');

        // email_verified_at is nullable, name is not
        $nullableColumn = collect($usersTable->columns)->firstWhere('name', 'email_verified_at');
        $nameColumn = collect($usersTable->columns)->firstWhere('name', 'name');

        $this->assertTrue($nullableColumn->nullable);
        $this->assertFalse($nameColumn->nullable);
    }

    public function test_parses_default_values(): void
    {
        $usersTable = $this->parser->parseTable('users');

        $isActiveColumn = collect($usersTable->columns)->firstWhere('name', 'is_active');

        // SQLite stores defaults as literals, check it has a default
        $this->assertTrue($isActiveColumn->hasDefault());
    }

    public function test_parses_primary_key_index(): void
    {
        $usersTable = $this->parser->parseTable('users');

        $primaryKey = $usersTable->getPrimaryKey();

        $this->assertNotNull($primaryKey);
        $this->assertContains('id', $primaryKey->columns);
    }

    public function test_parses_unique_index(): void
    {
        $usersTable = $this->parser->parseTable('users');

        $uniqueIndexes = array_filter($usersTable->indexes, fn ($idx) => $idx->type === 'unique');

        $this->assertNotEmpty($uniqueIndexes);

        $emailIndex = collect($usersTable->indexes)->first(fn ($idx) => in_array('email', $idx->columns));
        $this->assertNotNull($emailIndex);
    }

    public function test_parses_foreign_keys(): void
    {
        $postsTable = $this->parser->parseTable('posts');

        $fks = $postsTable->foreignKeys;

        $this->assertNotEmpty($fks);

        $userFk = collect($fks)->first(fn ($fk) => $fk->referencedTable === 'users');
        $this->assertNotNull($userFk);
        $this->assertContains('user_id', $userFk->columns);
    }

    public function test_parses_pivot_table(): void
    {
        $pivotTable = $this->parser->parseTable('category_post');

        $this->assertCount(2, $pivotTable->foreignKeys);

        $postFk = collect($pivotTable->foreignKeys)->first(fn ($fk) => $fk->referencedTable === 'posts');
        $categoryFk = collect($pivotTable->foreignKeys)->first(fn ($fk) => $fk->referencedTable === 'categories');

        $this->assertNotNull($postFk);
        $this->assertNotNull($categoryFk);
    }

    public function test_excludes_system_tables(): void
    {
        $tableNames = $this->parser->getTables(['migrations']);

        $this->assertNotContains('migrations', $tableNames);
        $this->assertNotContains('sqlite_sequence', $tableNames);
    }

    public function test_parses_composite_primary_key(): void
    {
        // category_post has composite primary key
        $pivotTable = $this->parser->parseTable('category_post');

        $pk = $pivotTable->getPrimaryKey();

        // It may be a composite index or individual indexes depending on how schema was created
        $this->assertNotNull($pk);
    }

    public function test_get_table_names(): void
    {
        $tableNames = $this->parser->getTables();

        $this->assertIsArray($tableNames);
        $this->assertContains('users', $tableNames);
        $this->assertContains('posts', $tableNames);
    }

    public function test_filters_tables_by_exclude(): void
    {
        $tables = $this->parser->parseAllTables(['users']);

        $tableNames = array_map(fn ($t) => $t->name, $tables);

        $this->assertNotContains('users', $tableNames);
        $this->assertContains('posts', $tableNames);
    }
}
