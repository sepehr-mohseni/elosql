<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Support;

use Sepehr_Mohseni\Elosql\Exceptions\GeneratorException;
use Illuminate\Filesystem\Filesystem;

class FileWriter
{
    /**
     * @param array<string, mixed> $formatting
     */
    public function __construct(
        protected Filesystem $files,
        protected array $formatting = [],
    ) {}

    /**
     * Write content to a file.
     *
     * @throws GeneratorException
     */
    public function write(string $path, string $content, bool $overwrite = false, bool $backup = false): bool
    {
        $directory = dirname($path);

        if (!$this->files->isDirectory($directory)) {
            if (!$this->files->makeDirectory($directory, 0755, true)) {
                throw GeneratorException::directoryCreateFailed($directory);
            }
        }

        if ($this->files->exists($path)) {
            if (!$overwrite) {
                return false;
            }

            if ($backup) {
                $this->files->copy($path, $path . '.bak');
            }
        }

        $result = $this->files->put($path, $content);

        if ($result === false) {
            throw GeneratorException::fileWriteFailed($path, 'Unable to write file');
        }

        return true;
    }

    /**
     * Check if a file exists.
     */
    public function exists(string $path): bool
    {
        return $this->files->exists($path);
    }

    /**
     * Read file contents.
     */
    public function read(string $path): string
    {
        return $this->files->get($path);
    }

    /**
     * Get all files in a directory matching a pattern.
     *
     * @return array<string>
     */
    public function glob(string $pattern): array
    {
        return $this->files->glob($pattern);
    }

    /**
     * Format generated code.
     */
    public function formatCode(string $code): string
    {
        $indent = $this->formatting['indent'] ?? '    ';
        $sortImports = $this->formatting['sort_imports'] ?? true;

        // Normalize line endings
        $code = str_replace(["\r\n", "\r"], "\n", $code);

        // Sort imports if enabled
        if ($sortImports) {
            $code = $this->sortImports($code);
        }

        // Normalize indentation
        $code = $this->normalizeIndentation($code, $indent);

        // Remove trailing whitespace
        $code = preg_replace('/[ \t]+$/m', '', $code);

        // Ensure single newline at end
        $code = rtrim($code) . "\n";

        return $code;
    }

    /**
     * Sort use statements alphabetically.
     */
    protected function sortImports(string $code): string
    {
        // Find the use statement block
        if (!preg_match('/^(.*?)((?:use [^;]+;\n)+)(.*)/s', $code, $matches)) {
            return $code;
        }

        $before = $matches[1];
        $uses = $matches[2];
        $after = $matches[3];

        // Extract and sort use statements
        preg_match_all('/use ([^;]+);/', $uses, $useMatches);
        $statements = $useMatches[1];
        sort($statements);

        // Rebuild use block
        $sortedUses = array_map(fn ($use) => "use {$use};", $statements);

        return $before . implode("\n", $sortedUses) . "\n" . $after;
    }

    /**
     * Normalize indentation to configured style.
     */
    protected function normalizeIndentation(string $code, string $indent): string
    {
        // Replace tabs with spaces or vice versa based on config
        if ($indent === "\t") {
            return preg_replace_callback('/^( {4})+/m', fn ($m) => str_repeat("\t", (int) (strlen($m[0]) / 4)), $code);
        }

        return preg_replace_callback('/^\t+/m', fn ($m) => str_repeat($indent, strlen($m[0])), $code);
    }

    /**
     * Generate a timestamped filename for migrations.
     */
    public function generateMigrationFilename(string $name, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $date = date('Y_m_d_His', $timestamp);
        $snakeName = $this->toSnakeCase($name);

        return "{$date}_{$snakeName}.php";
    }

    /**
     * Generate sequential migration filenames with incrementing timestamps.
     *
     * @param array<string> $names
     * @return array<string>
     */
    public function generateSequentialMigrationFilenames(array $names): array
    {
        $timestamp = time();
        $filenames = [];

        foreach ($names as $name) {
            $filenames[] = $this->generateMigrationFilename($name, $timestamp);
            $timestamp++;
        }

        return $filenames;
    }

    /**
     * Write multiple files at once.
     *
     * @param array<string, string> $files Map of path => content
     * @param bool $overwrite
     * @return array{written: array<string>, skipped: array<string>}
     */
    public function writeMultiple(array $files, bool $overwrite = true): array
    {
        $results = [
            'written' => [],
            'skipped' => [],
        ];

        foreach ($files as $path => $content) {
            if ($this->write($path, $content, $overwrite)) {
                $results['written'][] = $path;
            } else {
                $results['skipped'][] = $path;
            }
        }

        return $results;
    }

    /**
     * Generate model filename.
     */
    public function generateModelFilename(string $modelName): string
    {
        return $modelName . '.php';
    }

    /**
     * Generate full model path from model name and namespace.
     */
    public function generateModelPath(string $modelName, string $namespace): string
    {
        $namespacePath = str_replace('\\', '/', $namespace);
        $namespacePath = lcfirst($namespacePath);

        return $namespacePath . '/' . $this->generateModelFilename($modelName);
    }

    /**
     * Delete a file.
     */
    public function delete(string $path): bool
    {
        if (!$this->files->exists($path)) {
            return false;
        }

        return $this->files->delete($path);
    }

    /**
     * Convert string to snake_case.
     */
    protected function toSnakeCase(string $value): string
    {
        $value = preg_replace('/(?<!^)[A-Z]/', '_$0', $value);

        return strtolower($value);
    }

    /**
     * Get the Filesystem instance.
     */
    public function getFilesystem(): Filesystem
    {
        return $this->files;
    }
}
