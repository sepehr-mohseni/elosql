<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Analyzers;

use Sepehr_Mohseni\Elosql\Exceptions\GeneratorException;
use Sepehr_Mohseni\Elosql\Exceptions\SchemaParserException;
use Sepehr_Mohseni\Elosql\ValueObjects\TableSchema;

class DependencyResolver
{
    /**
     * Resolve table dependencies and return them in creation order.
     * Alias for sortByDependencies for more intuitive API.
     *
     * @param array<TableSchema> $tables
     *
     * @throws SchemaParserException
     *
     * @return array<TableSchema>
     */
    public function resolve(array $tables): array
    {
        // First check for circular dependencies
        $cycles = $this->detectCircularDependencies($tables);
        if (! empty($cycles)) {
            $cycleStr = implode(' -> ', $cycles[0]);

            throw SchemaParserException::circularDependency($cycleStr);
        }

        return $this->sortByDependencies($tables);
    }

    /**
     * Sort tables by their foreign key dependencies.
     * Tables with no dependencies come first, tables that depend on others come later.
     *
     * @param array<TableSchema> $tables
     *
     * @throws GeneratorException
     *
     * @return array<TableSchema>
     */
    public function sortByDependencies(array $tables): array
    {
        $tableMap = [];
        foreach ($tables as $table) {
            $tableMap[$table->name] = $table;
        }

        $sorted = [];
        $visited = [];
        $visiting = [];

        foreach ($tables as $table) {
            if (! isset($visited[$table->name])) {
                $this->topologicalSort($table->name, $tableMap, $sorted, $visited, $visiting);
            }
        }

        return $sorted;
    }

    /**
     * Perform topological sort using DFS.
     *
     * @param array<string, TableSchema> $tableMap
     * @param array<TableSchema> $sorted
     * @param array<string, bool> $visited
     * @param array<string, bool> $visiting
     *
     * @throws GeneratorException
     */
    protected function topologicalSort(
        string $tableName,
        array $tableMap,
        array &$sorted,
        array &$visited,
        array &$visiting
    ): void {
        if (isset($visited[$tableName])) {
            return;
        }

        if (isset($visiting[$tableName])) {
            // Circular dependency detected - we'll handle this by generating FK migrations separately
            return;
        }

        $visiting[$tableName] = true;

        if (isset($tableMap[$tableName])) {
            $table = $tableMap[$tableName];

            // Visit all tables that this table depends on (via foreign keys)
            foreach ($table->foreignKeys as $fk) {
                $referencedTable = $fk->referencedTable;

                // Skip self-references
                if ($referencedTable === $tableName) {
                    continue;
                }

                // Only process if the referenced table is in our set
                if (isset($tableMap[$referencedTable])) {
                    $this->topologicalSort($referencedTable, $tableMap, $sorted, $visited, $visiting);
                }
            }

            $sorted[] = $table;
        }

        unset($visiting[$tableName]);
        $visited[$tableName] = true;
    }

    /**
     * Detect circular dependencies between tables.
     *
     * @param array<TableSchema> $tables
     *
     * @return array<array<string>> Array of cycles, each cycle is an array of table names
     */
    public function detectCircularDependencies(array $tables): array
    {
        $tableMap = [];
        foreach ($tables as $table) {
            $tableMap[$table->name] = $table;
        }

        $cycles = [];
        $visited = [];
        $recursionStack = [];

        foreach ($tables as $table) {
            if (! isset($visited[$table->name])) {
                $path = [];
                $this->detectCycles($table->name, $tableMap, $visited, $recursionStack, $path, $cycles);
            }
        }

        return $cycles;
    }

    /**
     * Detect cycles using DFS.
     *
     * @param array<string, TableSchema> $tableMap
     * @param array<string, bool> $visited
     * @param array<string, bool> $recursionStack
     * @param array<string> $path
     * @param array<array<string>> $cycles
     */
    protected function detectCycles(
        string $tableName,
        array $tableMap,
        array &$visited,
        array &$recursionStack,
        array $path,
        array &$cycles
    ): void {
        $visited[$tableName] = true;
        $recursionStack[$tableName] = true;
        $path[] = $tableName;

        if (isset($tableMap[$tableName])) {
            foreach ($tableMap[$tableName]->foreignKeys as $fk) {
                $referencedTable = $fk->referencedTable;

                // Skip self-references
                if ($referencedTable === $tableName) {
                    continue;
                }

                if (! isset($tableMap[$referencedTable])) {
                    continue;
                }

                if (! isset($visited[$referencedTable])) {
                    $this->detectCycles($referencedTable, $tableMap, $visited, $recursionStack, $path, $cycles);
                } elseif (isset($recursionStack[$referencedTable])) {
                    // Found a cycle
                    $cycleStart = array_search($referencedTable, $path, true);
                    if ($cycleStart !== false) {
                        $cycle = array_slice($path, $cycleStart);
                        $cycle[] = $referencedTable; // Complete the cycle
                        $cycles[] = $cycle;
                    }
                }
            }
        }

        unset($recursionStack[$tableName]);
    }

