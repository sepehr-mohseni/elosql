<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\ValueObjects;

use JsonSerializable;

final class ForeignKeySchema implements JsonSerializable
{
    public const ACTION_CASCADE = 'CASCADE';
    public const ACTION_SET_NULL = 'SET NULL';
    public const ACTION_SET_DEFAULT = 'SET DEFAULT';
    public const ACTION_RESTRICT = 'RESTRICT';
    public const ACTION_NO_ACTION = 'NO ACTION';

    /**
     * @param array<string> $columns
     * @param array<string> $referencedColumns
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly string $referencedTable,
        public readonly array $referencedColumns,
        public readonly string $onDelete = self::ACTION_RESTRICT,
        public readonly string $onUpdate = self::ACTION_RESTRICT,
    ) {}

    public function isComposite(): bool
    {
        return count($this->columns) > 1;
    }

    public function getLocalColumn(): string
    {
        return $this->columns[0];
    }

    public function getReferencedColumn(): string
    {
        return $this->referencedColumns[0];
    }

    public function hasCascadeDelete(): bool
    {
        return $this->onDelete === self::ACTION_CASCADE;
    }

    public function hasSetNullDelete(): bool
    {
        return $this->onDelete === self::ACTION_SET_NULL;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'columns' => $this->columns,
            'referenced_table' => $this->referencedTable,
            'referenced_columns' => $this->referencedColumns,
            'on_delete' => $this->onDelete,
            'on_update' => $this->onUpdate,
        ];
    }
}
