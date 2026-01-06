<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Tests\Unit;

use Sepehr_Mohseni\Elosql\Support\NameConverter;
use Sepehr_Mohseni\Elosql\Tests\TestCase;

class NameConverterTest extends TestCase
{
    private NameConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new NameConverter();
    }

    /**
     * @dataProvider tableToModelProvider
     */
    public function test_converts_table_name_to_model_name(string $tableName, string $expected): void
    {
        $result = $this->converter->tableToModelName($tableName);

        $this->assertEquals($expected, $result);
    }

    public static function tableToModelProvider(): array
    {
        return [
            'simple plural' => ['users', 'User'],
            'snake_case plural' => ['blog_posts', 'BlogPost'],
            'already singular' => ['person', 'Person'],
            'people irregular' => ['people', 'Person'],
            'categories' => ['categories', 'Category'],
            'statuses with es' => ['statuses', 'Status'],
            'lowercase' => ['products', 'Product'],
            'uppercase' => ['ORDERS', 'Order'],
            'mixed case' => ['Order_Items', 'OrderItem'],
            'with numbers' => ['order_items_v2', 'OrderItemsV2'],
            'children irregular' => ['children', 'Child'],
            'addresses' => ['addresses', 'Address'],
            'data stays as data' => ['meta_data', 'MetaDatum'],
            'pivot table' => ['category_post', 'CategoryPost'],
        ];
    }

    /**
     * @dataProvider modelToTableProvider
     */
    public function test_converts_model_name_to_table_name(string $modelName, string $expected): void
    {
        $result = $this->converter->modelToTableName($modelName);

        $this->assertEquals($expected, $result);
    }

    public static function modelToTableProvider(): array
    {
        return [
            'simple' => ['User', 'users'],
            'camel case' => ['BlogPost', 'blog_posts'],
            'person irregular' => ['Person', 'people'],
            'category' => ['Category', 'categories'],
            'status' => ['Status', 'statuses'],
            'already lower' => ['product', 'products'],
            'child irregular' => ['Child', 'children'],
            'address' => ['Address', 'addresses'],
        ];
    }

    /**
     * @dataProvider columnToPropertyProvider
     */
    public function test_converts_column_name_to_property(string $columnName, string $expected): void
    {
        $result = $this->converter->columnToPropertyName($columnName);

        $this->assertEquals($expected, $result);
    }

    public static function columnToPropertyProvider(): array
    {
        return [
            'snake_case' => ['first_name', 'firstName'],
            'already camel' => ['firstName', 'firstName'],
            'all caps' => ['ID', 'id'],
            'single word' => ['email', 'email'],
            'multiple underscores' => ['some_long_column_name', 'someLongColumnName'],
        ];
    }

    /**
     * @dataProvider foreignKeyToRelationProvider
     */
    public function test_converts_foreign_key_to_relation_name(string $fkColumn, string $expectedMethod): void
    {
        $result = $this->converter->foreignKeyToRelationName($fkColumn);

        $this->assertEquals($expectedMethod, $result);
    }

    public static function foreignKeyToRelationProvider(): array
    {
        return [
            'user_id to user' => ['user_id', 'user'],
            'blog_post_id to blogPost' => ['blog_post_id', 'blogPost'],
            'parent_id to parent' => ['parent_id', 'parent'],
            'author_id to author' => ['author_id', 'author'],
            'category_uuid to category' => ['category_uuid', 'category'],
        ];
    }

    /**
     * @dataProvider tableToRelationProvider
     */
    public function test_derives_has_many_relation_name(string $tableName, string $expected): void
    {
        $result = $this->converter->tableToHasManyRelation($tableName);

        $this->assertEquals($expected, $result);
    }

    public static function tableToRelationProvider(): array
    {
        return [
            'comments' => ['comments', 'comments'],
            'blog_posts' => ['blog_posts', 'blogPosts'],
            'order_items' => ['order_items', 'orderItems'],
            'categories' => ['categories', 'categories'],
        ];
    }

    public function test_derives_has_one_relation_name(): void
    {
        $result = $this->converter->tableToHasOneRelation('profiles');

        $this->assertEquals('profile', $result);
    }

    public function test_converts_pivot_table_to_relation_name(): void
    {
        $result = $this->converter->pivotToRelationName('category_post', 'posts');

        $this->assertEquals('categories', $result);
    }

    public function test_plural_to_singular(): void
    {
        $this->assertEquals('user', $this->converter->pluralize('user', false));
        $this->assertEquals('person', $this->converter->pluralize('people', false));
        $this->assertEquals('category', $this->converter->pluralize('categories', false));
    }

    public function test_singular_to_plural(): void
    {
        $this->assertEquals('users', $this->converter->pluralize('user', true));
        $this->assertEquals('people', $this->converter->pluralize('person', true));
        $this->assertEquals('categories', $this->converter->pluralize('category', true));
    }

    public function test_snake_case_conversion(): void
    {
        $this->assertEquals('blog_post', $this->converter->snakeCase('BlogPost'));
        $this->assertEquals('blog_post', $this->converter->snakeCase('blogPost'));
        $this->assertEquals('user', $this->converter->snakeCase('User'));
    }

    public function test_studly_case_conversion(): void
    {
        $this->assertEquals('BlogPost', $this->converter->studlyCase('blog_post'));
        $this->assertEquals('User', $this->converter->studlyCase('user'));
        $this->assertEquals('OrderItem', $this->converter->studlyCase('order_item'));
    }

    public function test_camel_case_conversion(): void
    {
        $this->assertEquals('blogPost', $this->converter->camelCase('blog_post'));
        $this->assertEquals('user', $this->converter->camelCase('user'));
        $this->assertEquals('orderItem', $this->converter->camelCase('order_item'));
    }

    public function test_is_pivot_table(): void
    {
        $tables = ['users', 'posts', 'categories', 'post_user', 'category_post'];

        $this->assertTrue($this->converter->isPivotTable('post_user', $tables));
        $this->assertTrue($this->converter->isPivotTable('category_post', $tables));
        $this->assertFalse($this->converter->isPivotTable('users', $tables));
        $this->assertFalse($this->converter->isPivotTable('posts', $tables));
    }

    public function test_extracts_pivot_table_relations(): void
    {
        $tables = ['users', 'posts'];

        $result = $this->converter->getPivotRelations('post_user', $tables);

        $this->assertEquals(['post', 'user'], $result);
    }

    public function test_generates_migration_class_name(): void
    {
        $result = $this->converter->toMigrationClassName('users');

        $this->assertEquals('CreateUsersTable', $result);
    }

    public function test_generates_foreign_key_migration_class_name(): void
    {
        $result = $this->converter->toForeignKeyMigrationClassName('posts');

        $this->assertEquals('AddForeignKeysToPostsTable', $result);
    }
}
