<?php

require_once __DIR__ . '/../src/php/exceptions/ClientQDatabaseQueryException.php';
require_once __DIR__ . '/../src/php/exceptions/ClientQDatabaseTransactionException.php';

final class ClientQPDO extends PDO
{
    private string $databaseTarget = 'database';

    public function configureDatabaseTarget(string $target): void
    {
        $this->databaseTarget = $target;
        parent::setAttribute(
            PDO::ATTR_STATEMENT_CLASS,
            [ClientQPDOStatement::class, [$target]]
        );
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        try {
            return parent::prepare($query, $options);
        } catch (PDOException $exception) {
            throw databaseQueryException($this->databaseTarget, 'prepare', $exception);
        }
    }

    public function query(
        string $query,
        ?int $fetchMode = null,
        mixed ...$fetchModeArgs
    ): PDOStatement|false {
        try {
            return $fetchMode === null
                ? parent::query($query)
                : parent::query($query, $fetchMode, ...$fetchModeArgs);
        } catch (PDOException $exception) {
            throw databaseQueryException($this->databaseTarget, 'query', $exception);
        }
    }

    public function exec(string $statement): int|false
    {
        try {
            return parent::exec($statement);
        } catch (PDOException $exception) {
            throw databaseQueryException($this->databaseTarget, 'exec', $exception);
        }
    }

    public function beginTransaction(): bool
    {
        try {
            return parent::beginTransaction();
        } catch (PDOException $exception) {
            throw databaseTransactionException(
                $this->databaseTarget,
                'begin transaction',
                $exception
            );
        }
    }

    public function commit(): bool
    {
        try {
            return parent::commit();
        } catch (PDOException $exception) {
            throw databaseTransactionException($this->databaseTarget, 'commit', $exception);
        }
    }

    public function rollBack(): bool
    {
        try {
            return parent::rollBack();
        } catch (PDOException $exception) {
            throw databaseTransactionException($this->databaseTarget, 'rollback', $exception);
        }
    }

    public function inTransaction(): bool
    {
        try {
            return parent::inTransaction();
        } catch (PDOException $exception) {
            throw databaseTransactionException(
                $this->databaseTarget,
                'check transaction state',
                $exception
            );
        }
    }

    public function lastInsertId(?string $name = null): string|false
    {
        try {
            return parent::lastInsertId($name);
        } catch (PDOException $exception) {
            throw databaseQueryException(
                $this->databaseTarget,
                'read last insert ID',
                $exception
            );
        }
    }
}

final class ClientQPDOStatement extends PDOStatement
{
    protected function __construct(private readonly string $databaseTarget)
    {
    }

    public function execute(?array $params = null): bool
    {
        try {
            return parent::execute($params);
        } catch (PDOException $exception) {
            throw databaseQueryException($this->databaseTarget, 'execute statement', $exception);
        }
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        try {
            return parent::fetch($mode, $cursorOrientation, $cursorOffset);
        } catch (PDOException $exception) {
            throw databaseQueryException($this->databaseTarget, 'fetch row', $exception);
        }
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        try {
            return parent::fetchAll($mode, ...$args);
        } catch (PDOException $exception) {
            throw databaseQueryException($this->databaseTarget, 'fetch rows', $exception);
        }
    }

    public function fetchColumn(int $column = 0): mixed
    {
        try {
            return parent::fetchColumn($column);
        } catch (PDOException $exception) {
            throw databaseQueryException($this->databaseTarget, 'fetch column', $exception);
        }
    }

    public function rowCount(): int
    {
        try {
            return parent::rowCount();
        } catch (PDOException $exception) {
            throw databaseQueryException($this->databaseTarget, 'read affected rows', $exception);
        }
    }

    public function bindValue(
        string|int $param,
        mixed $value,
        int $type = PDO::PARAM_STR
    ): bool {
        try {
            return parent::bindValue($param, $value, $type);
        } catch (PDOException $exception) {
            throw databaseQueryException($this->databaseTarget, 'bind value', $exception);
        }
    }
}
