<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Support;

use Sepehr_Mohseni\Elosql\ValueObjects\ColumnSchema;

class TypeMapper
{
    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $customMappings;

    /**
     * Default MySQL type mappings.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $mysqlMappings = [
        'bigint' => ['method' => 'bigInteger'],
        'int' => ['method' => 'integer'],
        'integer' => ['method' => 'integer'],
        'mediumint' => ['method' => 'mediumInteger'],
        'smallint' => ['method' => 'smallInteger'],
        'tinyint' => ['method' => 'tinyInteger'],
        'decimal' => ['method' => 'decimal'],
        'double' => ['method' => 'double'],
        'float' => ['method' => 'float'],
        'varchar' => ['method' => 'string'],
        'char' => ['method' => 'char'],
        'text' => ['method' => 'text'],
        'mediumtext' => ['method' => 'mediumText'],
        'longtext' => ['method' => 'longText'],
        'tinytext' => ['method' => 'tinyText'],
        'blob' => ['method' => 'binary'],
        'mediumblob' => ['method' => 'binary'],
        'longblob' => ['method' => 'binary'],
        'tinyblob' => ['method' => 'binary'],
        'binary' => ['method' => 'binary'],
        'varbinary' => ['method' => 'binary'],
        'json' => ['method' => 'json', 'cast' => 'array'],
        'date' => ['method' => 'date', 'cast' => 'date'],
        'datetime' => ['method' => 'dateTime', 'cast' => 'datetime'],
        'timestamp' => ['method' => 'timestamp', 'cast' => 'datetime'],
        'time' => ['method' => 'time'],
        'year' => ['method' => 'year'],
        'enum' => ['method' => 'enum'],
        'set' => ['method' => 'set'],
        'boolean' => ['method' => 'boolean', 'cast' => 'boolean'],
        'bool' => ['method' => 'boolean', 'cast' => 'boolean'],
        'uuid' => ['method' => 'uuid'],
        'point' => ['method' => 'point'],
        'linestring' => ['method' => 'lineString'],
        'polygon' => ['method' => 'polygon'],
        'geometry' => ['method' => 'geometry'],
        'geometrycollection' => ['method' => 'geometryCollection'],
        'multipoint' => ['method' => 'multiPoint'],
        'multilinestring' => ['method' => 'multiLineString'],
        'multipolygon' => ['method' => 'multiPolygon'],
    ];

    /**
     * Default PostgreSQL type mappings.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $pgsqlMappings = [
        'bigint' => ['method' => 'bigInteger'],
        'int8' => ['method' => 'bigInteger'],
        'integer' => ['method' => 'integer'],
        'int' => ['method' => 'integer'],
        'int4' => ['method' => 'integer'],
        'smallint' => ['method' => 'smallInteger'],
        'int2' => ['method' => 'smallInteger'],
        'decimal' => ['method' => 'decimal'],
        'numeric' => ['method' => 'decimal'],
        'real' => ['method' => 'float'],
        'float4' => ['method' => 'float'],
        'double precision' => ['method' => 'double'],
        'float8' => ['method' => 'double'],
        'money' => ['method' => 'decimal', 'precision' => 19, 'scale' => 4],
        'character varying' => ['method' => 'string'],
        'varchar' => ['method' => 'string'],
        'character' => ['method' => 'char'],
        'char' => ['method' => 'char'],
        'text' => ['method' => 'text'],
        'bytea' => ['method' => 'binary'],
        'json' => ['method' => 'json', 'cast' => 'array'],
        'jsonb' => ['method' => 'jsonb', 'cast' => 'array'],
        'date' => ['method' => 'date', 'cast' => 'date'],
        'timestamp' => ['method' => 'timestamp', 'cast' => 'datetime'],
        'timestamp without time zone' => ['method' => 'timestamp', 'cast' => 'datetime'],
        'timestamp with time zone' => ['method' => 'timestampTz', 'cast' => 'datetime'],
        'timestamptz' => ['method' => 'timestampTz', 'cast' => 'datetime'],
        'time' => ['method' => 'time'],
        'time without time zone' => ['method' => 'time'],
        'time with time zone' => ['method' => 'timeTz'],
        'timetz' => ['method' => 'timeTz'],
        'interval' => ['method' => 'string'],
        'boolean' => ['method' => 'boolean', 'cast' => 'boolean'],
        'bool' => ['method' => 'boolean', 'cast' => 'boolean'],
        'uuid' => ['method' => 'uuid'],
        'inet' => ['method' => 'ipAddress'],
        'cidr' => ['method' => 'ipAddress'],
        'macaddr' => ['method' => 'macAddress'],
        'macaddr8' => ['method' => 'macAddress'],
        'point' => ['method' => 'point'],
        'line' => ['method' => 'lineString'],
        'lseg' => ['method' => 'lineString'],
        'box' => ['method' => 'polygon'],
        'path' => ['method' => 'lineString'],
        'polygon' => ['method' => 'polygon'],
        'circle' => ['method' => 'geometry'],
        'serial' => ['method' => 'integer', 'auto_increment' => true],
        'bigserial' => ['method' => 'bigInteger', 'auto_increment' => true],
        'smallserial' => ['method' => 'smallInteger', 'auto_increment' => true],
    ];

    /**
     * Default SQLite type mappings.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $sqliteMappings = [
        'integer' => ['method' => 'integer'],
        'real' => ['method' => 'float'],
        'text' => ['method' => 'text'],
        'blob' => ['method' => 'binary'],
        'numeric' => ['method' => 'decimal'],
    ];

    /**
     * Default SQL Server type mappings.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $sqlsrvMappings = [
        'bigint' => ['method' => 'bigInteger'],
        'int' => ['method' => 'integer'],
        'smallint' => ['method' => 'smallInteger'],
        'tinyint' => ['method' => 'tinyInteger'],
        'decimal' => ['method' => 'decimal'],
        'numeric' => ['method' => 'decimal'],
        'money' => ['method' => 'decimal', 'precision' => 19, 'scale' => 4],
        'smallmoney' => ['method' => 'decimal', 'precision' => 10, 'scale' => 4],
        'float' => ['method' => 'float'],
        'real' => ['method' => 'float'],
        'nvarchar' => ['method' => 'string'],
        'varchar' => ['method' => 'string'],
        'nchar' => ['method' => 'char'],
        'char' => ['method' => 'char'],
        'ntext' => ['method' => 'text'],
        'text' => ['method' => 'text'],
        'binary' => ['method' => 'binary'],
        'varbinary' => ['method' => 'binary'],
        'image' => ['method' => 'binary'],
        'date' => ['method' => 'date', 'cast' => 'date'],
        'datetime' => ['method' => 'dateTime', 'cast' => 'datetime'],
        'datetime2' => ['method' => 'dateTime', 'cast' => 'datetime'],
        'datetimeoffset' => ['method' => 'dateTimeTz', 'cast' => 'datetime'],
        'smalldatetime' => ['method' => 'dateTime', 'cast' => 'datetime'],
        'time' => ['method' => 'time'],
        'bit' => ['method' => 'boolean', 'cast' => 'boolean'],
        'uniqueidentifier' => ['method' => 'uuid'],
        'xml' => ['method' => 'text'],
        'geography' => ['method' => 'geography'],
        'geometry' => ['method' => 'geometry'],
    ];

    /**
     * @param array<string, array<string, mixed>> $customMappings
     */
    public function __construct(array $customMappings = [])
    {
        $this->customMappings = $customMappings;
    }

