<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql;

use Illuminate\Support\ServiceProvider;
use Sepehr_Mohseni\Elosql\Analyzers\DependencyResolver;
use Sepehr_Mohseni\Elosql\Analyzers\MigrationAnalyzer;
use Sepehr_Mohseni\Elosql\Analyzers\SchemaComparator;
use Sepehr_Mohseni\Elosql\Commands\GenerateMigrationsCommand;
use Sepehr_Mohseni\Elosql\Commands\GenerateModelsCommand;
use Sepehr_Mohseni\Elosql\Commands\GenerateSchemaCommand;
use Sepehr_Mohseni\Elosql\Commands\PreviewSchemaCommand;
use Sepehr_Mohseni\Elosql\Commands\ShowSchemaDiffCommand;
use Sepehr_Mohseni\Elosql\Generators\MigrationGenerator;
use Sepehr_Mohseni\Elosql\Generators\ModelGenerator;
use Sepehr_Mohseni\Elosql\Generators\RelationshipDetector;
use Sepehr_Mohseni\Elosql\Parsers\MySQLSchemaParser;
use Sepehr_Mohseni\Elosql\Parsers\PostgreSQLSchemaParser;
use Sepehr_Mohseni\Elosql\Parsers\SchemaParserFactory;
use Sepehr_Mohseni\Elosql\Parsers\SQLiteSchemaParser;
use Sepehr_Mohseni\Elosql\Parsers\SqlServerSchemaParser;
use Sepehr_Mohseni\Elosql\Support\FileWriter;
use Sepehr_Mohseni\Elosql\Support\NameConverter;
use Sepehr_Mohseni\Elosql\Support\TypeMapper;

class ElosqlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/elosql.php', 'elosql');

        $this->registerParsers();
        $this->registerSupport();
        $this->registerAnalyzers();
        $this->registerGenerators();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/elosql.php' => config_path('elosql.php'),
            ], 'elosql-config');

            $this->commands([
                GenerateSchemaCommand::class,
                GenerateMigrationsCommand::class,
                GenerateModelsCommand::class,
                PreviewSchemaCommand::class,
                ShowSchemaDiffCommand::class,
            ]);
        }
    }

    protected function registerParsers(): void
    {
        $this->app->singleton(SchemaParserFactory::class, function ($app) {
            return new SchemaParserFactory($app);
        });

        $this->app->bind(MySQLSchemaParser::class, function ($app) {
            return new MySQLSchemaParser(
                $app->make(TypeMapper::class)
            );
        });

        $this->app->bind(PostgreSQLSchemaParser::class, function ($app) {
            return new PostgreSQLSchemaParser(
                $app->make(TypeMapper::class)
            );
        });

        $this->app->bind(SQLiteSchemaParser::class, function ($app) {
            return new SQLiteSchemaParser(
                $app->make(TypeMapper::class)
            );
        });

        $this->app->bind(SqlServerSchemaParser::class, function ($app) {
            return new SqlServerSchemaParser(
                $app->make(TypeMapper::class)
            );
        });
    }

    protected function registerSupport(): void
    {
        $this->app->singleton(TypeMapper::class, function ($app) {
            return new TypeMapper(
                config('elosql.type_mappings', [])
            );
        });

        $this->app->singleton(NameConverter::class, function () {
            return new NameConverter();
        });

        $this->app->singleton(FileWriter::class, function ($app) {
            return new FileWriter(
                $app['files'],
                config('elosql.formatting', [])
            );
        });
    }

    protected function registerAnalyzers(): void
    {
        $this->app->singleton(MigrationAnalyzer::class, function ($app) {
            return new MigrationAnalyzer(
                $app['files'],
                config('elosql.migrations_path', database_path('migrations'))
            );
        });

        $this->app->singleton(SchemaComparator::class, function ($app) {
            return new SchemaComparator(
                $app->make(MigrationAnalyzer::class)
            );
        });

        $this->app->singleton(DependencyResolver::class, function () {
            return new DependencyResolver();
        });
    }

    protected function registerGenerators(): void
    {
        $this->app->singleton(RelationshipDetector::class, function ($app) {
            return new RelationshipDetector(
                $app->make(NameConverter::class)
            );
        });

        $this->app->singleton(MigrationGenerator::class, function ($app) {
            return new MigrationGenerator(
                $app->make(TypeMapper::class),
                $app->make(DependencyResolver::class),
                $app->make(FileWriter::class),
                config('elosql.migrations_path', database_path('migrations'))
            );
        });

        $this->app->singleton(ModelGenerator::class, function ($app) {
            return new ModelGenerator(
                $app->make(RelationshipDetector::class),
                $app->make(NameConverter::class),
                $app->make(FileWriter::class),
                config('elosql.models', [])
            );
        });
    }
}
