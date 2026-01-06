<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\ValueObjects;

use JsonSerializable;

final class ColumnSchema implements JsonSerializable
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $nativeType,
        public readonly bool $nullable,
        public readonly mixed $default,
        public readonly bool $autoIncrement,
        public readonly bool $unsigned,
        public readonly ?int $length,
        public readonly ?int $precision,
        public readonly ?int $scale,
        public readonly ?string $charset,
        public readonly ?string $collation,
        public readonly ?string $comment,
        public readonly array $attributes = [],
    ) {
    }

    public function hasDefault(): bool
    {
        return $this->default !== null || ($this->nullable && $this->default === null);
    }

    public function isNullDefault(): bool
    {
        return $this->nullable && $this->default === null;
    }

    public function isPrimaryKey(): bool
    {
        return $this->attributes['primary'] ?? false;
    }

    public function isEnum(): bool
    {
        return $this->type === 'enum';
    }

    public function isSet(): bool
    {
        return $this->type === 'set';
    }

    /**
     * @return array<string>
     */
    public function getEnumValues(): array
    {
        return $this->attributes['enum_values'] ?? [];
    }

    public function isTimestamp(): bool
    {
        return in_array($this->name, ['created_at', 'updated_at'], true);
    }

    public function isSoftDelete(): bool
    {
        return $this->name === 'deleted_at';
    }

    public function isBoolean(): bool
    {
        return in_array($this->type, ['boolean', 'tinyint'], true)
            && ($this->length === 1 || $this->length === null);
    }

    public function isJson(): bool
    {
        return in_array($this->type, ['json', 'jsonb'], true);
    }

    public function isUuid(): bool
    {
        return $this->type === 'uuid'
            || ($this->type === 'char' && $this->length === 36);
    }

    public function isUlid(): bool
    {
        return $this->type === 'ulid'
            || ($this->type === 'char' && $this->length === 26);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'native_type' => $this->nativeType,
            'nullable' => $this->nullable,
            'default' => $this->default,
            'auto_increment' => $this->autoIncrement,
            'unsigned' => $this->unsigned,
            'length' => $this->length,
            'precision' => $this->precision,
            'scale' => $this->scale,
            'charset' => $this->charset,
            'collation' => $this->collation,
            'comment' => $this->comment,
            'attributes' => $this->attributes,
        ];
    }
}
