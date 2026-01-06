<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Generators;

use Illuminate\Support\Str;
use Sepehr_Mohseni\Elosql\Analyzers\DependencyResolver;
use Sepehr_Mohseni\Elosql\Support\FileWriter;
use Sepehr_Mohseni\Elosql\Support\TypeMapper;
use Sepehr_Mohseni\Elosql\ValueObjects\ColumnSchema;
use Sepehr_Mohseni\Elosql\ValueObjects\ForeignKeySchema;
use Sepehr_Mohseni\Elosql\ValueObjects\IndexSchema;
use Sepehr_Mohseni\Elosql\ValueObjects\TableSchema;

class MigrationGenerator
{
    protected string $driver = 'mysql';

    public function __construct(
        protected TypeMapper $typeMapper,
        protected DependencyResolver $dependencyResolver,
        protected FileWriter $fileWriter,
        protected string $migrationsPath,
    ) {
    }

    /**
     * Set the database driver for type mapping.
     */
    public function setDriver(string $driver): self
    {
        $this->driver = $driver;

        return $this;
    }

    /**
     * Generate migrations for all tables.
     *
     * @param array<TableSchema> $tables
     * @param bool $separateForeignKeys Generate foreign keys in separate migrations
     *
     * @return array<string, string> Map of filename to content
     */
    public function generate(array $tables, bool $separateForeignKeys = true): array
    {
        $sortedTables = $this->dependencyResolver->sortByDependencies($tables);

        $migrations = [];
        $timestamp = time();
        $offset = 0;

        // Generate table creation migrations
        foreach ($sortedTables as $table) {
            $filename = $this->fileWriter->generateMigrationFilename(
                'create_' . $table->name . '_table',
                $timestamp + $offset
            );

            $content = $this->generateTableMigration($table, $separateForeignKeys);
            $migrations[$filename] = $this->fileWriter->formatCode($content);
            $offset++;
        }

        // Generate foreign key migrations separately if requested
        if ($separateForeignKeys) {
            $fkMigrations = $this->generateForeignKeyMigrations($sortedTables, $timestamp + $offset);
            $migrations = array_merge($migrations, $fkMigrations);
        }

        return $migrations;
    }

    /**
     * Generate migration for a single table.
     */
    public function generateTableMigration(TableSchema $table, bool $excludeForeignKeys = true): string
    {
        $className = 'Create' . Str::studly($table->name) . 'Table';
        $columns = $this->generateColumns($table);
        $indexes = $this->generateIndexes($table);
        $foreignKeys = $excludeForeignKeys ? '' : $this->generateForeignKeys($table);

        $upContent = $this->buildUpMethod($table, $columns, $indexes, $foreignKeys);
        $downContent = $this->buildDownMethod($table);

        return $this->buildMigrationClass($className, $upContent, $downContent);
    }

    /**
     * Generate foreign key migrations for all tables.
     *
     * @param array<TableSchema> $tables
     *
     * @return array<string, string>
     */
    protected function generateForeignKeyMigrations(array $tables, int $startTimestamp): array
    {
        $migrations = [];
        $offset = 0;

        foreach ($tables as $table) {
            if (empty($table->foreignKeys)) {
                continue;
            }

            $filename = $this->fileWriter->generateMigrationFilename(
                'add_' . $table->name . '_foreign_keys',
                $startTimestamp + $offset
            );

            $content = $this->generateForeignKeyMigration($table);
            $migrations[$filename] = $this->fileWriter->formatCode($content);
            $offset++;
        }

        return $migrations;
    }

    /**
     * Generate a foreign key migration for a table.
     */
    protected function generateForeignKeyMigration(TableSchema $table): string
    {
        $className = 'Add' . Str::studly($table->name) . 'ForeignKeys';

        $upLines = [];
        $downLines = [];

        foreach ($table->foreignKeys as $fk) {
            $upLines[] = $this->generateForeignKeyDefinition($fk);
            $downLines[] = "\$table->dropForeign(['" . implode("', '", $fk->columns) . "']);";
        }

        $upContent = "Schema::table('{$table->name}', function (Blueprint \$table) {\n";
        $upContent .= $this->indent(implode("\n", $upLines), 3);
        $upContent .= "\n        });";

        $downContent = "Schema::table('{$table->name}', function (Blueprint \$table) {\n";
        $downContent .= $this->indent(implode("\n", $downLines), 3);
        $downContent .= "\n        });";

        return $this->buildMigrationClass($className, $upContent, $downContent);
    }

