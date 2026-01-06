<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Sepehr_Mohseni\Elosql\Generators\MigrationGenerator;
use Sepehr_Mohseni\Elosql\Generators\ModelGenerator;
use Sepehr_Mohseni\Elosql\Parsers\SchemaParserFactory;
use Sepehr_Mohseni\Elosql\ValueObjects\TableSchema;

class PreviewSchemaCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'elosql:preview
        {--connection= : The database connection to use}
        {--tables= : Specific tables to preview (comma-separated)}
        {--json : Output as JSON}
        {--migrations : Show migration previews}
        {--models : Show model previews}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Preview what would be generated without writing files';

    public function __construct(
        protected SchemaParserFactory $parserFactory,
        protected MigrationGenerator $migrationGenerator,
        protected ModelGenerator $modelGenerator,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $connection = $this->option('connection') ?? config('elosql.connection') ?? config('database.default');
        $asJson = (bool) $this->option('json');
        $showMigrations = (bool) $this->option('migrations');
        $showModels = (bool) $this->option('models');

        // If neither specified, show both
        if (! $showMigrations && ! $showModels) {
            $showMigrations = true;
            $showModels = true;
        }

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
                if ($asJson) {
                    $this->line(json_encode(['error' => 'No tables found'], JSON_PRETTY_PRINT));
                } else {
                    $this->warn('No tables found to process.');
                }

                return self::SUCCESS;
            }

            // Parse tables
            $tables = [];
            foreach ($allTableNames as $tableName) {
                $tables[] = $parser->parseTable($tableName);
            }

            if ($asJson) {
                $this->outputJson($tables, $showMigrations, $showModels);
            } else {
                $this->outputPretty($tables, $showMigrations, $showModels);
            }

            return self::SUCCESS;
        } catch (Exception $e) {
            if ($asJson) {
                $this->line(json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT));
            } else {
                $this->error('Error: ' . $e->getMessage());
            }

            return self::FAILURE;
        }
    }

    /**
     * Output results as JSON.
     *
     * @param array<TableSchema> $tables
     */
    protected function outputJson(array $tables, bool $showMigrations, bool $showModels): void
    {
        $output = [
            'tables' => array_map(fn (TableSchema $t) => $t->jsonSerialize(), $tables),
        ];

        if ($showMigrations) {
            $migrations = $this->migrationGenerator->preview($tables);
            $output['migrations'] = [];
            foreach ($migrations as $filename => $content) {
                $output['migrations'][] = [
                    'filename' => $filename,
                    'content' => $content,
                ];
            }
        }

        if ($showModels) {
            $models = $this->modelGenerator->generateAll($tables);
            $output['models'] = [];
            foreach ($models as $filename => $content) {
                $output['models'][] = [
                    'filename' => $filename,
                    'content' => $content,
                ];
            }
        }

        $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Output results in pretty format.
     *
     * @param array<TableSchema> $tables
     */
    protected function outputPretty(array $tables, bool $showMigrations, bool $showModels): void
    {
        $this->info('Schema Preview');
        $this->line(str_repeat('=', 80));
        $this->newLine();

        // Table summary
        $this->comment('Tables found: ' . count($tables));
        $this->table(
            ['Table', 'Columns', 'Indexes', 'Foreign Keys'],
            array_map(fn (TableSchema $t) => [
                $t->name,
                count($t->columns),
                count($t->indexes),
                count($t->foreignKeys),
            ], $tables)
        );

        if ($showMigrations) {
            $this->newLine();
            $this->info('Migration Files to Generate:');
            $this->line(str_repeat('-', 40));

            $migrations = $this->migrationGenerator->preview($tables);
            foreach (array_keys($migrations) as $filename) {
                $this->line("  • {$filename}");
            }

            $this->newLine();
            if ($this->confirm('Show migration content?', false)) {
                foreach ($migrations as $filename => $content) {
                    $this->newLine();
                    $this->comment("=== {$filename} ===");
                    $this->line($content);
                }
            }
        }

        if ($showModels) {
            $this->newLine();
            $this->info('Model Files to Generate:');
            $this->line(str_repeat('-', 40));

            $models = $this->modelGenerator->generateAll($tables);
            foreach (array_keys($models) as $filename) {
                $this->line("  • {$filename}");
            }

            $this->newLine();
            if ($this->confirm('Show model content?', false)) {
                foreach ($models as $filename => $content) {
                    $this->newLine();
                    $this->comment("=== {$filename} ===");
                    $this->line($content);
                }
            }
        }

        $this->newLine();
        $this->info('Run "php artisan schema:generate" to create these files.');
    }
}
