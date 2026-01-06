<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Analyzers;

use Sepehr_Mohseni\Elosql\ValueObjects\ColumnSchema;
use Sepehr_Mohseni\Elosql\ValueObjects\ForeignKeySchema;
use Sepehr_Mohseni\Elosql\ValueObjects\IndexSchema;
use Sepehr_Mohseni\Elosql\ValueObjects\TableSchema;

class SchemaComparator
{
    public function __construct(
        protected ?MigrationAnalyzer $migrationAnalyzer = null,
    ) {}

    /**
     * Compare two schema arrays directly.
     *
     * @param array<TableSchema> $current Current/source schema
     * @param array<TableSchema> $target Target schema
     * @return array{created: array<TableSchema>, dropped: array<TableSchema>, modified: array<array<string, mixed>>}
     */
    public function compare(array $current, array $target): array
    {
        $currentNames = array_map(fn (TableSchema $t) => $t->name, $current);
        $targetNames = array_map(fn (TableSchema $t) => $t->name, $target);

        $currentByName = [];
        foreach ($current as $table) {
            $currentByName[$table->name] = $table;
        }

        $targetByName = [];
        foreach ($target as $table) {
            $targetByName[$table->name] = $table;
        }

        // Find created tables
        $createdNames = array_diff($targetNames, $currentNames);
        $created = array_values(array_filter($target, fn ($t) => in_array($t->name, $createdNames, true)));

        // Find dropped tables
        $droppedNames = array_diff($currentNames, $targetNames);
        $dropped = array_values(array_filter($current, fn ($t) => in_array($t->name, $droppedNames, true)));

        // Find modified tables
        $commonNames = array_intersect($currentNames, $targetNames);
        $modified = [];
        foreach ($commonNames as $name) {
            $diff = $this->compareTable($currentByName[$name], $targetByName[$name]);
            if (!empty($diff['columns']['added']) || !empty($diff['columns']['dropped']) ||
                !empty($diff['columns']['modified']) || !empty($diff['indexes']['added']) ||
                !empty($diff['indexes']['dropped']) || !empty($diff['foreign_keys']['added']) ||
                !empty($diff['foreign_keys']['dropped'])) {
                $modified[] = $diff;
            }
        }

        return [
            'created' => $created,
            'dropped' => $dropped,
            'modified' => $modified,
        ];
    }

    /**
     * Compare two tables and return differences.
     *
     * @return array<string, mixed>
     */
    public function compareTable(TableSchema $current, TableSchema $target): array
    {
        $currentColumnNames = array_map(fn ($c) => $c->name, $current->columns);
        $targetColumnNames = array_map(fn ($c) => $c->name, $target->columns);

        $currentColumns = [];
        foreach ($current->columns as $col) {
            $currentColumns[$col->name] = $col;
        }

        $targetColumns = [];
        foreach ($target->columns as $col) {
            $targetColumns[$col->name] = $col;
        }

        // Find added/dropped columns
        $addedColumnNames = array_diff($targetColumnNames, $currentColumnNames);
        $droppedColumnNames = array_diff($currentColumnNames, $targetColumnNames);

        $addedColumns = array_values(array_filter($target->columns, fn ($c) => in_array($c->name, $addedColumnNames, true)));
        $droppedColumns = array_values(array_filter($current->columns, fn ($c) => in_array($c->name, $droppedColumnNames, true)));

        // Find modified columns
        $commonColumnNames = array_intersect($currentColumnNames, $targetColumnNames);
        $modifiedColumns = [];
        foreach ($commonColumnNames as $name) {
            $changes = $this->compareColumn($currentColumns[$name], $targetColumns[$name]);
            if (!empty($changes)) {
                $modifiedColumns[] = [
                    'column' => $targetColumns[$name],
                    'changes' => $changes,
                ];
            }
        }

        // Compare indexes
        $currentIndexNames = array_map(fn ($i) => $i->name, $current->indexes);
        $targetIndexNames = array_map(fn ($i) => $i->name, $target->indexes);

        $addedIndexNames = array_diff($targetIndexNames, $currentIndexNames);
        $droppedIndexNames = array_diff($currentIndexNames, $targetIndexNames);

        $addedIndexes = array_values(array_filter($target->indexes, fn ($i) => in_array($i->name, $addedIndexNames, true)));
        $droppedIndexes = array_values(array_filter($current->indexes, fn ($i) => in_array($i->name, $droppedIndexNames, true)));

        // Compare foreign keys
        $currentFkNames = array_map(fn ($f) => $f->name, $current->foreignKeys);
        $targetFkNames = array_map(fn ($f) => $f->name, $target->foreignKeys);

        $addedFkNames = array_diff($targetFkNames, $currentFkNames);
        $droppedFkNames = array_diff($currentFkNames, $targetFkNames);

        $addedFks = array_values(array_filter($target->foreignKeys, fn ($f) => in_array($f->name, $addedFkNames, true)));
        $droppedFks = array_values(array_filter($current->foreignKeys, fn ($f) => in_array($f->name, $droppedFkNames, true)));

        return [
            'table' => $current->name,
            'columns' => [
                'added' => $addedColumns,
                'dropped' => $droppedColumns,
                'modified' => $modifiedColumns,
            ],
            'indexes' => [
                'added' => $addedIndexes,
                'dropped' => $droppedIndexes,
            ],
            'foreign_keys' => [
                'added' => $addedFks,
                'dropped' => $droppedFks,
            ],
        ];
    }

