<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Generators;

use Sepehr_Mohseni\Elosql\Support\FileWriter;
use Sepehr_Mohseni\Elosql\Support\NameConverter;
use Sepehr_Mohseni\Elosql\Support\TypeMapper;
use Sepehr_Mohseni\Elosql\ValueObjects\ColumnSchema;
use Sepehr_Mohseni\Elosql\ValueObjects\TableSchema;

class ModelGenerator
{
    protected string $driver = 'mysql';

    protected TypeMapper $typeMapper;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected RelationshipDetector $relationshipDetector,
        protected NameConverter $nameConverter,
        protected FileWriter $fileWriter,
        protected array $config = [],
    ) {
        $this->typeMapper = new TypeMapper();
    }

    /**
     * Set the database driver.
     */
    public function setDriver(string $driver): self
    {
        $this->driver = $driver;

        return $this;
    }

    /**
     * Generate a model for a table.
     *
     * @param array<TableSchema> $allTables
     */
    public function generate(TableSchema $table, array $allTables = []): string
    {
        $modelName = $this->nameConverter->tableToModelName($table->name);
        $namespace = $this->config['namespace'] ?? 'App\\Models';
        $baseClass = $this->config['base_class'] ?? 'Illuminate\\Database\\Eloquent\\Model';

        // Build model components
        $imports = $this->buildImports($table, $baseClass);
        $traits = $this->buildTraits($table);
        $properties = $this->buildProperties($table);
        $relationships = $this->buildRelationships($table, $allTables);
        $scopes = $this->buildScopes($table);

        return $this->buildModelClass(
            $modelName,
            $namespace,
            $baseClass,
            $imports,
            $traits,
            $properties,
            $relationships,
            $scopes,
            $table
        );
    }

    /**
     * Generate models for multiple tables.
     *
     * @param array<TableSchema> $tables
     * @return array<string, string> Map of filename to content
     */
    public function generateAll(array $tables): array
    {
        $models = [];

        foreach ($tables as $table) {
            $filename = $this->nameConverter->tableToModelFileName($table->name);
            $content = $this->generate($table, $tables);
            $models[$filename] = $this->fileWriter->formatCode($content);
        }

        return $models;
    }

    /**
     * Build import statements.
     *
     * @return array<string>
     */
    protected function buildImports(TableSchema $table, string $baseClass): array
    {
        $imports = [];

        // Base class
        if ($baseClass !== 'Illuminate\\Database\\Eloquent\\Model') {
            $imports[] = $baseClass;
        }
        $imports[] = 'Illuminate\\Database\\Eloquent\\Model';

        // Soft deletes
        if ($table->hasSoftDeletes()) {
            $imports[] = 'Illuminate\\Database\\Eloquent\\SoftDeletes';
        }

        // Factories
        $imports[] = 'Illuminate\\Database\\Eloquent\\Factories\\HasFactory';

        // Relationship types
        $imports[] = 'Illuminate\\Database\\Eloquent\\Relations\\BelongsTo';
        $imports[] = 'Illuminate\\Database\\Eloquent\\Relations\\HasMany';
        $imports[] = 'Illuminate\\Database\\Eloquent\\Relations\\HasOne';
        $imports[] = 'Illuminate\\Database\\Eloquent\\Relations\\BelongsToMany';

        // Builder for scopes
        $imports[] = 'Illuminate\\Database\\Eloquent\\Builder';

        sort($imports);

        return array_unique($imports);
    }

    /**
     * Build trait usage.
     *
     * @return array<string>
     */
    protected function buildTraits(TableSchema $table): array
    {
        $traits = ['HasFactory'];

        if ($table->hasSoftDeletes()) {
            $traits[] = 'SoftDeletes';
        }

        return $traits;
    }

    /**
     * Build model properties.
     */
    protected function buildProperties(TableSchema $table): string
    {
        $properties = [];

        // Table name (if not following convention)
        $expectedTable = $this->nameConverter->modelToTableName(
            $this->nameConverter->tableToModelName($table->name)
        );
        if ($table->name !== $expectedTable) {
            $properties[] = "protected \$table = '{$table->name}';";
        }

        // Primary key
        $pk = $table->getPrimaryKey();
        if ($pk !== null) {
            $pkColumns = $pk->columns;
            if (count($pkColumns) === 1 && $pkColumns[0] !== 'id') {
                $properties[] = "protected \$primaryKey = '{$pkColumns[0]}';";
            }

            // Check if primary key is not auto-incrementing
            $pkColumn = $table->getColumn($pkColumns[0] ?? 'id');
            if ($pkColumn !== null && !$pkColumn->autoIncrement) {
                $properties[] = 'public $incrementing = false;';
            }

            // Check if primary key is not integer
            if ($pkColumn !== null && ($pkColumn->isUuid() || $pkColumn->type === 'string')) {
                $properties[] = "protected \$keyType = 'string';";
            }
        }

        // Timestamps
        if (!$table->hasTimestamps()) {
            $properties[] = 'public $timestamps = false;';
        }

        // Fillable/Guarded
        $fillable = $this->getFillableColumns($table);
        if ($this->config['use_fillable'] ?? true) {
            $properties[] = $this->formatArrayProperty('fillable', $fillable);
        } else {
            $guarded = $this->config['guarded_columns'] ?? ['id'];
            $properties[] = $this->formatArrayProperty('guarded', $guarded);
        }

        // Casts
        $casts = $this->getCasts($table);
        if (!empty($casts)) {
            $properties[] = $this->formatCastsProperty($casts);
        }

        return implode("\n\n    ", $properties);
    }

    /**
     * Build relationship methods.
     *
     * @param array<TableSchema> $allTables
     */
    protected function buildRelationships(TableSchema $table, array $allTables): string
    {
        if (!($this->config['generate_relationships'] ?? true)) {
            return '';
        }

        $relationships = $this->relationshipDetector->detectRelationships($table, $allTables);
        $methods = [];

        foreach ($relationships as $rel) {
            // Skip polymorphic relationships here - they're handled separately below
            if (($rel['type'] ?? '') === 'morphTo') {
                continue;
            }
            $methods[] = $this->buildRelationshipMethod($rel);
        }

        // Add polymorphic relationships (morphTo)
        foreach ($relationships as $rel) {
            if (($rel['type'] ?? '') === 'morphTo') {
                $methods[] = $this->buildPolymorphicMethod($rel);
            }
        }

        return implode("\n\n", $methods);
    }

    /**
     * Build a relationship method.
     *
     * @param array<string, mixed> $relationship
     */
    protected function buildRelationshipMethod(array $relationship): string
    {
        $method = $relationship['method'];
        $type = $relationship['type'];
        $relatedModel = $relationship['related_model'];

        $returnType = match ($type) {
            'belongsTo' => 'BelongsTo',
            'hasMany' => 'HasMany',
            'hasOne' => 'HasOne',
            'belongsToMany' => 'BelongsToMany',
            default => 'mixed',
        };

        $body = match ($type) {
            'belongsTo' => $this->buildBelongsToBody($relationship),
            'hasMany', 'hasOne' => $this->buildHasBody($relationship),
            'belongsToMany' => $this->buildBelongsToManyBody($relationship),
            default => "return \$this->{$type}({$relatedModel}::class);",
        };

        $docblock = $this->config['add_docblocks'] ?? true
            ? "    /**\n     * Get the {$method} relationship.\n     */\n"
            : '';

        return <<<PHP
{$docblock}    public function {$method}(): {$returnType}
    {
        {$body}
    }
PHP;
    }

    /**
     * Build belongsTo method body.
     *
     * @param array<string, mixed> $relationship
     */
    protected function buildBelongsToBody(array $relationship): string
    {
        $relatedModel = $relationship['related_model'];
        $foreignKey = $relationship['foreign_key'];
        $ownerKey = $relationship['owner_key'];

        // Use defaults if following convention
        $expectedFk = $this->nameConverter->snake($relationship['method']) . '_id';
        if ($foreignKey === $expectedFk && $ownerKey === 'id') {
            return "return \$this->belongsTo({$relatedModel}::class);";
        }

        return "return \$this->belongsTo({$relatedModel}::class, '{$foreignKey}', '{$ownerKey}');";
    }

    /**
     * Build hasMany/hasOne method body.
     *
     * @param array<string, mixed> $relationship
     */
    protected function buildHasBody(array $relationship): string
    {
        $type = $relationship['type'];
        $relatedModel = $relationship['related_model'];
        $foreignKey = $relationship['foreign_key'];
        $localKey = $relationship['local_key'];

        // Use defaults if following convention
        $tableName = $this->nameConverter->modelToTableName($relatedModel);
        $expectedFk = $this->nameConverter->singular($tableName) . '_id';
        // This is approximate - actual parent table name would be better

        if ($localKey === 'id') {
            return "return \$this->{$type}({$relatedModel}::class, '{$foreignKey}');";
        }

        return "return \$this->{$type}({$relatedModel}::class, '{$foreignKey}', '{$localKey}');";
    }

    /**
     * Build belongsToMany method body.
     *
     * @param array<string, mixed> $relationship
     */
    protected function buildBelongsToManyBody(array $relationship): string
    {
        $relatedModel = $relationship['related_model'];
        $pivotTable = $relationship['pivot_table'];
        $foreignPivotKey = $relationship['foreign_pivot_key'];
        $relatedPivotKey = $relationship['related_pivot_key'];
        $pivotColumns = $relationship['pivot_columns'] ?? [];

        $body = "return \$this->belongsToMany({$relatedModel}::class, '{$pivotTable}', '{$foreignPivotKey}', '{$relatedPivotKey}')";

        if (!empty($pivotColumns)) {
            $columns = "['" . implode("', '", $pivotColumns) . "']";
            $body .= "\n            ->withPivot({$columns})";
        }

        // Check if pivot has timestamps
        $body .= ';';

        return $body;
    }

    /**
     * Build polymorphic method.
     *
     * @param array<string, mixed> $relationship
     */
    protected function buildPolymorphicMethod(array $relationship): string
    {
        $method = $relationship['method'];
        $morphName = $relationship['morph_name'];

        return <<<PHP
    /**
     * Get the {$method} polymorphic relationship.
     */
    public function {$method}(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return \$this->morphTo('{$morphName}');
    }
PHP;
    }

    /**
     * Build query scopes.
     */
    protected function buildScopes(TableSchema $table): string
    {
        if (!($this->config['generate_scopes'] ?? true)) {
            return '';
        }

        $scopes = [];

        foreach ($table->columns as $column) {
            $scope = $this->buildScopeForColumn($column);
            if ($scope !== null) {
                $scopes[] = $scope;
            }
        }

        return implode("\n\n", $scopes);
    }

    /**
     * Build a scope for a specific column.
     */
    protected function buildScopeForColumn(ColumnSchema $column): ?string
    {
        // Active/Status scope for boolean columns
        if ($column->isBoolean() && in_array($column->name, ['active', 'is_active', 'enabled', 'status'], true)) {
            return <<<PHP
    /**
     * Scope a query to only include active records.
     */
    public function scopeActive(Builder \$query): Builder
    {
        return \$query->where('{$column->name}', true);
    }
PHP;
        }

        // Status scope for enum columns
        if ($column->isEnum() && in_array($column->name, ['status', 'state', 'type'], true)) {
            $scopeName = 'scopeBy' . $this->nameConverter->studly($column->name);
            return <<<PHP
    /**
     * Scope a query to filter by {$column->name}.
     */
    public function {$scopeName}(Builder \$query, string \$value): Builder
    {
        return \$query->where('{$column->name}', \$value);
    }
PHP;
        }

        return null;
    }

    /**
     * Get fillable columns.
     *
     * @return array<string>
     */
    protected function getFillableColumns(TableSchema $table): array
    {
        $guarded = $this->config['guarded_columns'] ?? ['id', 'created_at', 'updated_at', 'deleted_at'];

        $fillable = [];
        foreach ($table->columns as $column) {
            if (!in_array($column->name, $guarded, true) && !$column->autoIncrement) {
                $fillable[] = $column->name;
            }
        }

        return $fillable;
    }

    /**
     * Get casts for columns.
     *
     * @return array<string, string>
     */
    protected function getCasts(TableSchema $table): array
    {
        $casts = [];

        foreach ($table->columns as $column) {
            $cast = $this->getCastForColumn($column);
            if ($cast !== null) {
                $casts[$column->name] = $cast;
            }
        }

        return $casts;
    }

    /**
     * Get cast type for a column.
     */
    protected function getCastForColumn(ColumnSchema $column): ?string
    {
        // Skip timestamp columns - Laravel handles these automatically
        if (in_array($column->name, ['created_at', 'updated_at', 'deleted_at'], true)) {
            return null;
        }

        // Check type mapper first
        $cast = $this->typeMapper->getCastType($column, $this->driver);
        if ($cast !== null) {
            return $cast;
        }

        // Boolean
        if ($column->isBoolean()) {
            return 'boolean';
        }

        // JSON
        if ($column->isJson()) {
            return 'array';
        }

        // Date/DateTime columns
        if (str_ends_with($column->name, '_at') && in_array($column->type, ['timestamp', 'datetime'], true)) {
            return 'datetime';
        }

        if (str_ends_with($column->name, '_date') && $column->type === 'date') {
            return 'date';
        }

        // Decimal for money-like columns
        if (in_array($column->type, ['decimal', 'numeric'], true)) {
            return 'decimal:' . ($column->scale ?? 2);
        }

        return null;
    }

    /**
     * Format an array property.
     *
     * @param array<string> $values
     */
    protected function formatArrayProperty(string $name, array $values): string
    {
        if (empty($values)) {
            return "protected \${$name} = [];";
        }

        $formatted = array_map(fn ($v) => "        '{$v}',", $values);

        return "protected \${$name} = [\n" . implode("\n", $formatted) . "\n    ];";
    }

    /**
     * Format the casts property.
     *
     * @param array<string, string> $casts
     */
    protected function formatCastsProperty(array $casts): string
    {
        $lines = [];
        foreach ($casts as $column => $cast) {
            $lines[] = "        '{$column}' => '{$cast}',";
        }

        return "protected \$casts = [\n" . implode("\n", $lines) . "\n    ];";
    }

    /**
     * Build the complete model class.
     *
     * @param array<string> $imports
     * @param array<string> $traits
     */
    protected function buildModelClass(
        string $modelName,
        string $namespace,
        string $baseClass,
        array $imports,
        array $traits,
        string $properties,
        string $relationships,
        string $scopes,
        TableSchema $table
    ): string {
        $useStatements = implode("\n", array_map(fn ($i) => "use {$i};", $imports));
        $traitUse = !empty($traits) ? "\n    use " . implode(', ', $traits) . ";\n" : '';

        $baseClassName = class_basename($baseClass);

        // Build docblock
        $docblock = '';
        if ($this->config['add_docblocks'] ?? true) {
            $docblock = $this->buildClassDocblock($table);
        }

        // Combine body sections
        $bodyParts = array_filter([$properties, $relationships, $scopes]);
        $body = implode("\n\n", $bodyParts);

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

{$useStatements}

{$docblock}class {$modelName} extends {$baseClassName}
{{$traitUse}
    {$body}
}
PHP;
    }

    /**
     * Build class-level docblock.
     */
    protected function buildClassDocblock(TableSchema $table): string
    {
        $lines = ["/**"];

        // Add property annotations for columns
        foreach ($table->columns as $column) {
            $phpType = $this->getPhpTypeForColumn($column);
            $lines[] = " * @property {$phpType} \${$column->name}";
        }

        $lines[] = " */\n";

        return implode("\n", $lines);
    }

    /**
     * Get PHP type for a column.
     */
    protected function getPhpTypeForColumn(ColumnSchema $column): string
    {
        $nullPrefix = $column->nullable ? '?' : '';

        if ($column->isBoolean()) {
            return $nullPrefix . 'bool';
        }

        if ($column->isJson()) {
            return $nullPrefix . 'array';
        }

        if (in_array($column->type, ['integer', 'bigint', 'smallint', 'tinyint', 'mediumint'], true)) {
            return $nullPrefix . 'int';
        }

        if (in_array($column->type, ['decimal', 'numeric', 'float', 'double', 'real'], true)) {
            return $nullPrefix . 'float';
        }

        if (in_array($column->type, ['timestamp', 'datetime', 'date'], true)) {
            return $nullPrefix . '\\Carbon\\Carbon';
        }

        return $nullPrefix . 'string';
    }

    /**
     * Write models to disk.
     *
     * @param array<string, string> $models
     * @return array<string> List of written files
     */
    public function writeModels(array $models, bool $force = false): array
    {
        $path = $this->config['path'] ?? app_path('Models');
        $written = [];

        foreach ($models as $filename => $content) {
            $filePath = $path . '/' . $filename;
            $this->fileWriter->write($filePath, $content, $force);
            $written[] = $filePath;
        }

        return $written;
    }
}
