<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Parsers;

use Sepehr_Mohseni\Elosql\Exceptions\SchemaParserException;
use Sepehr_Mohseni\Elosql\ValueObjects\ColumnSchema;
use Sepehr_Mohseni\Elosql\ValueObjects\ForeignKeySchema;
use Sepehr_Mohseni\Elosql\ValueObjects\IndexSchema;
use Sepehr_Mohseni\Elosql\ValueObjects\TableSchema;

class MySQLSchemaParser extends AbstractSchemaParser
{
    public function getDriver(): string
    {
        return 'mysql';
    }

    public function getTables(array $excludeTables = []): array
    {
        $connection = $this->getConnection();
        $database = $this->getDatabaseName();

        $tables = $connection->select(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES 
             WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
             ORDER BY TABLE_NAME",
            [$database]
        );

        $tableNames = array_map(fn ($row) => $row->TABLE_NAME, $tables);

        if (! empty($excludeTables)) {
            $tableNames = array_diff($tableNames, $excludeTables);
        }

        return array_values($tableNames);
    }

    public function parseTable(string $tableName): TableSchema
    {
        $connection = $this->getConnection();
        $database = $this->getDatabaseName();

        if (! $this->tableExists($tableName)) {
            throw SchemaParserException::tableNotFound($tableName);
        }

        // Get table info
        $tableInfo = $connection->selectOne(
            'SELECT ENGINE, TABLE_COLLATION, TABLE_COMMENT
             FROM INFORMATION_SCHEMA.TABLES 
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, $tableName]
        );

        // Extract charset from collation
        $charset = null;
        if ($tableInfo->TABLE_COLLATION) {
            $charset = explode('_', $tableInfo->TABLE_COLLATION)[0];
        }