    /**
     * Get the Laravel migration method for a database type.
     */
    public function getMigrationMethod(ColumnSchema $column, string $driver): string
    {
        $mapping = $this->getMapping($column->type, $driver);
        $method = $mapping['method'] ?? 'string';

        // Handle special cases
        if ($column->autoIncrement) {
            return $this->getAutoIncrementMethod($method, $column->unsigned);
        }

        if ($column->unsigned && $this->isIntegerType($method)) {
            return 'unsigned' . ucfirst($method);
        }

        return $method;
    }

    /**
     * Get the PHP cast type for a column.
     */
    public function getCastType(ColumnSchema $column, string $driver): ?string
    {
        $mapping = $this->getMapping($column->type, $driver);

        return $mapping['cast'] ?? null;
    }

    /**
     * Get the complete mapping for a type.
     *
     * @return array<string, mixed>
     */
    public function getMapping(string $type, string $driver): array
    {
        // Check custom mappings first
        $normalizedType = strtolower($type);

        if (isset($this->customMappings[$normalizedType])) {
            return $this->normalizeMapping($this->customMappings[$normalizedType]);
        }

        $mappings = $this->getMappingsForDriver($driver);

        return $mappings[$normalizedType] ?? ['method' => 'string'];
    }

    /**
     * Build the migration method call with parameters.
     */
    public function buildMethodCall(ColumnSchema $column, string $driver): string
    {
        $method = $this->getMigrationMethod($column, $driver);
        $params = [$this->quote($column->name)];

        // Add type-specific parameters
        if (in_array($method, ['decimal', 'unsignedDecimal'], true)) {
            if ($column->precision !== null) {
                $params[] = (string) $column->precision;
                $params[] = (string) ($column->scale ?? 0);
            }
        } elseif (in_array($method, ['string', 'char'], true) && $column->length !== null) {
            $params[] = (string) $column->length;
        } elseif (in_array($method, ['enum', 'set'], true)) {
            $values = $column->getEnumValues();
            $quotedValues = array_map(fn ($v) => $this->quote($v), $values);
            $params[] = '[' . implode(', ', $quotedValues) . ']';
        } elseif (in_array($method, ['float', 'double'], true) && $column->precision !== null) {
            $params[] = (string) $column->precision;
        }

        return "\$table->{$method}(" . implode(', ', $params) . ')';
    }

