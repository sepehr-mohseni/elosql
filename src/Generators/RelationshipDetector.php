<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Generators;

use Sepehr_Mohseni\Elosql\Support\NameConverter;
use Sepehr_Mohseni\Elosql\ValueObjects\ForeignKeySchema;
use Sepehr_Mohseni\Elosql\ValueObjects\TableSchema;

class RelationshipDetector
{
    public function __construct(
        protected NameConverter $nameConverter,
    ) {}

    /**
     * Detect all relationships for a table (alias for detectRelationships).
     *
     * @param array<TableSchema> $allTables
     * @param array<string, mixed> $options
     * @return array<array<string, mixed>>
     */
    public function detect(TableSchema $table, array $allTables, array $options = []): array
    {
        return $this->detectRelationships($table, $allTables, $options);
    }

    /**
     * Detect all relationships for a table.
     *
     * @param array<TableSchema> $allTables
     * @param array<string, mixed> $options
     * @return array<array<string, mixed>>
     */
    public function detectRelationships(TableSchema $table, array $allTables, array $options = []): array
    {
        $relationships = [];

        // Detect belongsTo relationships from foreign keys
        foreach ($table->foreignKeys as $fk) {
            $relationships[] = $this->buildBelongsToRelationship($fk, $table);
        }

        // Detect hasMany/hasOne relationships (inverse of foreign keys)
        foreach ($allTables as $otherTable) {
            if ($otherTable->name === $table->name) {
                continue;
            }

            foreach ($otherTable->foreignKeys as $fk) {
                if ($fk->referencedTable === $table->name) {
                    $relationships[] = $this->buildHasManyRelationship($fk, $otherTable, $table);
                }
            }
        }

        // Detect belongsToMany relationships (pivot tables)
        $pivotRelationships = $this->detectPivotRelationships($table, $allTables);
        $relationships = array_merge($relationships, $pivotRelationships);

        // Detect polymorphic relationships (morphTo)
        $polymorphicRelationships = $this->detectPolymorphicRelationships($table, $allTables);
        $relationships = array_merge($relationships, $polymorphicRelationships);

        return $relationships;
    }

    /**
     * Build a belongsTo relationship from a foreign key.
     *
     * @return array<string, mixed>
     */
    protected function buildBelongsToRelationship(ForeignKeySchema $fk, TableSchema $table): array
    {
        $relatedTable = $fk->referencedTable;
        $relatedModel = $this->nameConverter->tableToModelName($relatedTable);
        $methodName = $this->nameConverter->foreignKeyToRelationName($fk->getLocalColumn());

        // Check if it's a self-referencing relationship
        $isSelfReferencing = $relatedTable === $table->name;

        return [
            'type' => 'belongsTo',
            'method' => $methodName,
            'related_model' => $relatedModel,
            'related_table' => $relatedTable,
            'foreign_key' => $fk->getLocalColumn(),
            'owner_key' => $fk->getReferencedColumn(),
            'is_self_referencing' => $isSelfReferencing,
        ];
    }

    /**
     * Build a hasMany/hasOne relationship from an inverse foreign key.
     *
     * @return array<string, mixed>
     */
    protected function buildHasManyRelationship(
        ForeignKeySchema $fk,
        TableSchema $relatedTable,
        TableSchema $currentTable
    ): array {
        $relatedModel = $this->nameConverter->tableToModelName($relatedTable->name);

        // Determine if it should be hasOne or hasMany
        // hasOne if the foreign key column has a unique constraint
        $isHasOne = $this->hasUniqueConstraintOnColumn($relatedTable, $fk->getLocalColumn());

        $type = $isHasOne ? 'hasOne' : 'hasMany';
        $methodName = $isHasOne
            ? $this->nameConverter->tableToRelationName($relatedTable->name, false)
            : $this->nameConverter->tableToRelationName($relatedTable->name, true);

        return [
            'type' => $type,
            'method' => $methodName,
            'related_model' => $relatedModel,
            'related_table' => $relatedTable->name,
            'foreign_key' => $fk->getLocalColumn(),
            'local_key' => $fk->getReferencedColumn(),
        ];
    }

    /**
     * Detect belongsToMany relationships through pivot tables.
     *
     * @param array<TableSchema> $allTables
     * @return array<array<string, mixed>>
     */
    protected function detectPivotRelationships(TableSchema $table, array $allTables): array
    {
        $relationships = [];

        foreach ($allTables as $potentialPivot) {
            if (!$this->isPivotTable($potentialPivot)) {
                continue;
            }

            // Check if this pivot table connects to our table
            $ourFk = null;
            $otherFk = null;

            foreach ($potentialPivot->foreignKeys as $fk) {
                if ($fk->referencedTable === $table->name) {
                    $ourFk = $fk;
                } else {
                    $otherFk = $fk;
                }
            }

            if ($ourFk !== null && $otherFk !== null) {
                $relatedModel = $this->nameConverter->tableToModelName($otherFk->referencedTable);
                $methodName = $this->nameConverter->tableToRelationName($otherFk->referencedTable, true);

                $relationships[] = [
                    'type' => 'belongsToMany',
                    'method' => $methodName,
                    'related_model' => $relatedModel,
                    'related_table' => $otherFk->referencedTable,
                    'pivot_table' => $potentialPivot->name,
                    'foreign_pivot_key' => $ourFk->getLocalColumn(),
                    'related_pivot_key' => $otherFk->getLocalColumn(),
                    'pivot_columns' => $this->getPivotExtraColumns($potentialPivot, $ourFk, $otherFk),
                ];
            }
        }

        return $relationships;
    }

