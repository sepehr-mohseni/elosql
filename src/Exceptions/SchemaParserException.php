<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Exceptions;

use Exception;

class SchemaParserException extends Exception
{
    public static function connectionFailed(string $connection, string $message): self
    {
        return new self("Failed to connect to database '{$connection}': {$message}");
    }

    public static function unsupportedDriver(string $driver): self
    {
        return new self("Unsupported database driver: {$driver}. Supported drivers: mysql, pgsql, sqlite, sqlsrv");
    }

    public static function tableNotFound(string $table): self
    {
        return new self("Table '{$table}' not found in the database");
    }

    public static function queryFailed(string $query, string $message): self
    {
        return new self("Schema query failed: {$message}\nQuery: {$query}");
    }

    public static function invalidSchema(string $message): self
    {
        return new self("Invalid schema structure: {$message}");
    }

    public static function circularDependency(string $cycle): self
    {
        return new self("Circular dependency detected: {$cycle}");
    }
}
