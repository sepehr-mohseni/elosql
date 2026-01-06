<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Parsers;

use Illuminate\Database\Connection;
use Sepehr_Mohseni\Elosql\ValueObjects\TableSchema;

interface SchemaParser
{
    /**
     * Set the database connection to use.
     */
    public function setConnection(Connection $connection): self;

    /**
     * Get the database driver name.
     */
    public function getDriver(): string;

    /**
     * Get all table names in the database.
     *
     * @param array<string> $excludeTables Tables to exclude
     *
     * @return array<string>
     */
    public function getTables(array $excludeTables = []): array;

    /**
     * Parse a table and return its schema.
     */
    public function parseTable(string $tableName): TableSchema;

    /**
     * Parse all tables and return their schemas.
     *
     * @param array<string> $excludeTables Tables to exclude
     *
     * @return array<TableSchema>
     */
    public function parseAllTables(array $excludeTables = []): array;

    /**
     * Check if a table exists.
     */
    public function tableExists(string $tableName): bool;

    /**
     * Get the database name.
     */
    public function getDatabaseName(): string;
}
