<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Sepehr_Mohseni\Elosql\Analyzers\SchemaComparator;
use Sepehr_Mohseni\Elosql\Generators\MigrationGenerator;
use Sepehr_Mohseni\Elosql\Parsers\SchemaParserFactory;
use Sepehr_Mohseni\Elosql\ValueObjects\TableSchema;

class GenerateMigrationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'elosql:migrations
        {--connection= : The database connection to use}
        {--tables= : Specific tables to generate (comma-separated)}
        {--diff : Only generate for tables not in existing migrations}
        {--fresh : Generate fresh migrations ignoring existing state}
        {--force : Overwrite existing files}
        {--preview : Preview migrations without writing files}
        {--separate-fk : Generate foreign keys in separate migrations}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate Laravel migrations from existing database schema';

    public function __construct(
        protected SchemaParserFactory $parserFactory,
        protected MigrationGenerator $migrationGenerator,
        protected SchemaComparator $schemaComparator,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $connection = $this->option('connection') ?? config('elosql.connection') ?? config('database.default');
        $force = (bool) $this->option('force');
        $preview = (bool) $this->option('preview');
        $diff = (bool) $this->option('diff');
        $separateFk = $this->option('separate-fk') ?? config('elosql.features.separate_foreign_keys', true);

        $this->info("Analyzing database schema on connection: {$connection}");

        try {
            $dbConnection = DB::connection($connection);
            $parser = $this->parserFactory->make($dbConnection);
            $this->migrationGenerator->setDriver($parser->getDriver());

            // Get tables to process
            $excludeTables = config('elosql.exclude_tables', []);
            $specificTables = $this->option('tables')
                ? explode(',', $this->option('tables'))
                : null;

            $allTableNames = $parser->getTables($excludeTables);

            if ($specificTables !== null) {
                $allTableNames = array_intersect($allTableNames, $specificTables);
            }

            if (empty($allTableNames)) {
                $this->warn('No tables found to process.');

                return self::SUCCESS;
            }

            // Parse tables
            $tables = $this->parseTables($parser, $allTableNames);

            // Filter tables if diff mode
            if ($diff) {
                $tables = $this->filterForDiff($tables);
                if (empty($tables)) {
                    $this->info('All tables already have migrations. Nothing to generate.');

                    return self::SUCCESS;
                }
            }

            $this->info('Generating migrations for ' . count($tables) . ' tables...');

            // Generate migrations
            $migrations = $this->migrationGenerator->generate($tables, $separateFk);

            if ($preview) {
                $this->previewMigrations($migrations);

                return self::SUCCESS;
            }

            // Write migrations
            $writtenFiles = $this->writeMigrations($migrations, $force);

            $this->newLine();
            $this->info('Generated ' . count($writtenFiles) . ' migration files.');
            $this->displayGeneratedFiles($writtenFiles);

            return self::SUCCESS;
        } catch (Exception $e) {
            $this->error('Error: ' . $e->getMessage());

            if ($this->output->isVerbose()) {
                $this->error($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }

    /**
     * Parse all tables.
     *
     * @param array<string> $tableNames
     *
     * @return array<TableSchema>
     */
    protected function parseTables($parser, array $tableNames): array
    {
        $tables = [];
        $bar = $this->output->createProgressBar(count($tableNames));
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $bar->setMessage('Parsing tables...');
        $bar->start();

        foreach ($tableNames as $tableName) {
            $bar->setMessage("Parsing: {$tableName}");
            $tables[] = $parser->parseTable($tableName);
            $bar->advance();
        }

        $bar->setMessage('Done!');
        $bar->finish();
        $this->newLine(2);

        return $tables;
    }

    /**
     * Filter tables that need migrations (diff mode).
     *
     * @param array<TableSchema> $tables
     *
     * @return array<TableSchema>
     */
    protected function filterForDiff(array $tables): array
    {
        $this->info('Checking for schema drift...');

        $comparison = $this->schemaComparator->compareWithMigrations($tables);

        $newTables = $comparison['new'];
        $modifiedTables = $comparison['modified'];

        if (! empty($newTables)) {
            $this->info('New tables found: ' . implode(', ', $newTables));
        }

        if (! empty($modifiedTables)) {
            $this->warn('Modified tables found: ' . implode(', ', $modifiedTables));
            $this->line('Note: Modified tables will be regenerated. Review carefully before running migrations.');
        }

        $tablesToProcess = array_merge($newTables, $modifiedTables);

        return array_filter(
            $tables,
            fn (TableSchema $table) => in_array($table->name, $tablesToProcess, true)
        );
    }

    /**
     * Preview migrations without writing.
     *
     * @param array<string, string> $migrations
     */
    protected function previewMigrations(array $migrations): void
    {
        $this->newLine();
        $this->info('Migration Preview:');
        $this->line(str_repeat('=', 80));

        foreach ($migrations as $filename => $content) {
            $this->newLine();
            $this->comment("File: {$filename}");
            $this->line(str_repeat('-', 80));

            // Truncate content for preview if too long
            if (strlen($content) > 3000) {
                $this->line(substr($content, 0, 3000) . "\n... (truncated)");
            } else {
                $this->line($content);
            }

            $this->line(str_repeat('-', 80));
        }

        $this->newLine();
        $this->info('Preview complete. {' . count($migrations) . '} migrations would be generated.');
        $this->info('Run without --preview to write files.');
    }

    /**
     * Write migrations to disk.
     *
     * @param array<string, string> $migrations
     *
     * @return array<string>
     */
    protected function writeMigrations(array $migrations, bool $force): array
    {
        $migrationsPath = config('elosql.migrations_path', database_path('migrations'));

        if (! $force) {
            $existingFiles = [];

            foreach (array_keys($migrations) as $filename) {
                $path = $migrationsPath . '/' . $filename;
                if (file_exists($path)) {
                    $existingFiles[] = $filename;
                }
            }

            if (! empty($existingFiles)) {
                $this->warn('The following files already exist:');
                foreach ($existingFiles as $file) {
                    $this->line("  - {$file}");
                }

                if (! $this->confirm('Overwrite existing files?', false)) {
                    // Remove existing files from migrations
                    foreach ($existingFiles as $file) {
                        unset($migrations[$file]);
                    }

                    if (empty($migrations)) {
                        $this->info('No new migrations to write.');

                        return [];
                    }
                }
            }
        }

        return $this->migrationGenerator->writeMigrations($migrations, $force);
    }

    /**
     * Display generated files in a table.
     *
     * @param array<string> $files
     */
    protected function displayGeneratedFiles(array $files): void
    {
        $rows = array_map(fn ($f) => [basename($f)], $files);
        $this->table(['Generated Files'], $rows);
    }
}
