<?php

namespace Amp\Mysql\Test;

use Amp\Loop;
use Amp\Mysql\ConnectionPool;
use Amp\Mysql\Internal\ConnectionConfig;
use Amp\Mysql\Pool;
use Amp\Mysql\ResultSet;
use Amp\Promise;
use Amp\Success;
use function Amp\Mysql\pool;

class ConnectionPoolTest extends AbstractPoolTest {
    protected function getLink(string $connectionString): Promise {
        return new Success(new ConnectionPool(ConnectionConfig::parseConnectionString($connectionString)));
    }

    protected function createPool(array $connections): Pool {
        $mock = $this->getMockBuilder(ConnectionPool::class)
            ->setConstructorArgs([$this->createMock(ConnectionConfig::class), \count($connections)])
            ->setMethods(['createConnection'])
            ->getMock();

        $mock->method('createConnection')
            ->will($this->returnCallback(function () use ($connections): Promise {
                static $count = 0;
                return new Success($connections[$count++ % \count($connections)]);
            }));

        return $mock;
    }

    public function testSmallPool() {
        Loop::run(function () {
            $db = new ConnectionPool(ConnectionConfig::parseConnectionString("host=".DB_HOST." user=".DB_USER." pass=".DB_PASS." db=test"), 2);

            $queries = [];

            foreach (range(0, 5) as $value) {
                $queries[] = $db->query("SELECT $value");
            }

            $values = [];

            foreach ($queries as $query) {
                $result = yield $query;
                do {
                    while (yield $result->advance(ResultSet::FETCH_ARRAY)) {
                        $values[] = $result->getCurrent()[0];
                    }
                } while (yield $result->nextResultSet());
            }

            $this->assertEquals(\range(0, 5), $values);
        });
    }

    /**
     * @expectedException \Amp\Mysql\InitializationException
     * @expectedExceptionMessage Access denied for user
     */
    public function testWrongPassword() {
        Loop::run(function () {
            $db = pool("host=".DB_HOST.";user=".DB_USER.";pass=the_wrong_password;db=test");

            /* Try a query */
            yield $db->query("CREATE TABLE tmp SELECT 1 AS a, 2 AS b");
        });
    }
}
