<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Support;

use Illuminate\Support\Str;

class NameConverter
{
    /**
     * Convert table name to model class name.
     */
    public function tableToModelName(string $tableName): string
    {
        return Str::studly(Str::singular(strtolower($tableName)));
    }

    /**
     * Convert table name to expected file name for the model.
     */
    public function tableToModelFileName(string $tableName): string
    {
        return $this->tableToModelName($tableName) . '.php';
    }

    /**
     * Convert model name to table name.
     */
    public function modelToTableName(string $modelName): string
    {
        return Str::snake(Str::pluralStudly($modelName));
    }

    /**
     * Convert column name to relationship method name for belongsTo.
     */
    public function foreignKeyToRelationName(string $columnName): string
    {
        // Remove common FK suffixes (_id, _uuid, _key)
        $name = preg_replace('/_(id|uuid|key)$/', '', $columnName);

        return Str::camel($name);
    }

    /**
     * Convert table name to relationship method name for hasMany/hasOne.
     */
    public function tableToRelationName(string $tableName, bool $plural = true): string
    {
        $name = $plural ? Str::plural($tableName) : Str::singular($tableName);

        return Str::camel($name);
    }

    /**
     * Convert to StudlyCase.
     */
    public function studly(string $value): string
    {
        return Str::studly($value);
    }

    /**
     * Convert to camelCase.
     */
    public function camel(string $value): string
    {
        return Str::camel($value);
    }

    /**
     * Convert to snake_case.
     */
    public function snake(string $value): string
    {
        return Str::snake($value);
    }

    /**
     * Get singular form.
     */
    public function singular(string $value): string
    {
        return Str::singular($value);
    }

    /**
     * Get plural form.
     */
    public function plural(string $value): string
    {
        return Str::plural($value);
    }

    /**
     * Generate migration class name from table name.
     */
    public function tableToMigrationClassName(string $tableName): string
    {
        return 'Create' . Str::studly($tableName) . 'Table';
    }

    /**
     * Generate foreign key migration class name.
     */
    public function tableToForeignKeyMigrationClassName(string $tableName): string
    {
        return 'Add' . Str::studly($tableName) . 'ForeignKeys';
    }

    /**
     * Convert column name to scope method name.
     */
    public function columnToScopeName(string $columnName): string
    {
        return 'scope' . Str::studly($columnName);
    }

    /**
     * Convert column name to accessor method name.
     */
    public function columnToAccessorName(string $columnName): string
    {
        return Str::camel($columnName);
    }

    /**
     * Determine if a table name follows pivot table convention.
     */
    public function isPivotTableName(string $tableName, string $table1, string $table2): bool
    {
        $singular1 = Str::singular($table1);
        $singular2 = Str::singular($table2);

        // Alphabetically ordered
        $tables = [$singular1, $singular2];
        sort($tables);

        $expectedName = implode('_', $tables);

        return $tableName === $expectedName;
    }

    /**
     * Generate pivot table name from two table names.
     */
    public function generatePivotTableName(string $table1, string $table2): string
    {
        $singular1 = Str::singular($table1);
        $singular2 = Str::singular($table2);

        $tables = [$singular1, $singular2];
        sort($tables);

        return implode('_', $tables);
    }

    /**
     * Convert column name to property name (camelCase).
     */
    public function columnToPropertyName(string $columnName): string
    {
        // If already camelCase, return as-is
        if (preg_match('/^[a-z]+[a-zA-Z]*$/', $columnName) && ! str_contains($columnName, '_')) {
            return $columnName;
        }

        return Str::camel(strtolower($columnName));
    }

    /**
     * Get relation name for hasMany relationships.
     */
    public function tableToHasManyRelation(string $tableName): string
    {
        return Str::camel($tableName);
    }

    /**
     * Get relation name for hasOne relationships.
     */
    public function tableToHasOneRelation(string $tableName): string
    {
        return Str::camel(Str::singular($tableName));
    }

    /**
     * Get relation name from pivot table for the "other" side.
     */
    public function pivotToRelationName(string $pivotTable, string $currentTable): string
    {
        // Extract the other table name from pivot
        $parts = explode('_', $pivotTable);
        $currentSingular = Str::singular($currentTable);

        // Find the part that isn't the current table
        $otherTable = null;
        foreach ($parts as $part) {
            if ($part !== $currentSingular && $part !== Str::singular($currentSingular)) {
                $otherTable = $part;
                break;
            }
        }

        return $otherTable ? Str::plural($otherTable) : $pivotTable;
    }

    /**
     * Pluralize or singularize a word.
     */
    public function pluralize(string $value, bool $plural = true): string
    {
        return $plural ? Str::plural($value) : Str::singular($value);
    }

    /**
     * Convert to snake_case (alias for snake).
     */
    public function snakeCase(string $value): string
    {
        return Str::snake($value);
    }

    /**
     * Convert to StudlyCase (alias for studly).
     */
    public function studlyCase(string $value): string
    {
        return Str::studly($value);
    }

    /**
     * Convert to camelCase (alias for camel).
     */
    public function camelCase(string $value): string
    {
        return Str::camel($value);
    }

    /**
     * Check if a table name appears to be a pivot table.
     *
     * @param string $tableName
     * @param array<string> $allTables
     *
     * @return bool
     */
    public function isPivotTable(string $tableName, array $allTables): bool
    {
        $parts = explode('_', $tableName);

        if (count($parts) !== 2) {
            return false;
        }

        // Check if both parts correspond to existing tables (in singular form)
        $singularTables = array_map(fn ($t) => Str::singular($t), $allTables);

        return in_array($parts[0], $singularTables, true)
            && in_array($parts[1], $singularTables, true);
    }

    /**
     * Get the two related tables from a pivot table name.
     *
     * @param string $pivotTable
     * @param array<string> $allTables
     *
     * @return array<string>
     */
    public function getPivotRelations(string $pivotTable, array $allTables): array
    {
        $parts = explode('_', $pivotTable);
        sort($parts);

        return $parts;
    }

    /**
     * Generate migration class name from table name.
     */
    public function toMigrationClassName(string $tableName): string
    {
        return 'Create' . Str::studly($tableName) . 'Table';
    }

    /**
     * Generate foreign key migration class name.
     */
    public function toForeignKeyMigrationClassName(string $tableName): string
    {
        return 'AddForeignKeysTo' . Str::studly($tableName) . 'Table';
    }
}
