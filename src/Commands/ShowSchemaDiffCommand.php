<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Sepehr_Mohseni\Elosql\Analyzers\SchemaComparator;
use Sepehr_Mohseni\Elosql\Parsers\SchemaParserFactory;
use Sepehr_Mohseni\Elosql\ValueObjects\TableSchema;

class ShowSchemaDiffCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'elosql:diff
        {--connection= : The database connection to use}
        {--json : Output as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show differences between database schema and existing migrations';

    public function __construct(
        protected SchemaParserFactory $parserFactory,
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
        $asJson = (bool) $this->option('json');

        if (! $asJson) {
            $this->info("Analyzing schema drift on connection: {$connection}");
        }

        try {
            $dbConnection = DB::connection($connection);
            $parser = $this->parserFactory->make($dbConnection);

            // Get and parse all tables
            $excludeTables = config('elosql.exclude_tables', []);
            $tableNames = $parser->getTables($excludeTables);

            $tables = [];
            foreach ($tableNames as $tableName) {
                $tables[] = $parser->parseTable($tableName);
            }

            // Generate report
            $report = $this->schemaComparator->generateReport($tables);

            if ($asJson) {
                $this->outputJson($report);
            } else {
                $this->outputPretty($report, $tables);
            }

            // Return appropriate exit code
            $hasChanges = $report['summary']['new_tables'] > 0
                || $report['summary']['modified_tables'] > 0
                || $report['summary']['removed_tables'] > 0;

            return $hasChanges ? self::FAILURE : self::SUCCESS;
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
     * Output as JSON.
     *
     * @param array<string, mixed> $report
     */
    protected function outputJson(array $report): void
    {
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Output in pretty format.
     *
     * @param array<string, mixed> $report
     * @param array<TableSchema> $tables
     */
    protected function outputPretty(array $report, array $tables): void
    {
        $this->newLine();

        // Summary
        $summary = $report['summary'];

        if ($this->schemaComparator->isInSync($tables)) {
            $this->info('✓ Schema is in sync with migrations!');
            $this->line("  Total tables: {$summary['total_tables']}");

            return;
        }

        $this->warn('Schema drift detected!');
        $this->newLine();

        // Summary table
        $this->table(
            ['Category', 'Count'],
            [
                ['Total tables in database', $summary['total_tables']],
                ['New tables (no migration)', $summary['new_tables']],
                ['Modified tables', $summary['modified_tables']],
                ['Removed tables (migration exists but table gone)', $summary['removed_tables']],
            ]
        );

        // New tables
        if (! empty($report['new_tables'])) {
            $this->newLine();
            $this->comment('New Tables (not in any migration):');
            foreach ($report['new_tables'] as $table) {
                $this->line("  + {$table}");
            }
        }

        // Modified tables
        if (! empty($report['modified_tables'])) {
            $this->newLine();
            $this->comment('Modified Tables (columns differ from migration):');
            foreach ($report['modified_tables'] as $tableName => $diff) {
                $this->line("  ~ {$tableName}");
                if (! empty($diff['columns']['new'])) {
                    $this->line('      New columns: ' . implode(', ', $diff['columns']['new']));
                }
                if (! empty($diff['columns']['removed'])) {
                    $this->line('      Removed columns: ' . implode(', ', $diff['columns']['removed']));
                }
            }
        }

        // Removed tables
        if (! empty($report['removed_tables'])) {
            $this->newLine();
            $this->comment('Removed Tables (migration exists but table not in database):');
            foreach ($report['removed_tables'] as $table) {
                $this->line("  - {$table}");
            }
        }

        // Recommended actions
        if (! empty($report['actions'])) {
            $this->newLine();
            $this->info('Recommended Actions:');
            foreach ($report['actions'] as $action) {
                $this->line("  • {$action['description']}");
                $this->line('    Tables: ' . implode(', ', $action['tables']));
            }
        }

        $this->newLine();
        $this->line('Run "php artisan schema:migrations --diff" to generate missing migrations.');
    }
}
