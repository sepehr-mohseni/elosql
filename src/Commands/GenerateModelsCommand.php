<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Commands;

use Sepehr_Mohseni\Elosql\Generators\ModelGenerator;
use Sepehr_Mohseni\Elosql\Parsers\SchemaParserFactory;
use Sepehr_Mohseni\Elosql\ValueObjects\TableSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateModelsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'elosql:models
        {--connection= : The database connection to use}
        {--table=* : Specific tables to generate models for}
        {--overwrite : Overwrite existing model files}
        {--preview : Preview models without writing files}
        {--no-relationships : Skip relationship generation}
        {--no-scopes : Skip scope generation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate Eloquent models from existing database schema';

    public function __construct(
        protected SchemaParserFactory $parserFactory,
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
        $overwrite = (bool) $this->option('overwrite');
        $preview = (bool) $this->option('preview');

        $this->info("Analyzing database schema on connection: {$connection}");

        try {
            $dbConnection = DB::connection($connection);
            $parser = $this->parserFactory->make($dbConnection);
            $this->modelGenerator->setDriver($parser->getDriver());

            // Get tables to process
            $excludeTables = config('elosql.exclude_tables', []);
            $specificTables = $this->option('table');

            $allTableNames = $parser->getTables($excludeTables);

            if (!empty($specificTables)) {
                $allTableNames = array_intersect($allTableNames, $specificTables);
            }

            if (empty($allTableNames)) {
                $this->warn('No tables found to process.');

                return self::SUCCESS;
            }

            // Parse tables
            $tables = $this->parseTables($parser, $allTableNames);

            $this->info('Generating models for ' . count($tables) . ' tables...');

            // Generate models
            $models = $this->modelGenerator->generateAll($tables);

            if ($preview) {
                $this->previewModels($models);

                return self::SUCCESS;
            }

            // Write models
            $writtenFiles = $this->writeModels($models, $overwrite);

            $this->newLine();
            $this->info('Generated ' . count($writtenFiles) . ' model files.');
            $this->displayGeneratedFiles($writtenFiles);

            return self::SUCCESS;
        } catch (\Exception $e) {
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
     * Preview models without writing.
     *
     * @param array<string, string> $models
     */
    protected function previewModels(array $models): void
    {
        $this->newLine();
        $this->info('Model Preview:');
        $this->line(str_repeat('=', 80));

        foreach ($models as $filename => $content) {
            $this->newLine();
            $this->comment("File: {$filename}");
            $this->line(str_repeat('-', 80));

            // Truncate content for preview if too long
            if (strlen($content) > 4000) {
                $this->line(substr($content, 0, 4000) . "\n... (truncated)");
            } else {
                $this->line($content);
            }

            $this->line(str_repeat('-', 80));
        }

        $this->newLine();
        $this->info("Preview complete. {" . count($models) . "} models would be generated.");
        $this->info('Run without --preview to write files.');
    }

    /**
     * Write models to disk.
     *
     * @param array<string, string> $models
     * @return array<string>
     */
    protected function writeModels(array $models, bool $overwrite): array
    {
        $modelsPath = config('elosql.models.path', app_path('Models'));

        if (!$overwrite) {
            $existingFiles = [];

            foreach (array_keys($models) as $filename) {
                $path = $modelsPath . '/' . $filename;
                if (file_exists($path)) {
                    $existingFiles[] = $filename;
                }
            }

            if (!empty($existingFiles)) {
                $this->warn('The following model files already exist:');
                foreach ($existingFiles as $file) {
                    $this->line("  - {$file}");
                }

                if (!$this->confirm('Overwrite existing files?', false)) {
                    // Remove existing files from models
                    foreach ($existingFiles as $file) {
                        unset($models[$file]);
                    }

                    if (empty($models)) {
                        $this->info('No new models to write.');

                        return [];
                    }
                }
            }
        }

        return $this->modelGenerator->writeModels($models, $overwrite);
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
