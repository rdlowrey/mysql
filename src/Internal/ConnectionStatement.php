<?php

namespace Amp\Mysql\Internal;

use Amp\DeferredFuture;
use Amp\Mysql\Result;
use Amp\Mysql\Statement;
use Amp\Sql\ConnectionException;
use Revolt\EventLoop;

final class ConnectionStatement implements Statement
{
    private int $paramCount;
    private int $numParamCount;
    private array $named = [];
    private array $byNamed;
    private string $query;
    private int $stmtId;
    private array $prebound = [];

    private ?Processor $processor;

    private readonly ResultProxy $result;

    private int $lastUsedAt;

    public function __construct(Processor $processor, string $query, int $stmtId, array $named, ResultProxy $result)
    {
        $this->processor = $processor;
        $this->query = $query;
        $this->stmtId = $stmtId;
        $this->result = $result;
        $this->numParamCount = $this->paramCount = $this->result->columnsToFetch;
        $this->byNamed = $named;

        foreach ($named as $name => $ids) {
            foreach ($ids as $id) {
                $this->named[$id] = $name;
                $this->numParamCount--;
            }
        }

        $this->lastUsedAt = \time();
    }

    private function getProcessor(): Processor
    {
        if ($this->processor === null) {
            throw new \Error("The statement has been closed");
        }

        if (!$this->processor->isAlive()) {
            throw new ConnectionException("Connection went away");
        }

        return $this->processor;
    }

    public function isAlive(): bool
    {
        if ($this->processor === null) {
            return false;
        }

        return $this->processor->isAlive();
    }

    public function bind(int|string $paramId, mixed $data): void
    {
        if (\is_int($paramId)) {
            if ($paramId >= $this->numParamCount) {
                throw new \Error("Parameter $paramId is not defined for this prepared statement");
            }
            $i = $paramId;
        } else {
            if (!isset($this->byNamed[$paramId])) {
                throw new \Error("Parameter :$paramId is not defined for this prepared statement");
            }
            $array = $this->byNamed[$paramId];
            $i = \reset($array);
        }

        if (!\is_scalar($data) && !(\is_object($data) && \method_exists($data, '__toString'))) {
            throw new \TypeError("Data must be scalar or object that implements __toString method");
        }

        do {
            $realId = -1;
            while (isset($this->named[++$realId]) || $i-- > 0) {
                if (!\is_numeric($paramId) && isset($this->named[$realId]) && $this->named[$realId] == $paramId) {
                    break;
                }
            }

            $this->getProcessor()->bindParam($this->stmtId, $realId, $data);
        } while (isset($array) && $i = \next($array));

        if (isset($this->prebound[$paramId])) {
            $this->prebound[$paramId] .= (string) $data;
        } else {
            $this->prebound[$paramId] = (string) $data;
        }
    }

    public function execute(array $params = []): Result
    {
        $this->lastUsedAt = \time();

        $prebound = $args = [];
        for ($unnamed = $i = 0; $i < $this->paramCount; $i++) {
            if (isset($this->named[$i])) {
                $name = $this->named[$i];
                if (\array_key_exists($name, $params)) {
                    $args[$i] = $params[$name];
                } elseif (!\array_key_exists($name, $this->prebound)) {
                    throw new \Error("Named parameter '$name' missing for executing prepared statement");
                } else {
                    $prebound[$i] = $this->prebound[$name];
                }
            } elseif (\array_key_exists($unnamed, $params)) {
                $args[$i] = $params[$unnamed];
                $unnamed++;
            } elseif (!\array_key_exists($unnamed, $this->prebound)) {
                throw new \Error("Parameter $unnamed for prepared statement missing");
            } else {
                $prebound[$i] = $this->prebound[$unnamed++];
            }
        }

        return $this->getProcessor()
            ->execute($this->stmtId, $this->query, $this->result->params, $prebound, $args)
            ->await();
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function reset(): void
    {
        $this->getProcessor()
            ->resetStmt($this->stmtId)
            ->await();
    }

    public function getFields(): ?array
    {
        if ($this->result->state >= ResultProxy::COLUMNS_FETCHED) {
            return $this->result->columns;
        }

        if (isset($this->result->deferreds[ResultProxy::COLUMNS_FETCHED][0])) {
            return $this->result->deferreds[ResultProxy::COLUMNS_FETCHED][0][0]->promise();
        }

        $deferred = new DeferredFuture;
        $this->result->deferreds[ResultProxy::COLUMNS_FETCHED][0] = [$deferred, &$this->result->columns, null];
        return $deferred->getFuture()->await();
    }

    public function getLastUsedAt(): int
    {
        return $this->lastUsedAt;
    }

    public function __destruct()
    {
        if ($this->processor) {
            $processor = $this->processor;
            $stmtId = $this->stmtId;
            EventLoop::queue(static fn () => self::close($processor, $stmtId));
        }
    }

    private static function close(Processor $processor, int $stmtId): void
    {
        $processor->closeStmt($stmtId);
        $processor->unreference();
    }
}
