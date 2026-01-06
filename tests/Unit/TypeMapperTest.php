<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Tests\Unit;

use Sepehr_Mohseni\Elosql\Support\TypeMapper;
use Sepehr_Mohseni\Elosql\Tests\TestCase;
use Sepehr_Mohseni\Elosql\ValueObjects\ColumnSchema;

class TypeMapperTest extends TestCase
{
    private TypeMapper $typeMapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->typeMapper = new TypeMapper();
    }

    /**
     * @dataProvider mysqlIntegerTypesProvider
     */
    public function test_maps_mysql_integer_types_correctly(string $type, bool $unsigned, bool $autoIncrement, string $expected): void
    {
        $column = $this->createColumn($type, unsigned: $unsigned, autoIncrement: $autoIncrement);

        $result = $this->typeMapper->getMigrationMethod($column, 'mysql');

        $this->assertEquals($expected, $result);
    }

    public static function mysqlIntegerTypesProvider(): array
    {
        return [
            'bigint unsigned auto_increment' => ['bigint', true, true, 'id'],
            'bigint unsigned' => ['bigint', true, false, 'unsignedBigInteger'],
            'bigint signed' => ['bigint', false, false, 'bigInteger'],
            'int unsigned auto_increment' => ['int', true, true, 'increments'],
            'int unsigned' => ['int', true, false, 'unsignedInteger'],
            'int signed' => ['int', false, false, 'integer'],
            'mediumint' => ['mediumint', false, false, 'mediumInteger'],
            'smallint' => ['smallint', false, false, 'smallInteger'],
            'tinyint' => ['tinyint', false, false, 'tinyInteger'],
        ];
    }

    /**
     * @dataProvider mysqlStringTypesProvider
     */
    public function test_maps_mysql_string_types_correctly(string $type, string $expected): void
    {
        $column = $this->createColumn($type);

        $result = $this->typeMapper->getMigrationMethod($column, 'mysql');

        $this->assertEquals($expected, $result);
    }

    public static function mysqlStringTypesProvider(): array
    {
        return [
            'varchar' => ['varchar', 'string'],
            'char' => ['char', 'char'],
            'text' => ['text', 'text'],
            'mediumtext' => ['mediumtext', 'mediumText'],
            'longtext' => ['longtext', 'longText'],
            'tinytext' => ['tinytext', 'tinyText'],
        ];
    }

    /**
     * @dataProvider mysqlDateTimeTypesProvider
     */
    public function test_maps_mysql_datetime_types_correctly(string $type, string $expected): void
    {
        $column = $this->createColumn($type);

        $result = $this->typeMapper->getMigrationMethod($column, 'mysql');

        $this->assertEquals($expected, $result);
    }

    public static function mysqlDateTimeTypesProvider(): array
    {
        return [
            'date' => ['date', 'date'],
            'datetime' => ['datetime', 'dateTime'],
            'timestamp' => ['timestamp', 'timestamp'],
            'time' => ['time', 'time'],
            'year' => ['year', 'year'],
        ];
    }

    public function test_maps_mysql_json_type(): void
    {
        $column = $this->createColumn('json');

        $result = $this->typeMapper->getMigrationMethod($column, 'mysql');

        $this->assertEquals('json', $result);
    }

    public function test_maps_mysql_enum_type(): void
    {
        $column = $this->createColumn('enum', attributes: ['enum_values' => ['draft', 'published', 'archived']]);

        $result = $this->typeMapper->getMigrationMethod($column, 'mysql');

        $this->assertEquals('enum', $result);
    }

    public function test_builds_method_call_with_column_name(): void
    {
        $column = $this->createColumn('varchar', name: 'title', length: 255);

        $result = $this->typeMapper->buildMethodCall($column, 'mysql');

        $this->assertEquals("\$table->string('title', 255)", $result);
    }

    public function test_builds_method_call_for_decimal(): void
    {
        $column = $this->createColumn('decimal', name: 'price', precision: 10, scale: 2);

        $result = $this->typeMapper->buildMethodCall($column, 'mysql');

        $this->assertEquals("\$table->decimal('price', 10, 2)", $result);
    }

    public function test_builds_method_call_for_enum(): void
    {
        $column = $this->createColumn('enum', name: 'status', attributes: ['enum_values' => ['draft', 'published']]);

        $result = $this->typeMapper->buildMethodCall($column, 'mysql');

        $this->assertEquals("\$table->enum('status', ['draft', 'published'])", $result);
    }

    public function test_gets_cast_type_for_json(): void
    {
        $column = $this->createColumn('json');

        $result = $this->typeMapper->getCastType($column, 'mysql');

        $this->assertEquals('array', $result);
    }

    public function test_gets_cast_type_for_boolean(): void
    {
        $column = $this->createColumn('boolean');

        $result = $this->typeMapper->getCastType($column, 'mysql');

        $this->assertEquals('boolean', $result);
    }

    public function test_gets_cast_type_for_datetime(): void
    {
        $column = $this->createColumn('datetime');

        $result = $this->typeMapper->getCastType($column, 'mysql');

        $this->assertEquals('datetime', $result);
    }

    public function test_custom_mapping_overrides_default(): void
    {
        $this->typeMapper->addMapping('custom_type', 'text');

        $column = $this->createColumn('custom_type');

        $result = $this->typeMapper->getMigrationMethod($column, 'mysql');

        $this->assertEquals('text', $result);
    }

    public function test_custom_mapping_with_cast(): void
    {
        $this->typeMapper->addMapping('money', ['method' => 'decimal', 'cast' => 'float']);

        $column = $this->createColumn('money');

        $cast = $this->typeMapper->getCastType($column, 'mysql');

        $this->assertEquals('float', $cast);
    }

    /**
     * @dataProvider postgresTypesProvider
     */
    public function test_maps_postgres_types_correctly(string $type, string $expected): void
    {
        $column = $this->createColumn($type);

        $result = $this->typeMapper->getMigrationMethod($column, 'pgsql');

        $this->assertEquals($expected, $result);
    }

    public static function postgresTypesProvider(): array
    {
        return [
            'bigint' => ['bigint', 'bigInteger'],
            'integer' => ['integer', 'integer'],
            'smallint' => ['smallint', 'smallInteger'],
            'text' => ['text', 'text'],
            'boolean' => ['boolean', 'boolean'],
            'json' => ['json', 'json'],
            'jsonb' => ['jsonb', 'jsonb'],
            'uuid' => ['uuid', 'uuid'],
            'inet' => ['inet', 'ipAddress'],
            'macaddr' => ['macaddr', 'macAddress'],
        ];
    }

    /**
     * @dataProvider sqliteTypesProvider
     */
    public function test_maps_sqlite_types_correctly(string $type, string $expected): void
    {
        $column = $this->createColumn($type);

        $result = $this->typeMapper->getMigrationMethod($column, 'sqlite');

        $this->assertEquals($expected, $result);
    }

    public static function sqliteTypesProvider(): array
    {
        return [
            'integer' => ['integer', 'integer'],
            'real' => ['real', 'float'],
            'text' => ['text', 'text'],
            'blob' => ['blob', 'binary'],
            'numeric' => ['numeric', 'decimal'],
        ];
    }

    public function test_gets_all_mappings_for_driver(): void
    {
        $mappings = $this->typeMapper->getAllMappings('mysql');

        $this->assertArrayHasKey('bigint', $mappings);
        $this->assertArrayHasKey('varchar', $mappings);
        $this->assertArrayHasKey('json', $mappings);
    }

    /**
     * Helper to create a ColumnSchema for testing.
     */
    private function createColumn(
        string $type,
        string $name = 'test_column',
        bool $nullable = false,
        mixed $default = null,
        bool $autoIncrement = false,
        bool $unsigned = false,
        ?int $length = null,
        ?int $precision = null,
        ?int $scale = null,
        array $attributes = []
    ): ColumnSchema {
        return new ColumnSchema(
            name: $name,
            type: $type,
            nativeType: $type,
            nullable: $nullable,
            default: $default,
            autoIncrement: $autoIncrement,
            unsigned: $unsigned,
            length: $length,
            precision: $precision,
            scale: $scale,
            charset: null,
            collation: null,
            comment: null,
            attributes: $attributes,
        );
    }
}
