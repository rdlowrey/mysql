<?php

namespace Amp\Mysql;

use Amp\Promise;
use Amp\Sql\Operation;
use Amp\Sql\Transaction as SqlTransaction;
use function Amp\call;

final class Transaction implements SqlTransaction
{
    const SAVEPOINT_PREFIX = "amp_";

    /** @var Internal\Processor */
    private $processor;

    /** @var Internal\ReferenceQueue */
    private $queue;

    /** @var int */
    private $isolation;

    /**
     * @param Internal\Processor $processor
     * @param int $isolation
     *
     * @throws \Error If the isolation level is invalid.
     */
    public function __construct(Internal\Processor $processor, int $isolation)
    {
        $this->processor = $processor;
        $this->isolation = $isolation;
        $this->queue = new Internal\ReferenceQueue;
    }

    public function __destruct()
    {
        if ($this->isAlive()) {
            $this->rollback(); // Invokes $this->queue->unreference().
        }
    }

    /**
     * {@inheritdoc}
     *
     * Closes and commits all changes in the transaction.
     */
    public function close()
    {
        if ($this->processor) {
            $this->commit(); // Invokes $this->queue->unreference().
        }
    }

    /**
     * @return int
     */
    public function getIsolationLevel(): int
    {
        return $this->isolation;
    }

    /**
     * {@inheritdoc}
     */
    public function onDestruct(callable $onDestruct)
    {
        $this->queue->onDestruct($onDestruct);
    }

    /**
     * {@inheritdoc}
     */
    public function isAlive(): bool
    {
        return $this->processor !== null && $this->processor->isAlive();
    }

    /**
     * @return bool True if the transaction is active, false if it has been committed or rolled back.
     */
    public function isActive(): bool
    {
        return $this->processor !== null;
    }

    public function lastUsedAt(): int
    {
        return $this->processor->lastDataAt();
    }

    /**
     * {@inheritdoc}
     *
     * @throws TransactionError If the transaction has been committed or rolled back.
     */
    public function query(string $sql): Promise
    {
        if ($this->processor === null) {
            throw new TransactionError("The transaction has been committed or rolled back");
        }

        return call(function () use ($sql) {
            $this->queue->reference();

            try {
                $result = yield $this->processor->query($sql);
            } catch (\Throwable $exception) {
                $this->queue->unreference();
                throw $exception;
            }

            if ($result instanceof Internal\ResultProxy) {
                $result = new ResultSet($result);
                $result->onDestruct([$this->queue, "unreference"]);
            } else {
                $this->queue->unreference();
            }

            return $result;
        });
    }

    /**
     * {@inheritdoc}
     *
     * @throws TransactionError If the transaction has been committed or rolled back.
     */
    public function prepare(string $sql): Promise
    {
        if ($this->processor === null) {
            throw new TransactionError("The transaction has been committed or rolled back");
        }

        $this->queue->reference();

        $promise = $this->processor->prepare($sql);

        $promise->onResolve(function ($exception, $statement) {
            if ($statement instanceof Operation) {
                $statement->onDestruct([$this->queue, "unreference"]);
                return;
            }

            $this->queue->unreference();
        });

        return $promise;
    }

    /**
     * {@inheritdoc}
     *
     * @throws TransactionError If the transaction has been committed or rolled back.
     */
    public function execute(string $sql, array $params = []): Promise
    {
        if ($this->processor === null) {
            throw new TransactionError("The transaction has been committed or rolled back");
        }

        return call(function () use ($sql, $params) {
            $this->queue->reference();

            try {
                /** @var Statement $statement */
                $statement = yield $this->processor->prepare($sql);
                $result = yield $statement->execute($params);
            } catch (\Throwable $exception) {
                $this->queue->unreference();
                throw $exception;
            }

            if ($result instanceof ResultSet) {
                $result->onDestruct([$this->queue, "unreference"]);
            } else {
                $this->queue->unreference();
            }

            return $result;
        });
    }

    /**
     * Commits the transaction and makes it inactive.
     *
     * @return Promise<CommandResult>
     *
     * @throws TransactionError If the transaction has been committed or rolled back.
     */
    public function commit(): Promise
    {
        if ($this->processor === null) {
            throw new TransactionError("The transaction has been committed or rolled back");
        }

        $promise = $this->processor->query("COMMIT");
        $this->processor = null;
        $promise->onResolve([$this->queue, "unreference"]);

        return $promise;
    }

    /**
     * Rolls back the transaction and makes it inactive.
     *
     * @return Promise<CommandResult>
     *
     * @throws TransactionError If the transaction has been committed or rolled back.
     */
    public function rollback(): Promise
    {
        if ($this->processor === null) {
            throw new TransactionError("The transaction has been committed or rolled back");
        }

        $promise = $this->processor->query("ROLLBACK");
        $this->processor = null;
        $promise->onResolve([$this->queue, "unreference"]);

        return $promise;
    }

    /**
     * Creates a savepoint with the given identifier.
     *
     * @param string $identifier Savepoint identifier.
     *
     * @return Promise<CommandResult>
     *
     * @throws TransactionError If the transaction has been committed or rolled back.
     */
    public function createSavepoint(string $identifier): Promise
    {
        return $this->query(\sprintf("SAVEPOINT `%s%s`", self::SAVEPOINT_PREFIX, \sha1($identifier)));
    }

    /**
     * Rolls back to the savepoint with the given identifier.
     *
     * @param string $identifier Savepoint identifier.
     *
     * @return Promise<CommandResult>
     *
     * @throws TransactionError If the transaction has been committed or rolled back.
     */
    public function rollbackTo(string $identifier): Promise
    {
        return $this->query(\sprintf("ROLLBACK TO `%s%s`", self::SAVEPOINT_PREFIX, \sha1($identifier)));
    }

    /**
     * Releases the savepoint with the given identifier.
     *
     * @param string $identifier Savepoint identifier.
     *
     * @return Promise<CommandResult>
     *
     * @throws TransactionError If the transaction has been committed or rolled back.
     */
    public function releaseSavepoint(string $identifier): Promise
    {
        return $this->query(\sprintf("RELEASE SAVEPOINT `%s%s`", self::SAVEPOINT_PREFIX, \sha1($identifier)));
    }
}
