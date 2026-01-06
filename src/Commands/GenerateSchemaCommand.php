<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Sepehr_Mohseni\Elosql\Analyzers\SchemaComparator;
use Sepehr_Mohseni\Elosql\Generators\MigrationGenerator;
use Sepehr_Mohseni\Elosql\Generators\ModelGenerator;
use Sepehr_Mohseni\Elosql\Parsers\SchemaParserFactory;
use Sepehr_Mohseni\Elosql\ValueObjects\TableSchema;

class GenerateSchemaCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'elosql:schema
        {--connection= : The database connection to use}
        {--tables= : Specific tables to generate (comma-separated)}
        {--force : Overwrite existing files}
        {--no-migrations : Skip migration generation}
        {--no-models : Skip model generation}
        {--separate-fk : Generate foreign keys in separate migrations}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate migrations and models from existing database schema';

    public function __construct(
        protected SchemaParserFactory $parserFactory,
        protected MigrationGenerator $migrationGenerator,
        protected ModelGenerator $modelGenerator,
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
        $separateFk = $this->option('separate-fk') ?? config('elosql.features.separate_foreign_keys', true);

        $this->info("Analyzing database schema on connection: {$connection}");

        try {
            $dbConnection = DB::connection($connection);
            $parser = $this->parserFactory->make($dbConnection);
            $this->migrationGenerator->setDriver($parser->getDriver());
            $this->modelGenerator->setDriver($parser->getDriver());

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

            $this->info('Found ' . count($allTableNames) . ' tables to process.');

            // Parse tables with progress bar
            $tables = $this->parseTables($parser, $allTableNames);

            // Generate migrations
            if (! $this->option('no-migrations')) {
                $this->generateMigrations($tables, $force, $separateFk);
            }

            // Generate models
            if (! $this->option('no-models')) {
                $this->generateModels($tables, $force);
            }

            $this->newLine();
            $this->info('Schema generation completed successfully!');

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
     * Generate migrations.
     *
     * @param array<TableSchema> $tables
     */
    protected function generateMigrations(array $tables, bool $force, bool $separateFk): void
    {
        $this->info('Generating migrations...');

        $migrations = $this->migrationGenerator->generate($tables, $separateFk);

        if (! $force) {
            $existingFiles = [];
            $migrationsPath = config('elosql.migrations_path', database_path('migrations'));

            foreach (array_keys($migrations) as $filename) {
                $path = $migrationsPath . '/' . $filename;
                if (file_exists($path)) {
                    $existingFiles[] = $filename;
                }
            }

            if (! empty($existingFiles)) {
                $this->warn('The following migration files already exist:');
                foreach ($existingFiles as $file) {
                    $this->line("  - {$file}");
                }

                if (! $this->confirm('Do you want to overwrite them?')) {
                    $this->info('Skipping migration generation.');

                    return;
                }
            }
        }

        $writtenFiles = $this->migrationGenerator->writeMigrations($migrations, $force);

        $this->info('Generated ' . count($writtenFiles) . ' migration files:');
        $this->table(
            ['File'],
            array_map(fn ($f) => [basename($f)], $writtenFiles)
        );
    }

    /**
     * Generate models.
     *
     * @param array<TableSchema> $tables
     */
    protected function generateModels(array $tables, bool $force): void
    {
        $this->info('Generating models...');

        $models = $this->modelGenerator->generateAll($tables);

        if (! $force) {
            $existingFiles = [];
            $modelsPath = config('elosql.models.path', app_path('Models'));

            foreach (array_keys($models) as $filename) {
                $path = $modelsPath . '/' . $filename;
                if (file_exists($path)) {
                    $existingFiles[] = $filename;
                }
            }

            if (! empty($existingFiles)) {
                $this->warn('The following model files already exist:');
                foreach ($existingFiles as $file) {
                    $this->line("  - {$file}");
                }

                if (! $this->confirm('Do you want to overwrite them?')) {
                    $this->info('Skipping model generation.');

                    return;
                }
            }
        }

        $writtenFiles = $this->modelGenerator->writeModels($models, $force);

        $this->info('Generated ' . count($writtenFiles) . ' model files:');
        $this->table(
            ['File'],
            array_map(fn ($f) => [basename($f)], $writtenFiles)
        );
    }
}
