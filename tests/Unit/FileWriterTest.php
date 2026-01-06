<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Tests\Unit;

use Sepehr_Mohseni\Elosql\Support\FileWriter;
use Sepehr_Mohseni\Elosql\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

class FileWriterTest extends TestCase
{
    private FileWriter $writer;
    private Filesystem $filesystem;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem();
        $this->writer = new FileWriter($this->filesystem);
        $this->tempDir = sys_get_temp_dir() . '/elosql_test_' . uniqid();
        $this->filesystem->makeDirectory($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    public function test_writes_file_content(): void
    {
        $path = $this->tempDir . '/test.php';
        $content = "<?php\n\necho 'Hello World';";

        $this->writer->write($path, $content);

        $this->assertFileExists($path);
        $this->assertEquals($content, file_get_contents($path));
    }

    public function test_creates_directory_if_not_exists(): void
    {
        $path = $this->tempDir . '/nested/dir/test.php';
        $content = "<?php\n\necho 'Hello';";

        $this->writer->write($path, $content);

        $this->assertFileExists($path);
        $this->assertDirectoryExists($this->tempDir . '/nested/dir');
    }

    public function test_does_not_overwrite_by_default(): void
    {
        $path = $this->tempDir . '/existing.php';
        $originalContent = "<?php\n\n// original";
        $newContent = "<?php\n\n// new";

        file_put_contents($path, $originalContent);

        $result = $this->writer->write($path, $newContent, overwrite: false);

        $this->assertFalse($result);
        $this->assertEquals($originalContent, file_get_contents($path));
    }

    public function test_overwrites_when_flag_is_true(): void
    {
        $path = $this->tempDir . '/existing.php';
        $originalContent = "<?php\n\n// original";
        $newContent = "<?php\n\n// new";

        file_put_contents($path, $originalContent);

        $result = $this->writer->write($path, $newContent, overwrite: true);

        $this->assertTrue($result);
        $this->assertEquals($newContent, file_get_contents($path));
    }

    public function test_generates_migration_filename(): void
    {
        $result = $this->writer->generateMigrationFilename('CreateUsersTable');

        $this->assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_create_users_table\.php$/', $result);
    }

    public function test_generates_unique_migration_filenames(): void
    {
        // Generate filenames with different explicit timestamps to ensure uniqueness
        $baseTimestamp = time();
        $filenames = [];
        for ($i = 0; $i < 5; $i++) {
            $filenames[] = $this->writer->generateMigrationFilename('CreateUsersTable', $baseTimestamp + $i);
        }

        $uniqueFilenames = array_unique($filenames);
        $this->assertCount(5, $uniqueFilenames);
    }

    public function test_generates_sequential_migration_filenames(): void
    {
        $filenames = $this->writer->generateSequentialMigrationFilenames([
            'CreateUsersTable',
            'CreatePostsTable',
            'AddForeignKeysToPostsTable',
        ]);

        // Check timestamps are sequential
        preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})/', $filenames[0], $matches1);
        preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})/', $filenames[1], $matches2);
        preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})/', $filenames[2], $matches3);

        $this->assertLessThan($matches2[1], $matches1[1]);
        $this->assertLessThan($matches3[1], $matches2[1]);

        // Check filenames contain correct class names
        $this->assertStringContainsString('create_users_table', $filenames[0]);
        $this->assertStringContainsString('create_posts_table', $filenames[1]);
        $this->assertStringContainsString('add_foreign_keys_to_posts_table', $filenames[2]);
    }

    public function test_writes_multiple_files(): void
    {
        $files = [
            $this->tempDir . '/file1.php' => "<?php\n// file 1",
            $this->tempDir . '/file2.php' => "<?php\n// file 2",
            $this->tempDir . '/file3.php' => "<?php\n// file 3",
        ];

        $results = $this->writer->writeMultiple($files);

        $this->assertCount(3, $results['written']);
        $this->assertEmpty($results['skipped']);

        foreach ($files as $path => $content) {
            $this->assertFileExists($path);
            $this->assertEquals($content, file_get_contents($path));
        }
    }

    public function test_writes_multiple_files_reports_skipped(): void
    {
        $existingPath = $this->tempDir . '/existing.php';
        file_put_contents($existingPath, '<?php // existing');

        $files = [
            $this->tempDir . '/new.php' => "<?php // new",
            $existingPath => "<?php // should be skipped",
        ];

        $results = $this->writer->writeMultiple($files, overwrite: false);

        $this->assertCount(1, $results['written']);
        $this->assertCount(1, $results['skipped']);
        $this->assertContains($existingPath, $results['skipped']);
    }

    public function test_generates_model_filename(): void
    {
        $result = $this->writer->generateModelFilename('User');

        $this->assertEquals('User.php', $result);
    }

    public function test_generates_model_filename_with_namespace(): void
    {
        $result = $this->writer->generateModelPath('User', 'App\\Models');

        $this->assertStringEndsWith('app/Models/User.php', str_replace('\\', '/', $result));
    }

    public function test_backup_creates_copy_before_overwrite(): void
    {
        $path = $this->tempDir . '/backup_test.php';
        $originalContent = "<?php\n// original";
        $newContent = "<?php\n// new";

        file_put_contents($path, $originalContent);

        $this->writer->write($path, $newContent, overwrite: true, backup: true);

        // Check backup exists
        $backupPath = $path . '.bak';
        $this->assertFileExists($backupPath);
        $this->assertEquals($originalContent, file_get_contents($backupPath));
        $this->assertEquals($newContent, file_get_contents($path));
    }

    public function test_exists_checks_file_presence(): void
    {
        $existingPath = $this->tempDir . '/exists.php';
        file_put_contents($existingPath, '<?php');

        $this->assertTrue($this->writer->exists($existingPath));
        $this->assertFalse($this->writer->exists($this->tempDir . '/not_exists.php'));
    }

    public function test_delete_removes_file(): void
    {
        $path = $this->tempDir . '/to_delete.php';
        file_put_contents($path, '<?php');

        $this->assertTrue($this->writer->delete($path));
        $this->assertFileDoesNotExist($path);
    }

    public function test_delete_returns_false_for_nonexistent_file(): void
    {
        $this->assertFalse($this->writer->delete($this->tempDir . '/nonexistent.php'));
    }
}
