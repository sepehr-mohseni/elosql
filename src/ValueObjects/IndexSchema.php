<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\ValueObjects;

use JsonSerializable;

final class IndexSchema implements JsonSerializable
{
    public const TYPE_PRIMARY = 'primary';
    public const TYPE_UNIQUE = 'unique';
    public const TYPE_INDEX = 'index';
    public const TYPE_FULLTEXT = 'fulltext';
    public const TYPE_SPATIAL = 'spatial';

    public const ALGORITHM_BTREE = 'btree';
    public const ALGORITHM_HASH = 'hash';

    /**
     * @param array<string> $columns
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly array $columns,
        public readonly ?string $algorithm = null,
        public readonly bool $isComposite = false,
    ) {}

    public function isPrimary(): bool
    {
        return $this->type === self::TYPE_PRIMARY;
    }

    public function isUnique(): bool
    {
        return $this->type === self::TYPE_UNIQUE;
    }

    public function isFulltext(): bool
    {
        return $this->type === self::TYPE_FULLTEXT;
    }

    public function isSpatial(): bool
    {
        return $this->type === self::TYPE_SPATIAL;
    }

    public function getColumnList(): string
    {
        return implode(', ', $this->columns);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'columns' => $this->columns,
            'algorithm' => $this->algorithm,
            'is_composite' => $this->isComposite,
        ];
    }
}
