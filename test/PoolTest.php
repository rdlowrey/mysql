<?php

namespace Amp\Mysql\Test;

use Amp\Future;
use Amp\Mysql\Connection;
use Amp\Mysql\ConnectionConfig;
use Amp\Mysql\Internal\CommandResult;
use Amp\Mysql\Internal\Processor;
use Amp\Mysql\Link;
use Amp\Mysql\Pool;
use Amp\Mysql\Result;
use Amp\Mysql\Statement;
use Amp\Sql\Connector;
use Amp\Sql\Transaction as SqlTransaction;
use PHPUnit\Framework\MockObject\MockObject;
use function Amp\async;
use function Amp\delay;

interface StatementOperation extends Statement
{
}

class PoolTest extends LinkTest
{
    protected function getLink(string $connectionString): Link
    {
        return new Pool(ConnectionConfig::fromString($connectionString));
    }

    protected function createPool(array $connections): Pool
    {
        $connector = $this->createMock(Connector::class);
        $connector->method('connect')
            ->will($this->returnCallback(function () use ($connections): Connection {
                static $count = 0;
                return $connections[$count++ % \count($connections)];
            }));

        $config = ConnectionConfig::fromString('host=host;user=user;password=password');

        return new Pool($config, \count($connections), Pool::DEFAULT_IDLE_TIMEOUT, $connector);
    }

    /**
     * @param int $count
     *
     * @return array<int, Processor&MockObject>
     */
    private function makeProcessorSet(int $count): array
    {
        $processors = [];

        for ($i = 0; $i < $count; ++$i) {
            $processor = $this->createMock(Processor::class);
            $processor->method('isAlive')->willReturn(true);
            $processor->method('sendClose')->willReturn(Future::complete(null));
            $processors[] = $processor;
        }

        return $processors;
    }

    private function makeConnectionSet(array $processors): array
    {
        return \array_map((function (Processor $processor): Connection {
            return new self($processor);
        })->bindTo(null, Connection::class), $processors);
    }

    /**
     * @return array
     */
    public function getConnectionCounts(): array
    {
        return \array_map(function (int $count): array { return [$count]; }, \range(2, 10, 2));
    }

    /**
     * @dataProvider getConnectionCounts
     *
     * @param int $count
     */
    public function testSingleQuery(int $count)
    {
        $result = $this->createMock(Result::class);

        $processors = $this->makeProcessorSet($count);

        $connection = $processors[0];
        $connection->expects($this->once())
            ->method('query')
            ->with('SQL Query')
            ->willReturn(async(function () use ($result): Result {
                delay(0.01);
                return $result;
            }));

        $pool = $this->createPool($this->makeConnectionSet($processors));

        $return = $pool->query('SQL Query');
        $this->assertInstanceOf(Result::class, $return);

        $pool->close();
    }

    /**
     * @dataProvider getConnectionCounts
     *
     * @param int $count
     */
    public function testConsecutiveQueries(int $count)
    {
        $rounds = 3;
        $result = $this->createMock(Result::class);

        $processors = $this->makeProcessorSet($count);

        foreach ($processors as $connection) {
            $connection->method('query')
                ->with('SQL Query')
                ->willReturn(async(function () use ($result): Result {
                    delay(0.01);
                    return $result;
                }));
        }

        $pool = $this->createPool($this->makeConnectionSet($processors));

        try {
            $futures = [];

            for ($i = 0; $i < $count; ++$i) {
                $futures[] = async(fn() => $pool->query('SQL Query'));
            }

            $results = Future\all($futures);

            foreach ($results as $result) {
                $this->assertInstanceOf(Result::class, $result);
            }
        } finally {
            $pool->close();
        }
    }

    /**
     * @dataProvider getConnectionCounts
     *
     * @param int $count
     */
    public function testMultipleTransactions(int $count)
    {
        $processors = $this->makeProcessorSet($count);

        $connection = $processors[0];
        $result = new CommandResult(0, 0);

        $connection->expects($this->exactly(3))
            ->method('query')
            ->willReturn(async(function () use ($result): Result {
                delay(0.01);
                return $result;
            }));

        $pool = $this->createPool($this->makeConnectionSet($processors));

        try {
            $return = $pool->beginTransaction();
            $this->assertInstanceOf(SqlTransaction::class, $return);
            $return->rollback();
        } finally {
            $pool->close();
        }
    }

    /**
     * @dataProvider getConnectionCounts
     *
     * @param int $count
     */
    public function testConsecutiveTransactions(int $count)
    {
        $rounds = 3;
        $result = new CommandResult(0, 0);

        $processors = $this->makeProcessorSet($count);

        foreach ($processors as $connection) {
            $connection->method('query')
                ->willReturnCallback(fn () => async(function () use ($result): Result {
                    delay(0.01);
                    return $result;
                }));
        }

        $pool = $this->createPool($this->makeConnectionSet($processors));

        $futures = [];
        for ($i = 0; $i < $count; ++$i) {
            $futures[] = async(fn() => $pool->beginTransaction());
        }

        try {
            \array_map(function (Future $future) {
                $transaction = $future->await();
                $this->assertInstanceOf(SqlTransaction::class, $transaction);
                $transaction->rollback();
            }, $futures);
        } finally {
            $pool->close();
        }
    }

    /**
     * @dataProvider getConnectionCounts
     *
     * @param int $count
     */
    public function testExtractConnection(int $count)
    {
        $processors = $this->makeProcessorSet($count);
        $query = "SELECT * FROM test";

        foreach ($processors as $connection) {
            $connection->expects($this->once())
                ->method('query')
                ->with($query)
                ->willReturn(Future::complete($this->createMock(Result::class)));
        }

        $pool = $this->createPool($this->makeConnectionSet($processors));

        try {
            $futures = [];
            for ($i = 0; $i < $count; ++$i) {
                $futures[] = async(fn() => $pool->extractConnection());
            }
            $results = Future\all($futures);
            foreach ($results as $result) {
                $this->assertInstanceof(Connection::class, $result);
                $result->query($query);
            }
        } finally {
            $pool->close();
        }
    }

    /**
     * @dataProvider getConnectionCounts
     *
     * @param int $count
     */
    public function testConnectionClosedInPool(int $count)
    {
        $processors = $this->makeProcessorSet($count);
        $query = "SELECT * FROM test";
        $result = $this->createMock(Result::class);

        foreach ($processors as $processor) {
            $processor->expects($this->atLeastOnce())
                ->method('query')
                ->with($query)
                ->willReturn(async(function () use ($result): Result {
                    delay(0.01);
                    return $result;
                }));
        }

        $processor = $this->createMock(Processor::class);
        $processor->method('isAlive')
            ->willReturnOnConsecutiveCalls(true, false);
        $processor->expects($this->once())
            ->method('query')
            ->with($query)
            ->willReturn(async(function () use ($result): Result {
                delay(0.01);
                return $result;
            }));

        \array_unshift($processors, $processor);

        $pool = $this->createPool($this->makeConnectionSet($processors));

        try {
            $this->assertSame($count + 1, $pool->getConnectionLimit());
            $futures = [];
            for ($i = 0; $i < $count + 1; ++$i) {
                $futures[] = async(fn() => $pool->query($query));
            }
            Future\all($futures);
            $futures = [];
            for ($i = 0; $i < $count; ++$i) {
                $futures[] = async(fn() => $pool->query($query));
            }
            Future\all($futures);
        } finally {
            $pool->close();
        }
    }
}
