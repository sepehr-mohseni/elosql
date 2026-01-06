<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Parsers;

use Illuminate\Database\Connection;
use RuntimeException;
use Sepehr_Mohseni\Elosql\Support\TypeMapper;
use Sepehr_Mohseni\Elosql\ValueObjects\ColumnSchema;
use Sepehr_Mohseni\Elosql\ValueObjects\ForeignKeySchema;
use Sepehr_Mohseni\Elosql\ValueObjects\IndexSchema;

abstract class AbstractSchemaParser implements SchemaParser
{
    protected ?Connection $connection = null;

    public function __construct(
        protected TypeMapper $typeMapper,
    ) {
    }

    public function setConnection(Connection $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    public function parseAllTables(array $excludeTables = []): array
    {
        $tables = $this->getTables($excludeTables);
        $schemas = [];

        foreach ($tables as $tableName) {
            $schemas[] = $this->parseTable($tableName);
        }

        return $schemas;
    }

    public function tableExists(string $tableName): bool
    {
        return in_array($tableName, $this->getTables(), true);
    }

    protected function getConnection(): Connection
    {
        if ($this->connection === null) {
            throw new RuntimeException('Database connection not set. Call setConnection() first.');
        }

        return $this->connection;
    }

    /**
     * Build a ColumnSchema from raw database column info.
     *
     * @param array<string, mixed> $columnInfo
     */
    abstract protected function buildColumnSchema(array $columnInfo): ColumnSchema;

    /**
     * Build an IndexSchema from raw database index info.
     *
     * @param array<string, mixed> $indexInfo
     */
    abstract protected function buildIndexSchema(array $indexInfo): IndexSchema;

    /**
     * Build a ForeignKeySchema from raw database FK info.
     *
     * @param array<string, mixed> $fkInfo
     */
    abstract protected function buildForeignKeySchema(array $fkInfo): ForeignKeySchema;

    /**
     * Parse default value from database representation.
     */
    protected function parseDefaultValue(mixed $default, string $type, bool $nullable): mixed
    {
        if ($default === null) {
            return $nullable ? null : null;
        }

        // Remove quotes and type casts
        $default = trim((string) $default);

        // Check for NULL
        if (strtoupper($default) === 'NULL') {
            return null;
        }

        // Check for boolean
        if (in_array(strtolower($type), ['boolean', 'bool', 'tinyint'], true)) {
            if (in_array($default, ['1', 'true', "'1'", 'b\'1\''], true)) {
                return true;
            }
            if (in_array($default, ['0', 'false', "'0'", 'b\'0\''], true)) {
                return false;
            }
        }

        // Check for numeric
        if (is_numeric($default) && ! str_starts_with($default, "'")) {
            if (str_contains($default, '.')) {
                return (float) $default;
            }

            return (int) $default;
        }

        // Check for expression (NOW(), CURRENT_TIMESTAMP, etc.)
        if (preg_match('/^[A-Z_]+(\(\))?$/i', $default)) {
            return $default; // Return as expression string
        }

        // Remove surrounding quotes
        if (preg_match('/^\'(.*)\'$/', $default, $matches)) {
            return $matches[1];
        }

        return $default;
    }

    /**
     * Extract length from type definition.
     */
    protected function extractLength(string $typeDefinition): ?int
    {
        if (preg_match('/\((\d+)\)/', $typeDefinition, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Extract precision and scale from type definition.
     *
     * @return array{precision: int|null, scale: int|null}
     */
    protected function extractPrecisionScale(string $typeDefinition): array
    {
        if (preg_match('/\((\d+),\s*(\d+)\)/', $typeDefinition, $matches)) {
            return [
                'precision' => (int) $matches[1],
                'scale' => (int) $matches[2],
            ];
        }

        if (preg_match('/\((\d+)\)/', $typeDefinition, $matches)) {
            return [
                'precision' => (int) $matches[1],
                'scale' => null,
            ];
        }

        return ['precision' => null, 'scale' => null];
    }

    /**
     * Extract enum/set values from type definition.
     *
     * @return array<string>
     */
    protected function extractEnumValues(string $typeDefinition): array
    {
        if (preg_match('/\((.+)\)/', $typeDefinition, $matches)) {
            $values = str_getcsv($matches[1], ',', "'");

            return array_map('trim', $values);
        }

        return [];
    }

    /**
     * Normalize type name by removing size/precision info.
     */
    protected function normalizeTypeName(string $type): string
    {
        // Remove parenthetical info
        $type = preg_replace('/\s*\([^)]+\)/', '', $type);

        // Remove unsigned, zerofill, etc.
        $type = preg_replace('/\s+(unsigned|zerofill|signed)/i', '', $type);

        return strtolower(trim($type));
    }

    /**
     * Check if a type definition indicates unsigned.
     */
    protected function isUnsigned(string $typeDefinition): bool
    {
        return (bool) preg_match('/\bunsigned\b/i', $typeDefinition);
    }

    /**
     * Check if column is auto-increment.
     */
    protected function isAutoIncrement(string $extra): bool
    {
        return (bool) preg_match('/auto_increment/i', $extra);
    }
}