    /**
     * Group tables into batches that can be created together.
     * Each batch contains tables that don't depend on tables in later batches.
     *
     * @param array<TableSchema> $tables
     *
     * @return array<array<TableSchema>>
     */
    public function groupIntoBatches(array $tables): array
    {
        $sorted = $this->sortByDependencies($tables);

        $batches = [];
        $currentBatch = [];
        $tablesInPreviousBatches = [];

        foreach ($sorted as $table) {
            $canAddToBatch = true;

            // Check if any foreign key references a table not yet processed
            foreach ($table->foreignKeys as $fk) {
                if ($fk->referencedTable === $table->name) {
                    continue; // Self-reference is OK
                }

                // If it references a table in the current batch, we need a new batch
                foreach ($currentBatch as $batchTable) {
                    if ($batchTable->name === $fk->referencedTable) {
                        $canAddToBatch = false;
                        break 2;
                    }
                }
            }

            if (! $canAddToBatch && ! empty($currentBatch)) {
                $batches[] = $currentBatch;
                $tablesInPreviousBatches = array_merge(
                    $tablesInPreviousBatches,
                    array_map(fn ($t) => $t->name, $currentBatch)
                );
                $currentBatch = [];
            }

            $currentBatch[] = $table;
        }

        if (! empty($currentBatch)) {
            $batches[] = $currentBatch;
        }

        return $batches;
    }

    /**
     * Get the dependency order for foreign key migrations.
     * Returns foreign keys in the order they should be added.
     *
     * @param array<TableSchema> $tables
     *
     * @return array<array{table: string, foreign_key: \Sepehr_Mohseni\Elosql\ValueObjects\ForeignKeySchema}>
     */
    public function getForeignKeyOrder(array $tables): array
    {
        $sorted = $this->sortByDependencies($tables);
        $result = [];

        foreach ($sorted as $table) {
            foreach ($table->foreignKeys as $fk) {
                $result[] = [
                    'table' => $table->name,
                    'foreign_key' => $fk,
                ];
            }
        }

        return $result;
    }

    /**
     * Check if adding a foreign key would create a circular dependency.
     *
     * @param array<TableSchema> $tables
     */
    public function wouldCreateCycle(string $fromTable, string $toTable, array $tables): bool
    {
        $tableMap = [];
        foreach ($tables as $table) {
            $tableMap[$table->name] = $table;
        }

        // Check if toTable already has a path back to fromTable
        return $this->hasPath($toTable, $fromTable, $tableMap, []);
    }

