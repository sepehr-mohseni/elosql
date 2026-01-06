<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\ValueObjects;

use JsonSerializable;

final class TableSchema implements JsonSerializable
{
    /**
     * @param array<ColumnSchema> $columns
     * @param array<IndexSchema> $indexes
     * @param array<ForeignKeySchema> $foreignKeys
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly array $indexes = [],
        public readonly array $foreignKeys = [],
        public readonly ?string $engine = null,
        public readonly ?string $charset = null,
        public readonly ?string $collation = null,
        public readonly ?string $comment = null,
        public readonly array $attributes = [],
    ) {
    }

    public function getColumn(string $name): ?ColumnSchema
    {
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }

        return null;
    }

    public function hasColumn(string $name): bool
    {
        return $this->getColumn($name) !== null;
    }

    /**
     * @return array<string>
     */
    public function getColumnNames(): array
    {
        return array_map(fn (ColumnSchema $col) => $col->name, $this->columns);
    }

    public function getPrimaryKey(): ?IndexSchema
    {
        foreach ($this->indexes as $index) {
            if ($index->isPrimary()) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return array<string>
     */
    public function getPrimaryKeyColumns(): array
    {
        $pk = $this->getPrimaryKey();

        return $pk?->columns ?? [];
    }

    public function hasCompositePrimaryKey(): bool
    {
        $pk = $this->getPrimaryKey();

        return $pk !== null && count($pk->columns) > 1;
    }

    public function hasTimestamps(): bool
    {
        return $this->hasColumn('created_at') && $this->hasColumn('updated_at');
    }

    public function hasSoftDeletes(): bool
    {
        return $this->hasColumn('deleted_at');
    }

    /**
     * @return array<IndexSchema>
     */
    public function getUniqueIndexes(): array
    {
        return array_filter($this->indexes, fn (IndexSchema $idx) => $idx->isUnique());
    }

    /**
     * @return array<IndexSchema>
     */
    public function getNonPrimaryIndexes(): array
    {
        return array_filter($this->indexes, fn (IndexSchema $idx) => ! $idx->isPrimary());
    }

    /**
     * @return array<ForeignKeySchema>
     */
    public function getForeignKeysReferencingTable(string $tableName): array
    {
        return array_filter(
            $this->foreignKeys,
            fn (ForeignKeySchema $fk) => $fk->referencedTable === $tableName
        );
    }

    public function isSelfReferencing(): bool
    {
        foreach ($this->foreignKeys as $fk) {
            if ($fk->referencedTable === $this->name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if this table appears to be a pivot table for many-to-many relationships.
     */
    public function isPivotTable(): bool
    {
        // Must have exactly 2 foreign keys
        if (count($this->foreignKeys) !== 2) {
            return false;
        }

        // Name should follow convention: table1_table2
        if (! preg_match('/^[a-z0-9]+_[a-z0-9]+$/i', $this->name)) {
            return false;
        }

        // Should have limited columns (FKs + optional timestamps + optional id + few extras)
        $columnCount = count($this->columns);
        $maxExpectedColumns = 6; // 2 FKs + id + created_at + updated_at + 1 extra

        if ($columnCount > $maxExpectedColumns) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'columns' => array_map(fn (ColumnSchema $col) => $col->jsonSerialize(), $this->columns),
            'indexes' => array_map(fn (IndexSchema $idx) => $idx->jsonSerialize(), $this->indexes),
            'foreign_keys' => array_map(fn (ForeignKeySchema $fk) => $fk->jsonSerialize(), $this->foreignKeys),
            'engine' => $this->engine,
            'charset' => $this->charset,
            'collation' => $this->collation,
            'comment' => $this->comment,
            'attributes' => $this->attributes,
        ];
    }
}
