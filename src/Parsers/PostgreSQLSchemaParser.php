<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Parsers;

use Sepehr_Mohseni\Elosql\Exceptions\SchemaParserException;
use Sepehr_Mohseni\Elosql\ValueObjects\ColumnSchema;
use Sepehr_Mohseni\Elosql\ValueObjects\ForeignKeySchema;
use Sepehr_Mohseni\Elosql\ValueObjects\IndexSchema;
use Sepehr_Mohseni\Elosql\ValueObjects\TableSchema;

class PostgreSQLSchemaParser extends AbstractSchemaParser
{
    protected string $schema = 'public';

    public function getDriver(): string
    {
        return 'pgsql';
    }

    public function setSchema(string $schema): self
    {
        $this->schema = $schema;

        return $this;
    }

    public function getTables(array $excludeTables = []): array
    {
        $connection = $this->getConnection();

        $tables = $connection->select(
            'SELECT tablename FROM pg_tables 
             WHERE schemaname = ?
             ORDER BY tablename',
            [$this->schema]
        );

        $tableNames = array_map(fn ($row) => $row->tablename, $tables);

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

        $connection = $this->getConnection();

        // Get table comment
        $tableComment = $connection->selectOne(
            'SELECT obj_description(c.oid) as comment
             FROM pg_class c
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE c.relname = ? AND n.nspname = ?',
            [$tableName, $this->schema]
        );

        return new TableSchema(
            name: $tableName,
            columns: $this->getColumns($tableName),
            indexes: $this->getIndexes($tableName),
            foreignKeys: $this->getForeignKeys($tableName),
            comment: $tableComment?->comment,
            attributes: ['schema' => $this->schema],
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

        $columns = $connection->select(
            "SELECT 
                a.attname AS column_name,
                pg_catalog.format_type(a.atttypid, a.atttypmod) AS data_type,
                t.typname AS udt_name,
                a.attnotnull AS not_null,
                pg_get_expr(d.adbin, d.adrelid) AS column_default,
                a.attlen AS length,
                CASE WHEN a.atttypmod > 0 THEN a.atttypmod - 4 ELSE NULL END AS char_length,
                COALESCE(
                    (SELECT TRUE FROM pg_index i WHERE i.indrelid = c.oid AND i.indisprimary AND a.attnum = ANY(i.indkey)),
                    FALSE
                ) AS is_primary,
                col_description(c.oid, a.attnum) AS comment,
                CASE WHEN pg_get_serial_sequence(quote_ident(n.nspname) || '.' || quote_ident(c.relname), a.attname) IS NOT NULL 
                    THEN TRUE ELSE FALSE END AS is_serial
             FROM pg_class c
             JOIN pg_namespace n ON n.oid = c.relnamespace
             JOIN pg_attribute a ON a.attrelid = c.oid
             JOIN pg_type t ON t.oid = a.atttypid
             LEFT JOIN pg_attrdef d ON d.adrelid = c.oid AND d.adnum = a.attnum
             WHERE c.relname = ?
                AND n.nspname = ?
                AND a.attnum > 0
                AND NOT a.attisdropped
             ORDER BY a.attnum",
            [$tableName, $this->schema]
        );

        return array_map(
            fn ($column) => $this->buildColumnSchema((array) $column),
            $columns
        );
    }

    /**
     * @return array<IndexSchema>
     */
    protected function getIndexes(string $tableName): array
    {
        $connection = $this->getConnection();

        $indexes = $connection->select(
            'SELECT 
                i.relname AS index_name,
                a.attname AS column_name,
                ix.indisunique AS is_unique,
                ix.indisprimary AS is_primary,
                am.amname AS index_type,
                array_position(ix.indkey, a.attnum) AS position
             FROM pg_class t
             JOIN pg_namespace n ON n.oid = t.relnamespace
             JOIN pg_index ix ON t.oid = ix.indrelid
             JOIN pg_class i ON i.oid = ix.indexrelid
             JOIN pg_am am ON am.oid = i.relam
             JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
             WHERE t.relname = ?
                AND n.nspname = ?
             ORDER BY i.relname, array_position(ix.indkey, a.attnum)',
            [$tableName, $this->schema]
        );

        // Group columns by index
        $grouped = [];
        foreach ($indexes as $index) {
            $name = $index->index_name;
            if (! isset($grouped[$name])) {
                $grouped[$name] = [
                    'name' => $name,
                    'columns' => [],
                    'unique' => (bool) $index->is_unique,
                    'primary' => (bool) $index->is_primary,
                    'type' => $index->index_type,
                ];
            }
            $grouped[$name]['columns'][] = $index->column_name;
        }

        return array_map(
            fn ($index) => $this->buildIndexSchema($index),
            array_values($grouped)
        );
    }

    /**
     * @return array<ForeignKeySchema>
     */
    protected function getForeignKeys(string $tableName): array
    {
        $connection = $this->getConnection();

        $foreignKeys = $connection->select(
            "SELECT
                con.conname AS constraint_name,
                a.attname AS column_name,
                confrel.relname AS referenced_table,
                af.attname AS referenced_column,
                con.confupdtype AS update_action,
                con.confdeltype AS delete_action
             FROM pg_constraint con
             JOIN pg_class rel ON rel.oid = con.conrelid
             JOIN pg_namespace nsp ON nsp.oid = rel.relnamespace
             JOIN pg_class confrel ON confrel.oid = con.confrelid
             JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = ANY(con.conkey)
             JOIN pg_attribute af ON af.attrelid = con.confrelid AND af.attnum = ANY(con.confkey)
             WHERE con.contype = 'f'
                AND rel.relname = ?
                AND nsp.nspname = ?
             ORDER BY con.conname, array_position(con.conkey, a.attnum)",
            [$tableName, $this->schema]
        );

        // Group by constraint name
        $grouped = [];
        foreach ($foreignKeys as $fk) {
            $name = $fk->constraint_name;
            if (! isset($grouped[$name])) {
                $grouped[$name] = [
                    'name' => $name,
                    'columns' => [],
                    'referenced_table' => $fk->referenced_table,
                    'referenced_columns' => [],
                    'on_update' => $this->mapForeignKeyAction($fk->update_action),
                    'on_delete' => $this->mapForeignKeyAction($fk->delete_action),
                ];
            }
            $grouped[$name]['columns'][] = $fk->column_name;
            $grouped[$name]['referenced_columns'][] = $fk->referenced_column;
        }

        return array_map(
            fn ($fk) => $this->buildForeignKeySchema($fk),
            array_values($grouped)
        );
    }

    protected function buildColumnSchema(array $columnInfo): ColumnSchema
    {
        $dataType = $columnInfo['data_type'];
        $udtName = $columnInfo['udt_name'];
        $type = $this->normalizePostgresType($udtName, $dataType);
        $nullable = ! $columnInfo['not_null'];

        $default = $this->parsePostgresDefault(
            $columnInfo['column_default'],
            $type,
            $nullable
        );

        $isAutoIncrement = $columnInfo['is_serial'] ||
            str_contains((string) $columnInfo['column_default'], 'nextval(');

        // Extract length/precision
        $length = null;
        $precision = null;
        $scale = null;

        if (preg_match('/\((\d+),(\d+)\)/', $dataType, $matches)) {
            $precision = (int) $matches[1];
            $scale = (int) $matches[2];
        } elseif (preg_match('/\((\d+)\)/', $dataType, $matches)) {
            $length = (int) $matches[1];
        } elseif ($columnInfo['char_length'] !== null) {
            $length = (int) $columnInfo['char_length'];
        }

        $attributes = [];
        if ($columnInfo['is_primary']) {
            $attributes['primary'] = true;
        }

        return new ColumnSchema(
            name: $columnInfo['column_name'],
            type: $type,
            nativeType: $dataType,
            nullable: $nullable,
            default: $default,
            autoIncrement: $isAutoIncrement,
            unsigned: false, // PostgreSQL doesn't have unsigned
            length: $length,
            precision: $precision,
            scale: $scale,
            charset: null,
            collation: null,
            comment: $columnInfo['comment'],
            attributes: $attributes,
        );
    }

    protected function buildIndexSchema(array $indexInfo): IndexSchema
    {
        $type = match (true) {
            $indexInfo['primary'] => IndexSchema::TYPE_PRIMARY,
            $indexInfo['unique'] => IndexSchema::TYPE_UNIQUE,
            default => IndexSchema::TYPE_INDEX,
        };

        $algorithm = match ($indexInfo['type']) {
            'btree' => IndexSchema::ALGORITHM_BTREE,
            'hash' => IndexSchema::ALGORITHM_HASH,
            default => null,
        };

        return new IndexSchema(
            name: $indexInfo['name'],
            type: $type,
            columns: $indexInfo['columns'],
            algorithm: $algorithm,
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
            onDelete: $fkInfo['on_delete'],
            onUpdate: $fkInfo['on_update'],
        );
    }

    /**
     * Normalize PostgreSQL type names.
     */
    protected function normalizePostgresType(string $udtName, string $dataType): string
    {
        // Map PostgreSQL internal type names to standard names
        return match ($udtName) {
            'int2' => 'smallint',
            'int4' => 'integer',
            'int8' => 'bigint',
            'float4' => 'real',
            'float8' => 'double precision',
            'bool' => 'boolean',
            'varchar' => 'character varying',
            'bpchar' => 'character',
            'timestamptz' => 'timestamp with time zone',
            'timetz' => 'time with time zone',
            default => $this->normalizeTypeName($dataType),
        };
    }

    /**
     * Parse PostgreSQL default value.
     */
    protected function parsePostgresDefault(mixed $default, string $type, bool $nullable): mixed
    {
        if ($default === null) {
            return null;
        }

        $default = (string) $default;

        // Check for NULL
        if ($default === 'NULL' || $default === 'NULL::' . $type) {
            return null;
        }

        // Check for sequence (auto-increment)
        if (str_contains($default, 'nextval(')) {
            return null; // Auto-increment, no default needed
        }

        // Remove type cast
        $default = preg_replace('/::[\w\s\[\]]+$/', '', $default);

        // Handle boolean
        if ($type === 'boolean') {
            return $default === 'true';
        }

        // Remove quotes
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

        // Handle expressions
        if (preg_match('/^[A-Z_]+(\(\))?$/i', $default)) {
            return $default;
        }

        return $default;
    }

    /**
     * Map PostgreSQL foreign key action codes to action names.
     */
    protected function mapForeignKeyAction(string $action): string
    {
        return match ($action) {
            'a' => ForeignKeySchema::ACTION_NO_ACTION,
            'r' => ForeignKeySchema::ACTION_RESTRICT,
            'c' => ForeignKeySchema::ACTION_CASCADE,
            'n' => ForeignKeySchema::ACTION_SET_NULL,
            'd' => ForeignKeySchema::ACTION_SET_DEFAULT,
            default => ForeignKeySchema::ACTION_NO_ACTION,
        };
    }
}
