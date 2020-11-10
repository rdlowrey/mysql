<?php

namespace Amp\Mysql;

use Amp\Sql\Common\StatementPool as SqlStatementPool;
use Amp\Sql\Result as SqlResult;
use Amp\Sql\Statement as SqlStatement;

final class StatementPool extends SqlStatementPool implements Statement
{
    private array $params = [];

    protected function prepare(SqlStatement $statement): Statement
    {
        \assert($statement instanceof Statement);

        $statement->reset();

        foreach ($this->params as $paramId => $data) {
            $statement->bind($paramId, $data);
        }

        return $statement;
    }

    protected function createResult(SqlResult $result, callable $release): Result
    {
        if (!$result instanceof Result) {
            throw new \TypeError('Result object must be an instance of ' . Result::class);
        }

        return new PooledResult($result, $release);
    }

    public function bind(int|string $paramId, mixed $data): void
    {
        if (!\is_int($paramId) && !\is_string($paramId)) {
            throw new \TypeError("Invalid parameter ID type");
        }

        $this->params[$paramId] = $data;
    }

    public function reset(): void
    {
        $this->params = [];
    }

    public function getFields(): ?array
    {
        $statement = $this->pop();
        $fields = $statement->getFields();
        $this->push($statement);
        return $fields;
    }
}