    /**
     * Check if a table is a pivot table.
     */
    protected function isPivotTable(TableSchema $table): bool
    {
        // Must have exactly 2 foreign keys
        if (count($table->foreignKeys) !== 2) {
            return false;
        }

        // Table name should follow convention (singular_singular)
        if (!preg_match('/^[a-z0-9]+_[a-z0-9]+$/i', $table->name)) {
            return false;
        }

        // Should have limited columns
        $columnCount = count($table->columns);
        if ($columnCount > 7) {
            return false;
        }

        return true;
    }

    /**
     * Get extra columns in a pivot table (excluding FKs, id, and timestamps).
     *
     * @return array<string>
     */
    protected function getPivotExtraColumns(
        TableSchema $pivotTable,
        ForeignKeySchema $fk1,
        ForeignKeySchema $fk2
    ): array {
        $fkColumns = array_merge($fk1->columns, $fk2->columns);
        $excludeColumns = array_merge($fkColumns, ['id', 'created_at', 'updated_at']);

        $extraColumns = [];
        foreach ($pivotTable->columns as $column) {
            if (!in_array($column->name, $excludeColumns, true)) {
                $extraColumns[] = $column->name;
            }
        }

        return $extraColumns;
    }

    /**
     * Check if a column has a unique constraint.
     */
    protected function hasUniqueConstraintOnColumn(TableSchema $table, string $columnName): bool
    {
        foreach ($table->indexes as $index) {
            if ($index->isUnique() && count($index->columns) === 1 && $index->columns[0] === $columnName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect polymorphic relationships.
     *
     * @param array<TableSchema> $allTables
     * @return array<array<string, mixed>>
     */
    public function detectPolymorphicRelationships(TableSchema $table, array $allTables): array
    {
        $relationships = [];

        // Look for *_type and *_id column pairs
        foreach ($table->columns as $column) {
            if (!str_ends_with($column->name, '_type')) {
                continue;
            }

            $baseName = substr($column->name, 0, -5);
            $idColumn = $baseName . '_id';

            if (!$table->hasColumn($idColumn)) {
                continue;
            }

            $relationships[] = [
                'type' => 'morphTo',
                'method' => $this->nameConverter->camel($baseName),
                'morph_name' => $baseName,
                'type_column' => $column->name,
                'id_column' => $idColumn,
            ];
        }

        return $relationships;
    }

    /**
     * Generate a relationship method stub.
     *
     * @param array<string, mixed> $relationship
     */
    public function generateRelationshipMethod(array $relationship): string
    {
        $method = $relationship['method'] ?? 'relationship';
        $type = $relationship['type'] ?? 'belongsTo';
        $related = $relationship['related'] ?? $relationship['related_model'] ?? 'Model';
        $foreignKey = $relationship['foreignKey'] ?? $relationship['foreign_key'] ?? null;
        $ownerKey = $relationship['ownerKey'] ?? $relationship['owner_key'] ?? null;
        $pivotTable = $relationship['pivot_table'] ?? null;
        $foreignPivotKey = $relationship['foreign_pivot_key'] ?? null;
        $relatedPivotKey = $relationship['related_pivot_key'] ?? null;
        $pivotColumns = $relationship['pivot_columns'] ?? [];

        $indent = '    ';
        $lines = [];
        $lines[] = "{$indent}public function {$method}()";
        $lines[] = "{$indent}{";

        switch ($type) {
            case 'belongsTo':
                $args = ["\"{$related}\""];
                if ($foreignKey) {
                    $args[] = "'{$foreignKey}'";
                }
                if ($ownerKey && $ownerKey !== 'id') {
                    $args[] = "'{$ownerKey}'";
                }
                $lines[] = "{$indent}{$indent}return \$this->belongsTo(" . implode(', ', $args) . ');';
                break;

            case 'hasMany':
                $args = ["\"{$related}\""];
                if ($foreignKey) {
                    $args[] = "'{$foreignKey}'";
                }
                $lines[] = "{$indent}{$indent}return \$this->hasMany(" . implode(', ', $args) . ');';
                break;

            case 'hasOne':
                $args = ["\"{$related}\""];
                if ($foreignKey) {
                    $args[] = "'{$foreignKey}'";
                }
                $lines[] = "{$indent}{$indent}return \$this->hasOne(" . implode(', ', $args) . ');';
                break;

            case 'belongsToMany':
                $args = ["\"{$related}\""];
                if ($pivotTable) {
                    $args[] = "'{$pivotTable}'";
                }
                if ($foreignPivotKey) {
                    $args[] = "'{$foreignPivotKey}'";
                }
                if ($relatedPivotKey) {
                    $args[] = "'{$relatedPivotKey}'";
                }
                $methodCall = "{$indent}{$indent}return \$this->belongsToMany(" . implode(', ', $args) . ')';
                if (!empty($pivotColumns)) {
                    $methodCall .= "\n{$indent}{$indent}{$indent}->withPivot('" . implode("', '", $pivotColumns) . "')";
                }
                $lines[] = $methodCall . ';';
                break;

            default:
                $lines[] = "{$indent}{$indent}return \$this->{$type}('{$related}');";
        }

        $lines[] = "{$indent}}";

        return implode("\n", $lines);
    }
}
