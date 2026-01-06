<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Sepehr_Mohseni\Elosql\Tests\TestCase;

class CommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestTables();
    }

    public function test_preview_schema_command_outputs_json(): void
    {
        $exitCode = Artisan::call('elosql:preview', [
            '--json' => true,
        ]);

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
    }

    public function test_preview_schema_command_outputs_table(): void
    {
        // Use --json to avoid interactive prompts
        $exitCode = Artisan::call('elosql:preview', [
            '--json' => true,
        ]);

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        $decoded = json_decode($output, true);

        // Check tables are included in output
        $tableNames = array_map(fn ($t) => $t['name'], $decoded['tables'] ?? []);
        $this->assertContains('users', $tableNames);
        $this->assertContains('posts', $tableNames);
    }

    public function test_preview_schema_with_table_filter(): void
    {
        $exitCode = Artisan::call('elosql:preview', [
            '--tables' => 'users',
            '--json' => true,
        ]);

        $this->assertEquals(0, $exitCode);
    }

    public function test_generate_migrations_preview(): void
    {
        $exitCode = Artisan::call('elosql:migrations', [
            '--preview' => true,
        ]);

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('Schema::create', $output);
    }

    public function test_generate_migrations_command_with_table_filter(): void
    {
        $exitCode = Artisan::call('elosql:migrations', [
            '--preview' => true,
            '--tables' => 'users',
        ]);

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('users', $output);
    }

    public function test_generate_models_preview(): void
    {
        $exitCode = Artisan::call('elosql:models', [
            '--preview' => true,
        ]);

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('extends Model', $output);
    }

    public function test_generate_models_command_with_table_filter(): void
    {
        // --table option is repeatable, use array syntax
        $exitCode = Artisan::call('elosql:models', [
            '--preview' => true,
            '--table' => ['users'],
        ]);

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('class User', $output);
    }

    public function test_commands_are_registered(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('elosql:schema', $commands);
        $this->assertArrayHasKey('elosql:migrations', $commands);
        $this->assertArrayHasKey('elosql:models', $commands);
        $this->assertArrayHasKey('elosql:preview', $commands);
        $this->assertArrayHasKey('elosql:diff', $commands);
    }
}
