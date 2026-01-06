<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Parsers;

use Sepehr_Mohseni\Elosql\Exceptions\SchemaParserException;
use Sepehr_Mohseni\Elosql\ValueObjects\ColumnSchema;
use Sepehr_Mohseni\Elosql\ValueObjects\ForeignKeySchema;
use Sepehr_Mohseni\Elosql\ValueObjects\IndexSchema;
use Sepehr_Mohseni\Elosql\ValueObjects\TableSchema;

class SQLiteSchemaParser extends AbstractSchemaParser
{
    public function getDriver(): string
    {
        return 'sqlite';
    }

    public function getTables(array $excludeTables = []): array
    {
        $connection = $this->getConnection();

        $tables = $connection->select(
            "SELECT name FROM sqlite_master 
             WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
             ORDER BY name"
        );

        $tableNames = array_map(fn ($row) => $row->name, $tables);

        if (! empty($excludeTables)) {
            $tableNames = array_diff($tableNames, $excludeTables);
        }

        return array_values($tableNames);
    }

    public function parseTable(string $tableName): TableSchema
    {
        if (! $this->tableExists($tableName)) {
            throw SchemaParserException::tableNotFound($tableName);
        }

        return new TableSchema(
            name: $tableName,
            columns: $this->getColumns($tableName),
            indexes: $this->getIndexes($tableName),
            foreignKeys: $this->getForeignKeys($tableName),
        );
    }

    public function getDatabaseName(): string
    {
        return $this->getConnection()->getDatabaseName();
    }

    /**
     * @return array<ColumnSchema>
     */
    protected function getColumns(string $tableName): array
    {
        $connection = $this->getConnection();

        // Use PRAGMA to get column info
        $columns = $connection->select("PRAGMA table_info('{$tableName}')");

        // Get primary key columns
        $pkColumns = $this->getPrimaryKeyColumns($tableName);

        return array_map(
            fn ($column) => $this->buildColumnSchema(
                array_merge((array) $column, ['pk_columns' => $pkColumns])
            ),
            $columns
        );
    }

    /**
     * @return array<IndexSchema>
     */
    protected function getIndexes(string $tableName): array
    {
        $connection = $this->getConnection();

        // Get list of indexes
        $indexes = $connection->select("PRAGMA index_list('{$tableName}')");

        $result = [];

        // Add primary key as index if it exists
        $pkColumns = $this->getPrimaryKeyColumns($tableName);
        if (! empty($pkColumns)) {
            $result[] = new IndexSchema(
                name: 'PRIMARY',
                type: IndexSchema::TYPE_PRIMARY,
                columns: $pkColumns,
                isComposite: count($pkColumns) > 1,
            );
        }

        foreach ($indexes as $index) {
            if (str_starts_with($index->name, 'sqlite_autoindex_')) {
                continue; // Skip auto-created indexes
            }

            // Get columns for this index
            $indexInfo = $connection->select("PRAGMA index_info('{$index->name}')");
            $columns = array_map(fn ($info) => $info->name, $indexInfo);

            if (empty($columns)) {
                continue;
            }

            $type = $index->unique ? IndexSchema::TYPE_UNIQUE : IndexSchema::TYPE_INDEX;

            $result[] = new IndexSchema(
                name: $index->name,
                type: $type,
                columns: $columns,
                isComposite: count($columns) > 1,
            );
        }

        return $result;
    }

    /**
     * @return array<ForeignKeySchema>
     */
    protected function getForeignKeys(string $tableName): array
    {
        $connection = $this->getConnection();

        $foreignKeys = $connection->select("PRAGMA foreign_key_list('{$tableName}')");

        // Group by id (constraint id)
        $grouped = [];
        foreach ($foreignKeys as $fk) {
            $id = $fk->id;
            if (! isset($grouped[$id])) {
                $grouped[$id] = [
                    'name' => "fk_{$tableName}_{$fk->table}_{$id}",
                    'columns' => [],
                    'referenced_table' => $fk->table,
                    'referenced_columns' => [],
                    'on_update' => strtoupper($fk->on_update),
                    'on_delete' => strtoupper($fk->on_delete),
                ];
            }
            $grouped[$id]['columns'][] = $fk->from;
            $grouped[$id]['referenced_columns'][] = $fk->to;
        }

        return array_map(
            fn ($fk) => $this->buildForeignKeySchema($fk),
            array_values($grouped)
        );
    }

    /**
     * Get primary key columns for a table.
     *
     * @return array<string>
     */
    protected function getPrimaryKeyColumns(string $tableName): array
    {
        $connection = $this->getConnection();
        $columns = $connection->select("PRAGMA table_info('{$tableName}')");

        $pkColumns = [];
        foreach ($columns as $column) {
            if ($column->pk > 0) {
                $pkColumns[$column->pk] = $column->name;
            }
        }

        ksort($pkColumns);

        return array_values($pkColumns);
    }