    /**
     * Get mappings for a specific driver.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function getMappingsForDriver(string $driver): array
    {
        return match ($driver) {
            'mysql', 'mariadb' => $this->mysqlMappings,
            'pgsql' => $this->pgsqlMappings,
            'sqlite' => $this->sqliteMappings,
            'sqlsrv' => $this->sqlsrvMappings,
            default => $this->mysqlMappings,
        };
    }

    /**
     * Get the auto-increment method variant.
     */
    protected function getAutoIncrementMethod(string $baseMethod, bool $unsigned): string
    {
        return match ($baseMethod) {
            'bigInteger', 'unsignedBigInteger' => 'id',
            'integer', 'unsignedInteger' => $unsigned ? 'increments' : 'integerIncrements',
            'mediumInteger' => 'mediumIncrements',
            'smallInteger' => 'smallIncrements',
            'tinyInteger' => 'tinyIncrements',
            default => 'id',
        };
    }

    /**
     * Check if a method represents an integer type.
     */
    protected function isIntegerType(string $method): bool
    {
        return in_array($method, [
            'integer',
            'bigInteger',
            'mediumInteger',
            'smallInteger',
            'tinyInteger',
        ], true);
    }

    /**
     * Normalize a mapping to array format.
     *
     * @param string|array<string, mixed> $mapping
     * @return array<string, mixed>
     */
    protected function normalizeMapping(string|array $mapping): array
    {
        if (is_string($mapping)) {
            return ['method' => $mapping];
        }

        return $mapping;
    }

    /**
     * Quote a string for use in generated code.
     */
    protected function quote(string $value): string
    {
        return "'" . addslashes($value) . "'";
    }

    /**
     * Add a custom type mapping.
     */
    public function addMapping(string $type, string|array $mapping): self
    {
        $this->customMappings[strtolower($type)] = $this->normalizeMapping($mapping);

        return $this;
    }

    /**
     * Get all mappings for a driver including custom mappings.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllMappings(string $driver): array
    {
        return array_merge(
            $this->getMappingsForDriver($driver),
            $this->customMappings
        );
    }
}
