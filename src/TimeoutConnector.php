<?php

namespace Amp\Mysql;

use Amp\Promise;
use Amp\Socket;
use Amp\Sql\Connector;
use Amp\TimeoutCancellationToken;
use function Amp\call;

final class TimeoutConnector implements Connector {
    const DEFAULT_TIMEOUT = 5000;

    /** @var int */
    private $timeout;

    /**
     * @param int $timeout Milliseconds until connections attempts are cancelled.
     */
    public function __construct(int $timeout = self::DEFAULT_TIMEOUT) {
        $this->timeout = $timeout;
    }


    /**
     * {@inheritdoc}
     *
     * @throws \Amp\Sql\FailureException If connecting fails.
     */
    public function connect(ConnectionConfig $config = null): Promise {
        if (null === $config) {

        }

        return call(function () use ($config) {
            static $connectContext;

            $connectContext = $connectContext ?? (new Socket\ClientConnectContext())->withTcpNoDelay();
            $token = new TimeoutCancellationToken($this->timeout);

            $socket = yield Socket\connect($config->getResolvedHost(), $connectContext, $token);

            return Connection::connect($socket, $config);
        });
    }
}
