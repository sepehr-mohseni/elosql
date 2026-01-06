<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Analyzers;

use Illuminate\Filesystem\Filesystem;

class MigrationAnalyzer
{
    /** @var array<string, array<string, mixed>>|null */
    protected ?array $parsedMigrations = null;

    public function __construct(
        protected Filesystem $files,
        protected string $migrationsPath,
    ) {
    }

    /**
     * Get all migration files.
     *
     * @return array<string>
     */
    public function getMigrationFiles(): array
    {
        if (! $this->files->isDirectory($this->migrationsPath)) {
            return [];
        }

        $files = $this->files->glob($this->migrationsPath . '/*.php');

        return array_map('realpath', $files);
    }

    /**
     * Parse all migrations and extract table information.
     *
     * @return array<string, array<string, mixed>>
     */
    public function parseMigrations(): array
    {
        if ($this->parsedMigrations !== null) {
            return $this->parsedMigrations;
        }

        $this->parsedMigrations = [];

        foreach ($this->getMigrationFiles() as $file) {
            $info = $this->parseMigrationFile($file);
            if ($info !== null) {
                $this->parsedMigrations[basename($file)] = $info;
            }
        }

        return $this->parsedMigrations;
    }

    /**
     * Get tables that are created in existing migrations.
     *
     * @return array<string>
     */
    public function getTablesInMigrations(): array
    {
        $migrations = $this->parseMigrations();
        $tables = [];

        foreach ($migrations as $info) {
            if (isset($info['creates'])) {
                $tables = array_merge($tables, $info['creates']);
            }
        }

        return array_unique($tables);
    }

    /**
     * Check if a table has a migration.
     */
    public function hasTableMigration(string $tableName): bool
    {
        return in_array($tableName, $this->getTablesInMigrations(), true);
    }

    /**
     * Get migration info for a specific table.
     *
     * @return array<string, mixed>|null
     */
    public function getMigrationForTable(string $tableName): ?array
    {
        $migrations = $this->parseMigrations();

        foreach ($migrations as $file => $info) {
            if (isset($info['creates']) && in_array($tableName, $info['creates'], true)) {
                return array_merge(['file' => $file], $info);
            }
        }

        return null;
    }

    /**
     * Parse a single migration file.
     *
     * @return array<string, mixed>|null
     */
    protected function parseMigrationFile(string $path): ?array
    {
        $content = $this->files->get($path);

        $info = [
            'creates' => [],
            'modifies' => [],
            'drops' => [],
            'foreign_keys' => [],
        ];

        // Match Schema::create calls
        if (preg_match_all('/Schema::create\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
            $info['creates'] = $matches[1];
        }

        // Match Schema::table calls (modifications)
        if (preg_match_all('/Schema::table\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
            $info['modifies'] = $matches[1];
        }

        // Match Schema::drop and Schema::dropIfExists calls
        if (preg_match_all('/Schema::drop(?:IfExists)?\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
            $info['drops'] = $matches[1];
        }

        // Match foreign key definitions
        if (preg_match_all('/->foreign\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
            $info['foreign_keys'] = $matches[1];
        }

        // Extract class name
        if (preg_match('/class\s+(\w+)/', $content, $matches)) {
            $info['class'] = $matches[1];
        }

        // Check if this migration has any meaningful content
        if (empty($info['creates']) && empty($info['modifies']) && empty($info['drops'])) {
            return null;
        }

        return $info;
    }

    /**
     * Get the timestamp prefix from a migration filename.
     */
    public function extractTimestamp(string $filename): ?string
    {
        if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', basename($filename), $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Generate the next available timestamp for migrations.
     */
    public function getNextTimestamp(int $offset = 0): string
    {
        return date('Y_m_d_His', time() + $offset);
    }

    /**
     * Get columns defined in a migration for a specific table.
     *
     * @return array<string>
     */
    public function getColumnsInMigration(string $tableName): array
    {
        $migration = $this->getMigrationForTable($tableName);

        if ($migration === null) {
            return [];
        }

        $content = $this->files->get($this->migrationsPath . '/' . $migration['file']);

        // Find the Schema::create block for this table
        $pattern = '/Schema::create\s*\(\s*[\'"]' . preg_quote($tableName, '/') . '[\'"]\s*,\s*function[^{]*\{(.*?)\}\s*\)/s';

        if (! preg_match($pattern, $content, $match)) {
            return [];
        }

        $schemaBlock = $match[1];
        $columns = [];

        // Match column definitions
        $columnPattern = '/\$table->(\w+)\s*\(\s*[\'"]([^\'"]+)[\'"]/';
        if (preg_match_all($columnPattern, $schemaBlock, $matches)) {
            $columns = $matches[2];
        }

        // Also match id(), timestamps(), etc. without column name parameter
        $implicitColumns = [
            'id' => 'id',
            'uuid' => 'uuid',
            'ulid' => 'ulid',
            'timestamps' => ['created_at', 'updated_at'],
            'timestampsTz' => ['created_at', 'updated_at'],
            'softDeletes' => 'deleted_at',
            'softDeletesTz' => 'deleted_at',
            'rememberToken' => 'remember_token',
        ];

        foreach ($implicitColumns as $method => $columnNames) {
            if (preg_match('/\$table->' . $method . '\s*\(/', $schemaBlock)) {
                if (is_array($columnNames)) {
                    $columns = array_merge($columns, $columnNames);
                } else {
                    $columns[] = $columnNames;
                }
            }
        }

        return array_unique($columns);
    }

    /**
     * Clear the parsed migrations cache.
     */
    public function clearCache(): void
    {
        $this->parsedMigrations = null;
    }

    /**
     * Set the migrations path.
     */
    public function setMigrationsPath(string $path): self
    {
        $this->migrationsPath = $path;
        $this->clearCache();

        return $this;
    }
}