    /**
     * Compare two columns and return the differences.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function compareColumn(ColumnSchema $current, ColumnSchema $target): array
    {
        $changes = [];

        if ($current->type !== $target->type) {
            $changes['type'] = ['from' => $current->type, 'to' => $target->type];
        }

        if ($current->nullable !== $target->nullable) {
            $changes['nullable'] = ['from' => $current->nullable, 'to' => $target->nullable];
        }

        if ($current->default !== $target->default) {
            $changes['default'] = ['from' => $current->default, 'to' => $target->default];
        }

        if ($current->length !== $target->length) {
            $changes['length'] = ['from' => $current->length, 'to' => $target->length];
        }

        if ($current->precision !== $target->precision) {
            $changes['precision'] = ['from' => $current->precision, 'to' => $target->precision];
        }

        if ($current->scale !== $target->scale) {
            $changes['scale'] = ['from' => $current->scale, 'to' => $target->scale];
        }

        return $changes;
    }

    /**
     * Check if there are any changes between two schemas.
     *
     * @param array<TableSchema> $current
     * @param array<TableSchema> $target
     */
    public function hasChanges(array $current, array $target): bool
    {
        $diff = $this->compare($current, $target);

        return !empty($diff['created']) || !empty($diff['dropped']) || !empty($diff['modified']);
    }

    /**
     * Generate a diff summary.
     *
     * @param array<TableSchema> $current
     * @param array<TableSchema> $target
     * @return array<string, mixed>
     */
    public function getDiffSummary(array $current, array $target): array
    {
        $diff = $this->compare($current, $target);

        $summary = [
            'created_tables' => count($diff['created']),
            'dropped_tables' => count($diff['dropped']),
            'modified_tables' => count($diff['modified']),
            'created_table_names' => array_map(fn ($t) => $t->name, $diff['created']),
            'dropped_table_names' => array_map(fn ($t) => $t->name, $diff['dropped']),
            'modifications' => [],
        ];

        foreach ($diff['modified'] as $mod) {
            $summary['modifications'][$mod['table']] = [
                'columns_added' => count($mod['columns']['added']),
                'columns_dropped' => count($mod['columns']['dropped']),
                'columns_modified' => count($mod['columns']['modified']),
                'indexes_added' => count($mod['indexes']['added']),
                'indexes_dropped' => count($mod['indexes']['dropped']),
                'foreign_keys_added' => count($mod['foreign_keys']['added']),
                'foreign_keys_dropped' => count($mod['foreign_keys']['dropped']),
            ];
        }

        return $summary;
    }

    /**
     * Compare database tables with existing migrations and return differences.
     * (Used when MigrationAnalyzer is available)
     *
     * @param array<TableSchema> $databaseTables
     * @return array{new: array<string>, modified: array<string>, removed: array<string>}
     */
    public function compareWithMigrations(array $databaseTables): array
    {
        if ($this->migrationAnalyzer === null) {
            throw new \RuntimeException('MigrationAnalyzer not set. Use compare() for direct comparison.');
        }

        $migrationTables = $this->migrationAnalyzer->getTablesInMigrations();
        $databaseTableNames = array_map(fn (TableSchema $t) => $t->name, $databaseTables);

        return [
            'new' => array_values(array_diff($databaseTableNames, $migrationTables)),
            'modified' => $this->findModifiedTables($databaseTables, $migrationTables),
            'removed' => array_values(array_diff($migrationTables, $databaseTableNames)),
        ];
    }

