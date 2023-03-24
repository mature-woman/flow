<?php

declare(strict_types=1);

namespace Flow\ETL\Adapter\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Flow\ETL\DataFrame;
use Flow\ETL\DataFrameFactory;
use Flow\ETL\DSL\Dbal;
use Flow\ETL\DSL\Transform;
use Flow\ETL\Flow;
use Flow\ETL\Rows;

/**
 * @implements DataFrameFactory<array{
 *  connection_params: array<string, mixed>,
 *  query: string,
 *  parameters: array<Parameter>
 * }>
 */
final class DbalDataFrameFactory implements DataFrameFactory
{
    private ?Connection $connection = null;

    /**
     * @var array<Parameter>
     */
    private array $parameters;

    /**
     * @param array<string, mixed> $connectionParams
     * @param string $query
     * @param Parameter ...$parameters
     */
    public function __construct(
        private readonly array $connectionParams,
        private readonly string $query,
        Parameter ...$parameters
    ) {
        $this->parameters = $parameters;
    }

    public static function fromConnection(Connection $connection, string $query, Parameter ...$parameters) : self
    {
        /** @psalm-suppress InternalMethod */
        $factory = new self($connection->getParams(), $query, ...$parameters);
        $factory->connection = $connection;

        return $factory;
    }

    public function __serialize() : array
    {
        return [
            'connection_params' => $this->connectionParams,
            'query' => $this->query,
            'parameters' => $this->parameters,
        ];
    }

    public function __unserialize(array $data) : void
    {
        $this->connectionParams = $data['connection_params'];
        $this->query = $data['query'];
        $this->parameters = $data['parameters'];
    }

    public function from(Rows $rows) : DataFrame
    {
        $parameters = [];
        $types = [];

        foreach ($this->parameters as $parameter) {
            $parameters[$parameter->queryParamName] = $parameter->toQueryParam($rows);
            $types[$parameter->queryParamName] = $parameter->type;
        }

        return (new Flow())
            ->extract(
                Dbal::from_query(
                    $this->connection(),
                    $this->query,
                    $parameters,
                    $types,
                    $rowEntryName = 'row'
                )
            )
            ->transform(Transform::array_unpack($rowEntryName))
            ->drop($rowEntryName);
    }

    private function connection() : Connection
    {
        if ($this->connection === null) {
            /**
             * @psalm-suppress ArgumentTypeCoercion
             *
             * @phpstan-ignore-next-line
             */
            $this->connection = DriverManager::getConnection($this->connectionParams);
        }

        return $this->connection;
    }
}