        return new TableSchema(
            name: $tableName,
            columns: $this->getColumns($tableName),
            indexes: $this->getIndexes($tableName),
            foreignKeys: $this->getForeignKeys($tableName),
            engine: $tableInfo->ENGINE,
            charset: $charset,
            collation: $tableInfo->TABLE_COLLATION,
            comment: $tableInfo->TABLE_COMMENT ?: null,
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
        $database = $this->getDatabaseName();

        $columns = $connection->select(
            'SELECT 
                COLUMN_NAME,
                COLUMN_TYPE,
                DATA_TYPE,
                IS_NULLABLE,
                COLUMN_DEFAULT,
                EXTRA,
                CHARACTER_MAXIMUM_LENGTH,
                NUMERIC_PRECISION,
                NUMERIC_SCALE,
                CHARACTER_SET_NAME,
                COLLATION_NAME,
                COLUMN_COMMENT,
                COLUMN_KEY
             FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION',
            [$database, $tableName]
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
        $database = $this->getDatabaseName();

        $indexes = $connection->select(
            'SELECT 
                INDEX_NAME,
                COLUMN_NAME,
                NON_UNIQUE,
                INDEX_TYPE,
                SEQ_IN_INDEX
             FROM INFORMATION_SCHEMA.STATISTICS 
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$database, $tableName]
        );

        // Group columns by index
        $grouped = [];
        foreach ($indexes as $index) {
            $name = $index->INDEX_NAME;
            if (! isset($grouped[$name])) {
                $grouped[$name] = [
                    'name' => $name,
                    'columns' => [],
                    'unique' => ! $index->NON_UNIQUE,
                    'type' => $index->INDEX_TYPE,
                ];
            }
            $grouped[$name]['columns'][] = $index->COLUMN_NAME;
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
        $database = $this->getDatabaseName();

        $foreignKeys = $connection->select(
            'SELECT 
                kcu.CONSTRAINT_NAME,
                kcu.COLUMN_NAME,
                kcu.REFERENCED_TABLE_NAME,
                kcu.REFERENCED_COLUMN_NAME,
                rc.UPDATE_RULE,
                rc.DELETE_RULE
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
             JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc 
                ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME 
                AND kcu.TABLE_SCHEMA = rc.CONSTRAINT_SCHEMA
             WHERE kcu.TABLE_SCHEMA = ? 
                AND kcu.TABLE_NAME = ?
                AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION',
            [$database, $tableName]
        );

        // Group by constraint name
        $grouped = [];
        foreach ($foreignKeys as $fk) {
            $name = $fk->CONSTRAINT_NAME;
            if (! isset($grouped[$name])) {
                $grouped[$name] = [
                    'name' => $name,
                    'columns' => [],
                    'referenced_table' => $fk->REFERENCED_TABLE_NAME,
                    'referenced_columns' => [],
                    'on_update' => $fk->UPDATE_RULE,
                    'on_delete' => $fk->DELETE_RULE,
                ];
            }
            $grouped[$name]['columns'][] = $fk->COLUMN_NAME;
            $grouped[$name]['referenced_columns'][] = $fk->REFERENCED_COLUMN_NAME;
        }

        return array_map(
            fn ($fk) => $this->buildForeignKeySchema($fk),
            array_values($grouped)
        );
    }

    protected function buildColumnSchema(array $columnInfo): ColumnSchema
    {
        $type = $this->normalizeTypeName($columnInfo['DATA_TYPE']);
        $columnType = $columnInfo['COLUMN_TYPE'];
        $nullable = $columnInfo['IS_NULLABLE'] === 'YES';
        $default = $this->parseDefaultValue(
            $columnInfo['COLUMN_DEFAULT'],
            $type,
            $nullable
        );

        // Handle ENUM and SET
        $attributes = [];
        if (in_array($type, ['enum', 'set'], true)) {
            $attributes['enum_values'] = $this->extractEnumValues($columnType);
        }

        // Check for primary key
        if ($columnInfo['COLUMN_KEY'] === 'PRI') {
            $attributes['primary'] = true;
        }

        // Extract length/precision
        $length = null;
        $precision = null;
        $scale = null;

        if (in_array($type, ['decimal', 'numeric', 'float', 'double'], true)) {
            $precisionScale = $this->extractPrecisionScale($columnType);
            $precision = $precisionScale['precision'];
            $scale = $precisionScale['scale'];
        } elseif ($columnInfo['CHARACTER_MAXIMUM_LENGTH'] !== null) {
            $length = (int) $columnInfo['CHARACTER_MAXIMUM_LENGTH'];
        } elseif (preg_match('/\((\d+)\)/', $columnType, $matches)) {
            $length = (int) $matches[1];
        }

        return new ColumnSchema(
            name: $columnInfo['COLUMN_NAME'],
            type: $type,
            nativeType: $columnType,
            nullable: $nullable,
            default: $default,
            autoIncrement: $this->isAutoIncrement($columnInfo['EXTRA'] ?? ''),
            unsigned: $this->isUnsigned($columnType),
            length: $length,
            precision: $precision,
            scale: $scale,
            charset: $columnInfo['CHARACTER_SET_NAME'],
            collation: $columnInfo['COLLATION_NAME'],
            comment: $columnInfo['COLUMN_COMMENT'] ?: null,
            attributes: $attributes,
        );
    }

    protected function buildIndexSchema(array $indexInfo): IndexSchema
    {
        $name = $indexInfo['name'];
        $columns = $indexInfo['columns'];

        // Determine index type
        $type = match (true) {
            $name === 'PRIMARY' => IndexSchema::TYPE_PRIMARY,
            $indexInfo['unique'] => IndexSchema::TYPE_UNIQUE,
            $indexInfo['type'] === 'FULLTEXT' => IndexSchema::TYPE_FULLTEXT,
            $indexInfo['type'] === 'SPATIAL' => IndexSchema::TYPE_SPATIAL,
            default => IndexSchema::TYPE_INDEX,
        };

        $algorithm = match ($indexInfo['type']) {
            'BTREE' => IndexSchema::ALGORITHM_BTREE,
            'HASH' => IndexSchema::ALGORITHM_HASH,
            default => null,
        };

        return new IndexSchema(
            name: $name,
            type: $type,
            columns: $columns,
            algorithm: $algorithm,
            isComposite: count($columns) > 1,
        );
    }

    protected function buildForeignKeySchema(array $fkInfo): ForeignKeySchema
    {
        return new ForeignKeySchema(
            name: $fkInfo['name'],
            columns: $fkInfo['columns'],
            referencedTable: $fkInfo['referenced_table'],
            referencedColumns: $fkInfo['referenced_columns'],
            onDelete: $fkInfo['on_delete'] ?? ForeignKeySchema::ACTION_RESTRICT,
            onUpdate: $fkInfo['on_update'] ?? ForeignKeySchema::ACTION_RESTRICT,
        );
    }
}