    /**
     * Generate column definitions.
     */
    protected function generateColumns(TableSchema $table): string
    {
        $lines = [];

        foreach ($table->columns as $column) {
            $lines[] = $this->generateColumnDefinition($column);
        }

        return implode("\n", $lines);
    }

    /**
     * Generate a single column definition.
     */
    protected function generateColumnDefinition(ColumnSchema $column): string
    {
        // Check for special Laravel column types first
        if ($this->isLaravelTimestamps($column)) {
            return ''; // Will be handled separately
        }

        $line = $this->typeMapper->buildMethodCall($column, $this->driver);

        // Add modifiers
        if ($column->nullable && ! $column->autoIncrement) {
            $line .= '->nullable()';
        }

        if ($column->hasDefault() && ! $column->autoIncrement) {
            $default = $this->formatDefaultValue($column->default, $column->type);
            if ($default !== null) {
                $line .= "->default({$default})";
            }
        }

        if ($column->comment !== null && $column->comment !== '') {
            $line .= "->comment('" . addslashes($column->comment) . "')";
        }

        // Add charset/collation if different from table default
        if ($column->charset !== null) {
            $line .= "->charset('{$column->charset}')";
        }

        if ($column->collation !== null) {
            $line .= "->collation('{$column->collation}')";
        }

        return $line . ';';
    }

    /**
     * Generate index definitions.
     */
    protected function generateIndexes(TableSchema $table): string
    {
        $lines = [];

        foreach ($table->indexes as $index) {
            // Skip primary key - it's usually handled by column definition
            if ($index->isPrimary()) {
                // Only add explicit primary key if it's composite
                if ($index->isComposite) {
                    $columns = "['" . implode("', '", $index->columns) . "']";
                    $lines[] = "\$table->primary({$columns});";
                }
                continue;
            }

            $lines[] = $this->generateIndexDefinition($index);
        }

        return implode("\n", $lines);
    }

    /**
     * Generate a single index definition.
     */
    protected function generateIndexDefinition(IndexSchema $index): string
    {
        $columns = count($index->columns) === 1
            ? "'" . $index->columns[0] . "'"
            : "['" . implode("', '", $index->columns) . "']";

        $method = match ($index->type) {
            IndexSchema::TYPE_UNIQUE => 'unique',
            IndexSchema::TYPE_FULLTEXT => 'fullText',
            IndexSchema::TYPE_SPATIAL => 'spatialIndex',
            default => 'index',
        };

        $name = $index->name !== '' ? ", '{$index->name}'" : '';

        return "\$table->{$method}({$columns}{$name});";
    }

    /**
     * Generate foreign key definitions.
     */
    protected function generateForeignKeys(TableSchema $table): string
    {
        $lines = [];

        foreach ($table->foreignKeys as $fk) {
            $lines[] = $this->generateForeignKeyDefinition($fk);
        }

        return implode("\n", $lines);
    }

    /**
     * Generate a single foreign key definition.
     */
    protected function generateForeignKeyDefinition(ForeignKeySchema $fk): string
    {
        $columns = count($fk->columns) === 1
            ? "'" . $fk->columns[0] . "'"
            : "['" . implode("', '", $fk->columns) . "']";

        $references = count($fk->referencedColumns) === 1
            ? "'" . $fk->referencedColumns[0] . "'"
            : "['" . implode("', '", $fk->referencedColumns) . "']";

        $line = "\$table->foreign({$columns})";
        $line .= "->references({$references})";
        $line .= "->on('{$fk->referencedTable}')";

        if ($fk->onDelete !== ForeignKeySchema::ACTION_RESTRICT && $fk->onDelete !== ForeignKeySchema::ACTION_NO_ACTION) {
            $line .= "->onDelete('" . strtolower(str_replace('_', ' ', $fk->onDelete)) . "')";
        }

        if ($fk->onUpdate !== ForeignKeySchema::ACTION_RESTRICT && $fk->onUpdate !== ForeignKeySchema::ACTION_NO_ACTION) {
            $line .= "->onUpdate('" . strtolower(str_replace('_', ' ', $fk->onUpdate)) . "')";
        }

        return $line . ';';
    }

