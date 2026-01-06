<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Exceptions;

use Exception;

class GeneratorException extends Exception
{
    public static function fileWriteFailed(string $path, string $message): self
    {
        return new self("Failed to write file '{$path}': {$message}");
    }

    public static function directoryCreateFailed(string $path): self
    {
        return new self("Failed to create directory: {$path}");
    }

    public static function fileAlreadyExists(string $path): self
    {
        return new self("File already exists: {$path}. Use --force to overwrite.");
    }

    public static function invalidTableName(string $table): self
    {
        return new self("Invalid table name for migration: {$table}");
    }

    public static function circularDependency(string $tables): self
    {
        return new self("Circular foreign key dependency detected between tables: {$tables}");
    }

    public static function templateNotFound(string $template): self
    {
        return new self("Template file not found: {$template}");
    }

    public static function invalidConfiguration(string $key, string $message): self
    {
        return new self("Invalid configuration for '{$key}': {$message}");
    }
}