    /**
     * Check if there's a path from source to target through foreign keys.
     *
     * @param array<string, TableSchema> $tableMap
     * @param array<string, bool> $visited
     */
    protected function hasPath(string $source, string $target, array $tableMap, array $visited): bool
    {
        if ($source === $target) {
            return true;
        }

        if (isset($visited[$source]) || ! isset($tableMap[$source])) {
            return false;
        }

        $visited[$source] = true;

        foreach ($tableMap[$source]->foreignKeys as $fk) {
            if ($this->hasPath($fk->referencedTable, $target, $tableMap, $visited)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the dependency graph showing which tables each table depends on.
     *
     * @param array<TableSchema> $tables
     *
     * @return array<string, array<string>>
     */
    public function getDependencyGraph(array $tables): array
    {
        $graph = [];

        foreach ($tables as $table) {
            $dependencies = [];

            foreach ($table->foreignKeys as $fk) {
                if ($fk->referencedTable !== $table->name) {
                    $dependencies[] = $fk->referencedTable;
                }
            }

            $graph[$table->name] = array_unique($dependencies);
        }

        return $graph;
    }

    /**
     * Get the reverse dependency graph showing which tables depend on each table.
     *
     * @param array<TableSchema> $tables
     *
     * @return array<string, array<string>>
     */
    public function getReverseDependencyGraph(array $tables): array
    {
        $graph = [];

        // Initialize all tables
        foreach ($tables as $table) {
            $graph[$table->name] = [];
        }

        // Build reverse dependencies
        foreach ($tables as $table) {
            foreach ($table->foreignKeys as $fk) {
                if ($fk->referencedTable !== $table->name && isset($graph[$fk->referencedTable])) {
                    $graph[$fk->referencedTable][] = $table->name;
                }
            }
        }

        return $graph;
    }

    /**
     * Get tables that have no dependencies (root tables).
     *
     * @param array<TableSchema> $tables
     *
     * @return array<string>
     */
    public function getRootTables(array $tables): array
    {
        $tableNames = array_map(fn ($t) => $t->name, $tables);
        $roots = [];

        foreach ($tables as $table) {
            $hasDependency = false;

            foreach ($table->foreignKeys as $fk) {
                // Skip self-references
                if ($fk->referencedTable === $table->name) {
                    continue;
                }

                // Check if referenced table exists in our set
                if (in_array($fk->referencedTable, $tableNames, true)) {
                    $hasDependency = true;
                    break;
                }
            }

            if (! $hasDependency) {
                $roots[] = $table->name;
            }
        }

        return $roots;
    }

    /**
     * Get tables that no other tables depend on (leaf tables).
     *
     * @param array<TableSchema> $tables
     *
     * @return array<string>
     */
    public function getLeafTables(array $tables): array
    {
        $reverseGraph = $this->getReverseDependencyGraph($tables);
        $leaves = [];

        foreach ($reverseGraph as $tableName => $dependents) {
            if (empty($dependents)) {
                $leaves[] = $tableName;
            }
        }

        return $leaves;
    }

    /**
     * Identify pivot tables (many-to-many join tables).
     * A table is considered a pivot if it has exactly 2 foreign keys
     * and no non-FK columns except possibly timestamps.
     *
     * @param array<TableSchema> $tables
     *
     * @return array<string>
     */
    public function getPivotTables(array $tables): array
    {
        $tableNames = array_map(fn ($t) => $t->name, $tables);
        $pivotTables = [];

        foreach ($tables as $table) {
            if (count($table->foreignKeys) === 2) {
                // Get referenced tables
                $refs = array_map(fn ($fk) => $fk->referencedTable, $table->foreignKeys);

                // Check if both referenced tables exist
                if (
                    in_array($refs[0], $tableNames, true) &&
                    in_array($refs[1], $tableNames, true)
                ) {
                    $pivotTables[] = $table->name;
                }
            }
        }

        return $pivotTables;
    }

    /**
     * Group tables by their dependency level.
     *
     * @param array<TableSchema> $tables
     *
     * @return array<int, array<string>>
     */
    public function groupByLevel(array $tables): array
    {
        $levels = [];
        $assigned = [];
        $tableMap = [];

        foreach ($tables as $table) {
            $tableMap[$table->name] = $table;
        }

        // Find root level (tables with no dependencies)
        $roots = $this->getRootTables($tables);
        if (! empty($roots)) {
            $levels[0] = $roots;
            foreach ($roots as $root) {
                $assigned[$root] = 0;
            }
        }

        // Assign levels to remaining tables
        $changed = true;
        while ($changed) {
            $changed = false;

            foreach ($tables as $table) {
                if (isset($assigned[$table->name])) {
                    continue;
                }

                $maxLevel = -1;
                $canAssign = true;

                foreach ($table->foreignKeys as $fk) {
                    if ($fk->referencedTable === $table->name) {
                        continue; // Skip self-references
                    }

                    if (! isset($tableMap[$fk->referencedTable])) {
                        continue; // Skip external references
                    }

                    if (! isset($assigned[$fk->referencedTable])) {
                        $canAssign = false;
                        break;
                    }

                    $maxLevel = max($maxLevel, $assigned[$fk->referencedTable]);
                }

                if ($canAssign) {
                    $level = $maxLevel + 1;
                    $assigned[$table->name] = $level;

                    if (! isset($levels[$level])) {
                        $levels[$level] = [];
                    }
                    $levels[$level][] = $table->name;
                    $changed = true;
                }
            }
        }

        ksort($levels);

        return $levels;
    }
}