    protected function buildColumnSchema(array $columnInfo): ColumnSchema
    {
        $type = $this->normalizeSqliteType($columnInfo['type']);
        $nullable = $columnInfo['notnull'] == 0;

        $default = $this->parseSqliteDefault(
            $columnInfo['dflt_value'],
            $type,
            $nullable
        );

        // Check if column is auto-increment (INTEGER PRIMARY KEY in SQLite)
        $isAutoIncrement = false;
        $isPrimary = false;

        if ($columnInfo['pk'] > 0) {
            $isPrimary = true;
            // In SQLite, INTEGER PRIMARY KEY is alias for ROWID and auto-increments
            if (strtoupper($columnInfo['type']) === 'INTEGER' && count($columnInfo['pk_columns']) === 1) {
                $isAutoIncrement = true;
            }
        }

        // Extract length/precision from type
        $length = $this->extractLength($columnInfo['type']);
        $precisionScale = $this->extractPrecisionScale($columnInfo['type']);

        $attributes = [];
        if ($isPrimary) {
            $attributes['primary'] = true;
        }

        return new ColumnSchema(
            name: $columnInfo['name'],
            type: $type,
            nativeType: $columnInfo['type'],
            nullable: $nullable,
            default: $default,
            autoIncrement: $isAutoIncrement,
            unsigned: false, // SQLite doesn't have unsigned
            length: $length,
            precision: $precisionScale['precision'],
            scale: $precisionScale['scale'],
            charset: null,
            collation: null,
            comment: null, // SQLite doesn't support column comments
            attributes: $attributes,
        );
    }

    protected function buildIndexSchema(array $indexInfo): IndexSchema
    {
        return new IndexSchema(
            name: $indexInfo['name'],
            type: $indexInfo['unique'] ? IndexSchema::TYPE_UNIQUE : IndexSchema::TYPE_INDEX,
            columns: $indexInfo['columns'],
            isComposite: count($indexInfo['columns']) > 1,
        );
    }

    protected function buildForeignKeySchema(array $fkInfo): ForeignKeySchema
    {
        return new ForeignKeySchema(
            name: $fkInfo['name'],
            columns: $fkInfo['columns'],
            referencedTable: $fkInfo['referenced_table'],
            referencedColumns: $fkInfo['referenced_columns'],
            onDelete: $this->normalizeForeignKeyAction($fkInfo['on_delete']),
            onUpdate: $this->normalizeForeignKeyAction($fkInfo['on_update']),
        );
    }

    /**
     * Normalize SQLite type to a standard type name.
     * SQLite uses type affinity, so we map common type names.
     */
    protected function normalizeSqliteType(string $type): string
    {
        $type = strtolower(trim($type));

        // Remove size specifications
        $baseType = preg_replace('/\s*\([^)]+\)/', '', $type);

        // Map to standard types based on SQLite type affinity rules
        return match (true) {
            str_contains($baseType, 'int') => 'integer',
            str_contains($baseType, 'char'),
            str_contains($baseType, 'clob'),
            str_contains($baseType, 'text') => 'text',
            str_contains($baseType, 'blob'),
            $baseType === '' => 'blob',
            str_contains($baseType, 'real'),
            str_contains($baseType, 'floa'),
            str_contains($baseType, 'doub') => 'real',
            str_contains($baseType, 'bool') => 'integer', // SQLite stores booleans as integers
            str_contains($baseType, 'date'),
            str_contains($baseType, 'time') => 'text', // SQLite stores dates as text
            str_contains($baseType, 'decimal'),
            str_contains($baseType, 'numeric') => 'numeric',
            default => 'numeric',
        };
    }

    /**
     * Parse SQLite default value.
     */
    protected function parseSqliteDefault(mixed $default, string $type, bool $nullable): mixed
    {
        if ($default === null) {
            return null;
        }

        $default = (string) $default;

        // Check for NULL
        if (strtoupper($default) === 'NULL') {
            return null;
        }

        // Handle expressions
        if (in_array(strtoupper($default), ['CURRENT_TIME', 'CURRENT_DATE', 'CURRENT_TIMESTAMP'], true)) {
            return $default;
        }

        // Remove surrounding quotes
        if (preg_match("/^'(.*)'$/s", $default, $matches)) {
            return $matches[1];
        }

        // Handle numeric
        if (is_numeric($default)) {
            if (str_contains($default, '.')) {
                return (float) $default;
            }

            return (int) $default;
        }

        return $default;
    }

    /**
     * Normalize foreign key action.
     */
    protected function normalizeForeignKeyAction(string $action): string
    {
        $action = strtoupper(trim($action));

        return match ($action) {
            'CASCADE' => ForeignKeySchema::ACTION_CASCADE,
            'SET NULL' => ForeignKeySchema::ACTION_SET_NULL,
            'SET DEFAULT' => ForeignKeySchema::ACTION_SET_DEFAULT,
            'RESTRICT' => ForeignKeySchema::ACTION_RESTRICT,
            'NO ACTION', '' => ForeignKeySchema::ACTION_NO_ACTION,
            default => ForeignKeySchema::ACTION_NO_ACTION,
        };
    }
}