    /**
     * Build the up() method content.
     */
    protected function buildUpMethod(
        TableSchema $table,
        string $columns,
        string $indexes,
        string $foreignKeys
    ): string {
        $content = "Schema::create('{$table->name}', function (Blueprint \$table) {\n";

        // Add columns
        $content .= $this->indent($columns, 3);

        // Add timestamps if detected
        if ($table->hasTimestamps()) {
            $content .= "\n" . $this->indent('$table->timestamps();', 3);
        }

        // Add soft deletes if detected
        if ($table->hasSoftDeletes()) {
            $content .= "\n" . $this->indent('$table->softDeletes();', 3);
        }

        // Add indexes
        if ($indexes !== '') {
            $content .= "\n\n" . $this->indent('// Indexes', 3);
            $content .= "\n" . $this->indent($indexes, 3);
        }

        // Add foreign keys if not separated
        if ($foreignKeys !== '') {
            $content .= "\n\n" . $this->indent('// Foreign Keys', 3);
            $content .= "\n" . $this->indent($foreignKeys, 3);
        }

        $content .= "\n        });";

        return $content;
    }

    /**
     * Build the down() method content.
     */
    protected function buildDownMethod(TableSchema $table): string
    {
        return "Schema::dropIfExists('{$table->name}');";
    }

    /**
     * Build the complete migration class.
     */
    protected function buildMigrationClass(string $className, string $upContent, string $downContent): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration
            {
                /**
                 * Run the migrations.
                 */
                public function up(): void
                {
                    {$upContent}
                }

                /**
                 * Reverse the migrations.
                 */
                public function down(): void
                {
                    {$downContent}
                }
            };
            PHP;
    }

    /**
     * Format a default value for use in migration.
     */
    protected function formatDefaultValue(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        // Check for DB expressions
        $expressions = ['CURRENT_TIMESTAMP', 'CURRENT_DATE', 'CURRENT_TIME', 'NOW()', 'UUID()'];
        if (is_string($value) && in_array(strtoupper($value), $expressions, true)) {
            return "DB::raw('{$value}')";
        }

        // String value
        return "'" . addslashes((string) $value) . "'";
    }

    /**
     * Check if a column is part of Laravel's timestamps.
     */
    protected function isLaravelTimestamps(ColumnSchema $column): bool
    {
        return in_array($column->name, ['created_at', 'updated_at', 'deleted_at'], true);
    }

    /**
     * Indent a string with the given level.
     */
    protected function indent(string $content, int $level): string
    {
        $indent = str_repeat('    ', $level);
        $lines = explode("\n", $content);

        return implode("\n", array_map(
            fn ($line) => $line !== '' ? $indent . $line : $line,
            $lines
        ));
    }

    /**
     * Write migrations to disk.
     *
     * @param array<string, string> $migrations
     *
     * @return array<string> List of written files
     */
    public function writeMigrations(array $migrations, bool $force = false): array
    {
        $written = [];

        foreach ($migrations as $filename => $content) {
            $path = $this->migrationsPath . '/' . $filename;
            $this->fileWriter->write($path, $content, $force);
            $written[] = $path;
        }

        return $written;
    }

    /**
     * Preview migrations without writing.
     *
     * @param array<TableSchema> $tables
     *
     * @return array<string, string>
     */
    public function preview(array $tables, bool $separateForeignKeys = true): array
    {
        return $this->generate($tables, $separateForeignKeys);
    }
}
