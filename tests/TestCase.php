<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Sepehr_Mohseni\Elosql\ElosqlServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ElosqlServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Elosql config
        $app['config']->set('elosql.connection', 'testing');
        $app['config']->set('elosql.exclude_tables', ['migrations']);
    }

    /**
     * Create test tables in the database.
     */
    protected function createTestTables(): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        $this->app['db']->connection()->getSchemaBuilder()->create('posts', function ($table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('content');
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->softDeletes();
        });

        $this->app['db']->connection()->getSchemaBuilder()->create('categories', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        $this->app['db']->connection()->getSchemaBuilder()->create('category_post', function ($table) {
            $table->foreignId('category_id')->constrained()->onDelete('cascade');
            $table->foreignId('post_id')->constrained()->onDelete('cascade');
            $table->primary(['category_id', 'post_id']);
        });

        $this->app['db']->connection()->getSchemaBuilder()->create('comments', function ($table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->text('body');
            $table->boolean('is_approved')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Drop all test tables.
     */
    protected function dropTestTables(): void
    {
        $schema = $this->app['db']->connection()->getSchemaBuilder();

        // Disable foreign key checks for SQLite
        $this->app['db']->connection()->statement('PRAGMA foreign_keys = OFF');

        $schema->dropIfExists('comments');
        $schema->dropIfExists('category_post');
        $schema->dropIfExists('categories');
        $schema->dropIfExists('posts');
        $schema->dropIfExists('users');

        $this->app['db']->connection()->statement('PRAGMA foreign_keys = ON');
    }
}
