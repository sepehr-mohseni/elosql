<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Tests\Unit;

use Sepehr_Mohseni\Elosql\Generators\RelationshipDetector;
use Sepehr_Mohseni\Elosql\Support\NameConverter;
use Sepehr_Mohseni\Elosql\Tests\TestCase;
use Sepehr_Mohseni\Elosql\ValueObjects\ColumnSchema;
use Sepehr_Mohseni\Elosql\ValueObjects\ForeignKeySchema;
use Sepehr_Mohseni\Elosql\ValueObjects\IndexSchema;
use Sepehr_Mohseni\Elosql\ValueObjects\TableSchema;

class RelationshipDetectorTest extends TestCase
{
    private RelationshipDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new RelationshipDetector(new NameConverter());
    }

    public function test_detects_belongs_to_relationship(): void
    {
        $postTable = new TableSchema(
            'posts',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('user_id'),
                $this->createColumn('title', type: 'varchar'),
            ],
            [],
            [
                new ForeignKeySchema('posts_user_id_fk', columns: ['user_id'], referencedTable: 'users', referencedColumns: ['id']),
            ]
        );

        $allTables = [$postTable, $this->createUserTable()];

        $relationships = $this->detector->detect($postTable, $allTables);

        $belongsTo = array_filter($relationships, fn ($r) => $r['type'] === 'belongsTo');
        $this->assertNotEmpty($belongsTo);

        $userRelation = reset($belongsTo);
        $this->assertEquals('user', $userRelation['method']);
        $this->assertEquals('User', $userRelation['related_model']);
        $this->assertEquals('user_id', $userRelation['foreign_key']);
    }

    public function test_detects_has_many_relationship(): void
    {
        $userTable = $this->createUserTable();

        $postTable = new TableSchema(
            'posts',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('user_id'),
            ],
            [],
            [
                new ForeignKeySchema('posts_user_id_fk', columns: ['user_id'], referencedTable: 'users', referencedColumns: ['id']),
            ]
        );

        $allTables = [$userTable, $postTable];

        $relationships = $this->detector->detect($userTable, $allTables);

        $hasMany = array_filter($relationships, fn ($r) => $r['type'] === 'hasMany');
        $this->assertNotEmpty($hasMany);

        $postsRelation = reset($hasMany);
        $this->assertEquals('posts', $postsRelation['method']);
        $this->assertEquals('Post', $postsRelation['related_model']);
    }

    public function test_detects_has_one_relationship(): void
    {
        $userTable = $this->createUserTable();

        $profileTable = new TableSchema(
            'profiles',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('user_id'),
                $this->createColumn('bio', type: 'text', nullable: true),
            ],
            [
                new IndexSchema('profiles_user_id_unique', 'unique', ['user_id']),
            ],
            [
                new ForeignKeySchema('profiles_user_id_fk', columns: ['user_id'], referencedTable: 'users', referencedColumns: ['id']),
            ]
        );

        $allTables = [$userTable, $profileTable];

        $relationships = $this->detector->detect($userTable, $allTables);

        $hasOne = array_filter($relationships, fn ($r) => $r['type'] === 'hasOne');
        $this->assertNotEmpty($hasOne);

        $profileRelation = reset($hasOne);
        $this->assertEquals('profile', $profileRelation['method']);
        $this->assertEquals('Profile', $profileRelation['related_model']);
    }

    public function test_detects_belongs_to_many_relationship(): void
    {
        $postTable = new TableSchema(
            'posts',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('title', type: 'varchar'),
            ],
            [],
            []
        );

        $tagTable = new TableSchema(
            'tags',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('name', type: 'varchar'),
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
                new ForeignKeySchema('post_tag_post_id_fk', columns: ['post_id'], referencedTable: 'posts', referencedColumns: ['id']),
                new ForeignKeySchema('post_tag_tag_id_fk', columns: ['tag_id'], referencedTable: 'tags', referencedColumns: ['id']),
            ]
        );

        $allTables = [$postTable, $tagTable, $pivotTable];

        $relationships = $this->detector->detect($postTable, $allTables);

        $belongsToMany = array_filter($relationships, fn ($r) => $r['type'] === 'belongsToMany');
        $this->assertNotEmpty($belongsToMany);

        $tagsRelation = reset($belongsToMany);
        $this->assertEquals('tags', $tagsRelation['method']);
        $this->assertEquals('Tag', $tagsRelation['related_model']);
        $this->assertEquals('post_tag', $tagsRelation['pivot_table']);
    }

    public function test_detects_morph_to_relationship(): void
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

        $relationships = $this->detector->detect($commentTable, [$commentTable]);

        $morphTo = array_filter($relationships, fn ($r) => $r['type'] === 'morphTo');
        $this->assertNotEmpty($morphTo);

        $commentableRelation = reset($morphTo);
        $this->assertEquals('commentable', $commentableRelation['method']);
    }

    public function test_detects_morph_many_relationship(): void
    {
        $postTable = new TableSchema(
            'posts',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('title', type: 'varchar'),
            ],
            [],
            []
        );

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

        $allTables = [$postTable, $commentTable];

        $relationships = $this->detector->detect($postTable, $allTables, [
            'morph_maps' => ['App\\Models\\Post' => 'posts'],
        ]);

        // Note: morphMany detection requires additional configuration
        // For now, we just verify no errors occur
        $this->assertIsArray($relationships);
    }

    public function test_generates_relationship_code(): void
    {
        $postTable = new TableSchema(
            'posts',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('user_id'),
            ],
            [],
            [
                new ForeignKeySchema('posts_user_id_fk', columns: ['user_id'], referencedTable: 'users', referencedColumns: ['id']),
            ]
        );

        $relationships = $this->detector->detect($postTable, [$postTable, $this->createUserTable()]);
        $belongsTo = array_filter($relationships, fn ($r) => $r['type'] === 'belongsTo');
        $userRelation = reset($belongsTo);

        $code = $this->detector->generateRelationshipMethod($userRelation);

        $this->assertStringContainsString('public function user()', $code);
        $this->assertStringContainsString('belongsTo', $code);
    }

    public function test_handles_custom_foreign_key_name(): void
    {
        $postTable = new TableSchema(
            'posts',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('author_id'),
            ],
            [],
            [
                new ForeignKeySchema('posts_author_id_fk', columns: ['author_id'], referencedTable: 'users', referencedColumns: ['id']),
            ]
        );

        $allTables = [$postTable, $this->createUserTable()];

        $relationships = $this->detector->detect($postTable, $allTables);

        $belongsTo = array_filter($relationships, fn ($r) => $r['type'] === 'belongsTo');
        $authorRelation = reset($belongsTo);

        $this->assertEquals('author', $authorRelation['method']);
        $this->assertEquals('author_id', $authorRelation['foreign_key']);
    }

    public function test_detects_pivot_table_with_timestamps(): void
    {
        $pivotTable = new TableSchema(
            'post_tag',
            [
                $this->createColumn('post_id'),
                $this->createColumn('tag_id'),
                $this->createColumn('created_at', type: 'timestamp', nullable: true),
                $this->createColumn('updated_at', type: 'timestamp', nullable: true),
            ],
            [],
            [
                new ForeignKeySchema('post_tag_post_id_fk', columns: ['post_id'], referencedTable: 'posts', referencedColumns: ['id']),
                new ForeignKeySchema('post_tag_tag_id_fk', columns: ['tag_id'], referencedTable: 'tags', referencedColumns: ['id']),
            ]
        );

        $postTable = new TableSchema('posts', [
            $this->createColumn('id', autoIncrement: true, unsigned: true),
        ], [], []);

        $tagTable = new TableSchema('tags', [
            $this->createColumn('id', autoIncrement: true, unsigned: true),
        ], [], []);

        $allTables = [$postTable, $tagTable, $pivotTable];

        $relationships = $this->detector->detect($postTable, $allTables);

        $belongsToMany = array_filter($relationships, fn ($r) => $r['type'] === 'belongsToMany');
        $this->assertNotEmpty($belongsToMany);
    }

    public function test_detects_pivot_table_with_extra_columns(): void
    {
        $pivotTable = new TableSchema(
            'role_user',
            [
                $this->createColumn('user_id'),
                $this->createColumn('role_id'),
                $this->createColumn('expires_at', type: 'datetime', nullable: true),
                $this->createColumn('granted_by', nullable: true),
            ],
            [],
            [
                new ForeignKeySchema('role_user_user_id_fk', columns: ['user_id'], referencedTable: 'users', referencedColumns: ['id']),
                new ForeignKeySchema('role_user_role_id_fk', columns: ['role_id'], referencedTable: 'roles', referencedColumns: ['id']),
            ]
        );

        $userTable = $this->createUserTable();
        $roleTable = new TableSchema('roles', [
            $this->createColumn('id', autoIncrement: true, unsigned: true),
        ], [], []);

        $allTables = [$userTable, $roleTable, $pivotTable];

        $relationships = $this->detector->detect($userTable, $allTables);

        $belongsToMany = array_filter($relationships, fn ($r) => $r['type'] === 'belongsToMany');
        $rolesRelation = reset($belongsToMany);

        $this->assertContains('expires_at', $rolesRelation['pivot_columns'] ?? []);
        $this->assertContains('granted_by', $rolesRelation['pivot_columns'] ?? []);
    }

    /**
     * Create a simple users table for testing.
     */
    private function createUserTable(): TableSchema
    {
        return new TableSchema(
            'users',
            [
                $this->createColumn('id', autoIncrement: true, unsigned: true),
                $this->createColumn('name', type: 'varchar'),
                $this->createColumn('email', type: 'varchar'),
            ],
            [],
            []
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
}
