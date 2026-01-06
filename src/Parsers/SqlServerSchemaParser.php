<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Parsers;

use Sepehr_Mohseni\Elosql\Exceptions\SchemaParserException;
use Sepehr_Mohseni\Elosql\ValueObjects\ColumnSchema;
use Sepehr_Mohseni\Elosql\ValueObjects\ForeignKeySchema;
use Sepehr_Mohseni\Elosql\ValueObjects\IndexSchema;
use Sepehr_Mohseni\Elosql\ValueObjects\TableSchema;

class SqlServerSchemaParser extends AbstractSchemaParser
{
    protected string $schema = 'dbo';

    public function getDriver(): string
    {
        return 'sqlsrv';
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
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES 
             WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
             ORDER BY TABLE_NAME",
            [$this->schema]
        );

        $tableNames = array_map(fn ($row) => $row->TABLE_NAME, $tables);

        if (!empty($excludeTables)) {
            $tableNames = array_diff($tableNames, $excludeTables);
        }

        return array_values($tableNames);
    }

    public function parseTable(string $tableName): TableSchema
    {
        if (!$this->tableExists($tableName)) {
            throw SchemaParserException::tableNotFound($tableName);
        }

        $connection = $this->getConnection();

        // Get table extended properties (comments)
        $tableComment = $connection->selectOne(
            "SELECT CAST(value AS NVARCHAR(MAX)) as comment
             FROM sys.extended_properties ep
             JOIN sys.tables t ON ep.major_id = t.object_id
             JOIN sys.schemas s ON t.schema_id = s.schema_id
             WHERE ep.minor_id = 0 
                AND ep.name = 'MS_Description'
                AND t.name = ?
                AND s.name = ?",
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
                c.COLUMN_NAME,
                c.DATA_TYPE,
                c.IS_NULLABLE,
                c.COLUMN_DEFAULT,
                c.CHARACTER_MAXIMUM_LENGTH,
                c.NUMERIC_PRECISION,
                c.NUMERIC_SCALE,
                c.COLLATION_NAME,
                COLUMNPROPERTY(OBJECT_ID(c.TABLE_SCHEMA + '.' + c.TABLE_NAME), c.COLUMN_NAME, 'IsIdentity') AS IS_IDENTITY,
                COLUMNPROPERTY(OBJECT_ID(c.TABLE_SCHEMA + '.' + c.TABLE_NAME), c.COLUMN_NAME, 'IsComputed') AS IS_COMPUTED,
                CAST(ep.value AS NVARCHAR(MAX)) AS COLUMN_COMMENT,
                CASE WHEN pk.COLUMN_NAME IS NOT NULL THEN 1 ELSE 0 END AS IS_PRIMARY
             FROM INFORMATION_SCHEMA.COLUMNS c
             LEFT JOIN sys.extended_properties ep 
                ON ep.major_id = OBJECT_ID(c.TABLE_SCHEMA + '.' + c.TABLE_NAME)
                AND ep.minor_id = c.ORDINAL_POSITION
                AND ep.name = 'MS_Description'
             LEFT JOIN (
                SELECT ku.TABLE_SCHEMA, ku.TABLE_NAME, ku.COLUMN_NAME
                FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
                JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE ku 
                    ON tc.CONSTRAINT_NAME = ku.CONSTRAINT_NAME
                WHERE tc.CONSTRAINT_TYPE = 'PRIMARY KEY'
             ) pk ON c.TABLE_SCHEMA = pk.TABLE_SCHEMA 
                AND c.TABLE_NAME = pk.TABLE_NAME 
                AND c.COLUMN_NAME = pk.COLUMN_NAME
             WHERE c.TABLE_SCHEMA = ? AND c.TABLE_NAME = ?
             ORDER BY c.ORDINAL_POSITION",
            [$this->schema, $tableName]
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
            "SELECT 
                i.name AS INDEX_NAME,
                c.name AS COLUMN_NAME,
                i.is_unique AS IS_UNIQUE,
                i.is_primary_key AS IS_PRIMARY,
                i.type_desc AS INDEX_TYPE,
                ic.key_ordinal AS ORDINAL
             FROM sys.indexes i
             JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id
             JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id
             JOIN sys.tables t ON i.object_id = t.object_id
             JOIN sys.schemas s ON t.schema_id = s.schema_id
             WHERE t.name = ?
                AND s.name = ?
                AND i.name IS NOT NULL
             ORDER BY i.name, ic.key_ordinal",
            [$tableName, $this->schema]
        );

        // Group columns by index
        $grouped = [];
        foreach ($indexes as $index) {
            $name = $index->INDEX_NAME;
            if (!isset($grouped[$name])) {
                $grouped[$name] = [
                    'name' => $name,
                    'columns' => [],
                    'unique' => (bool) $index->IS_UNIQUE,
                    'primary' => (bool) $index->IS_PRIMARY,
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

        $foreignKeys = $connection->select(
            "SELECT 
                fk.name AS CONSTRAINT_NAME,
                cp.name AS COLUMN_NAME,
                rt.name AS REFERENCED_TABLE,
                cr.name AS REFERENCED_COLUMN,
                fk.update_referential_action_desc AS UPDATE_ACTION,
                fk.delete_referential_action_desc AS DELETE_ACTION
             FROM sys.foreign_keys fk
             JOIN sys.foreign_key_columns fkc ON fk.object_id = fkc.constraint_object_id
             JOIN sys.tables t ON fk.parent_object_id = t.object_id
             JOIN sys.schemas s ON t.schema_id = s.schema_id
             JOIN sys.columns cp ON fkc.parent_object_id = cp.object_id AND fkc.parent_column_id = cp.column_id
             JOIN sys.tables rt ON fk.referenced_object_id = rt.object_id
             JOIN sys.columns cr ON fkc.referenced_object_id = cr.object_id AND fkc.referenced_column_id = cr.column_id
             WHERE t.name = ?
                AND s.name = ?
             ORDER BY fk.name, fkc.constraint_column_id",
            [$tableName, $this->schema]
        );

        // Group by constraint name
        $grouped = [];
        foreach ($foreignKeys as $fk) {
            $name = $fk->CONSTRAINT_NAME;
            if (!isset($grouped[$name])) {
                $grouped[$name] = [
                    'name' => $name,
                    'columns' => [],
                    'referenced_table' => $fk->REFERENCED_TABLE,
                    'referenced_columns' => [],
                    'on_update' => $this->mapSqlServerAction($fk->UPDATE_ACTION),
                    'on_delete' => $this->mapSqlServerAction($fk->DELETE_ACTION),
                ];
            }
            $grouped[$name]['columns'][] = $fk->COLUMN_NAME;
            $grouped[$name]['referenced_columns'][] = $fk->REFERENCED_COLUMN;
        }

        return array_map(
            fn ($fk) => $this->buildForeignKeySchema($fk),
            array_values($grouped)
        );
    }

    protected function buildColumnSchema(array $columnInfo): ColumnSchema
    {
        $type = strtolower($columnInfo['DATA_TYPE']);
        $nullable = $columnInfo['IS_NULLABLE'] === 'YES';

        $default = $this->parseSqlServerDefault(
            $columnInfo['COLUMN_DEFAULT'],
            $type,
            $nullable
        );

        $attributes = [];
        if ($columnInfo['IS_PRIMARY']) {
            $attributes['primary'] = true;
        }
        if ($columnInfo['IS_COMPUTED']) {
            $attributes['computed'] = true;
        }

        $length = null;
        $precision = null;
        $scale = null;

        if ($columnInfo['CHARACTER_MAXIMUM_LENGTH'] !== null && $columnInfo['CHARACTER_MAXIMUM_LENGTH'] !== -1) {
            $length = (int) $columnInfo['CHARACTER_MAXIMUM_LENGTH'];
        } elseif ($columnInfo['CHARACTER_MAXIMUM_LENGTH'] === -1) {
            // -1 means MAX (e.g., varchar(max))
            $attributes['max'] = true;
        }

        if ($columnInfo['NUMERIC_PRECISION'] !== null) {
            $precision = (int) $columnInfo['NUMERIC_PRECISION'];
            $scale = $columnInfo['NUMERIC_SCALE'] !== null ? (int) $columnInfo['NUMERIC_SCALE'] : null;
        }

        // Build native type string
        $nativeType = $type;
        if ($length !== null) {
            $nativeType .= "({$length})";
        } elseif ($precision !== null) {
            $nativeType .= $scale !== null ? "({$precision},{$scale})" : "({$precision})";
        } elseif (isset($attributes['max'])) {
            $nativeType .= '(max)';
        }

        return new ColumnSchema(
            name: $columnInfo['COLUMN_NAME'],
            type: $type,
            nativeType: $nativeType,
            nullable: $nullable,
            default: $default,
            autoIncrement: (bool) $columnInfo['IS_IDENTITY'],
            unsigned: false, // SQL Server doesn't have unsigned
            length: $length,
            precision: $precision,
            scale: $scale,
            charset: null,
            collation: $columnInfo['COLLATION_NAME'],
            comment: $columnInfo['COLUMN_COMMENT'],
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

        return new IndexSchema(
            name: $indexInfo['name'],
            type: $type,
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
            onDelete: $fkInfo['on_delete'],
            onUpdate: $fkInfo['on_update'],
        );
    }

    /**
     * Parse SQL Server default value.
     */
    protected function parseSqlServerDefault(mixed $default, string $type, bool $nullable): mixed
    {
        if ($default === null) {
            return null;
        }

        $default = (string) $default;

        // Remove outer parentheses (SQL Server wraps defaults in parens)
        $default = trim($default, '()');

        // Check for NULL
        if (strtoupper($default) === 'NULL') {
            return null;
        }

        // Handle expressions
        if (preg_match('/^[A-Z_]+(\(\))?$/i', $default)) {
            return $default;
        }

        // Handle boolean (bit type)
        if ($type === 'bit') {
            return $default === '1';
        }

        // Remove N prefix for unicode strings
        $default = preg_replace("/^N'/", "'", $default);

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
     * Map SQL Server foreign key action descriptions to action names.
     */
    protected function mapSqlServerAction(string $action): string
    {
        return match ($action) {
            'NO_ACTION' => ForeignKeySchema::ACTION_NO_ACTION,
            'CASCADE' => ForeignKeySchema::ACTION_CASCADE,
            'SET_NULL' => ForeignKeySchema::ACTION_SET_NULL,
            'SET_DEFAULT' => ForeignKeySchema::ACTION_SET_DEFAULT,
            default => ForeignKeySchema::ACTION_NO_ACTION,
        };
    }
}