    /**
     * Get detailed diff for a specific table.
     *
     * @return array<string, mixed>
     */
    public function getTableDiff(TableSchema $table): array
    {
        if ($this->migrationAnalyzer === null) {
            throw new \RuntimeException('MigrationAnalyzer not set.');
        }

        $migrationColumns = $this->migrationAnalyzer->getColumnsInMigration($table->name);
        $databaseColumns = $table->getColumnNames();

        return [
            'table' => $table->name,
            'has_migration' => $this->migrationAnalyzer->hasTableMigration($table->name),
            'columns' => [
                'new' => array_values(array_diff($databaseColumns, $migrationColumns)),
                'removed' => array_values(array_diff($migrationColumns, $databaseColumns)),
                'existing' => array_values(array_intersect($databaseColumns, $migrationColumns)),
            ],
            'migration_info' => $this->migrationAnalyzer->getMigrationForTable($table->name),
        ];
    }

    /**
     * Find tables that exist in both but might have different columns.
     *
     * @param array<TableSchema> $databaseTables
     * @param array<string> $migrationTables
     * @return array<string>
     */
    protected function findModifiedTables(array $databaseTables, array $migrationTables): array
    {
        if ($this->migrationAnalyzer === null) {
            return [];
        }

        $modified = [];

        foreach ($databaseTables as $table) {
            if (!in_array($table->name, $migrationTables, true)) {
                continue; // New table, not modified
            }

            $diff = $this->getTableDiff($table);

            if (!empty($diff['columns']['new']) || !empty($diff['columns']['removed'])) {
                $modified[] = $table->name;
            }
        }

        return $modified;
    }

    /**
     * Generate a human-readable diff report.
     *
     * @param array<TableSchema> $databaseTables
     * @return array<string, mixed>
     */
    public function generateReport(array $databaseTables): array
    {
        $comparison = $this->compareWithMigrations($databaseTables);

        $report = [
            'summary' => [
                'total_tables' => count($databaseTables),
                'new_tables' => count($comparison['new']),
                'modified_tables' => count($comparison['modified']),
                'removed_tables' => count($comparison['removed']),
            ],
            'new_tables' => $comparison['new'],
            'modified_tables' => [],
            'removed_tables' => $comparison['removed'],
            'actions' => [],
        ];

        // Get detailed info for modified tables
        foreach ($comparison['modified'] as $tableName) {
            $table = $this->findTable($databaseTables, $tableName);
            if ($table !== null) {
                $report['modified_tables'][$tableName] = $this->getTableDiff($table);
            }
        }

        // Generate recommended actions
        if (!empty($comparison['new'])) {
            $report['actions'][] = [
                'type' => 'create_migrations',
                'description' => 'Create migrations for new tables',
                'tables' => $comparison['new'],
            ];
        }

        if (!empty($comparison['modified'])) {
            $report['actions'][] = [
                'type' => 'review_modifications',
                'description' => 'Review and potentially update migrations for modified tables',
                'tables' => $comparison['modified'],
            ];
        }

        return $report;
    }

    /**
     * Check if schema is in sync (no differences).
     *
     * @param array<TableSchema> $databaseTables
     */
    public function isInSync(array $databaseTables): bool
    {
        $comparison = $this->compareWithMigrations($databaseTables);

        return empty($comparison['new'])
            && empty($comparison['modified'])
            && empty($comparison['removed']);
    }

    /**
     * Get tables that need migrations.
     *
     * @param array<TableSchema> $databaseTables
     * @return array<TableSchema>
     */
    public function getTablesNeedingMigrations(array $databaseTables): array
    {
        $comparison = $this->compareWithMigrations($databaseTables);
        $needsMigration = array_merge($comparison['new'], $comparison['modified']);

        return array_filter(
            $databaseTables,
            fn (TableSchema $table) => in_array($table->name, $needsMigration, true)
        );
    }

    /**
     * Find a table by name in an array of tables.
     *
     * @param array<TableSchema> $tables
     */
    protected function findTable(array $tables, string $name): ?TableSchema
    {
        foreach ($tables as $table) {
            if ($table->name === $name) {
                return $table;
            }
        }

        return null;
    }
}
