<?php

declare(strict_types=1);

namespace Sepehr_Mohseni\Elosql\Parsers;

use Sepehr_Mohseni\Elosql\Exceptions\SchemaParserException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;

class SchemaParserFactory
{
    public function __construct(
        protected Container $container,
    ) {}

    /**
     * Create a schema parser for the given connection.
     *
     * @throws SchemaParserException
     */
    public function make(Connection $connection): SchemaParser
    {
        $driver = $connection->getDriverName();

        $parser = match ($driver) {
            'mysql', 'mariadb' => $this->container->make(MySQLSchemaParser::class),
            'pgsql' => $this->container->make(PostgreSQLSchemaParser::class),
            'sqlite' => $this->container->make(SQLiteSchemaParser::class),
            'sqlsrv' => $this->container->make(SqlServerSchemaParser::class),
            default => throw SchemaParserException::unsupportedDriver($driver),
        };

        $parser->setConnection($connection);

        return $parser;
    }

    /**
     * Create a schema parser for a named connection.
     *
     * @throws SchemaParserException
     */
    public function forConnection(string $connectionName): SchemaParser
    {
        $connection = $this->container->make('db')->connection($connectionName);

        return $this->make($connection);
    }
}
