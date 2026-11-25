<?php

require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/../src/php/exceptions/ClientQDatabaseConnectionException.php';
require_once __DIR__ . '/../src/php/exceptions/ClientQDatabaseQueryException.php';
require_once __DIR__ . '/../src/php/exceptions/ClientQDatabaseTransactionException.php';
require_once __DIR__ . '/guarded-pdo.php';

function mysqlErrorNumber(PDOException $exception): int {
    $driverCode = $exception->errorInfo[1] ?? null;
    if (is_numeric($driverCode)) {
        return (int)$driverCode;
    }

    if (preg_match(
        '/SQLSTATE\[[0-9A-Za-z]+\]\s*\[(\d+)\]/',
        $exception->getMessage(),
        $matches
    ) || preg_match(
        '/SQLSTATE\[[0-9A-Za-z]+\]:[^:]*:\s*(\d+)\b/',
        $exception->getMessage(),
        $matches
    )) {
        return (int)$matches[1];
    }

    return 0;
}

function mysqlSqlState(PDOException $exception): string {
    $sqlState = $exception->errorInfo[0] ?? $exception->getCode();
    if (is_string($sqlState) && $sqlState !== '' && $sqlState !== '0') {
        return $sqlState;
    }

    if (preg_match('/SQLSTATE\[([0-9A-Za-z]+)\]/', $exception->getMessage(), $matches)) {
        return $matches[1];
    }

    return 'unknown';
}

function databaseConnectionException(
    string $target,
    PDOException $exception
): ClientQDatabaseConnectionException {
    return new ClientQDatabaseConnectionException(
        mysqlErrorNumber($exception),
        mysqlSqlState($exception),
        $target,
        $exception
    );
}

function databaseQueryException(
    string $target,
    string $operation,
    PDOException $exception
): ClientQDatabaseQueryException {
    $databaseException = new ClientQDatabaseQueryException(
        mysqlErrorNumber($exception),
        mysqlSqlState($exception),
        $target,
        $operation,
        $exception
    );
    clientqLog($databaseException->getMessage());

    return $databaseException;
}

function databaseTransactionException(
    string $target,
    string $operation,
    PDOException $exception
): ClientQDatabaseTransactionException {
    $databaseException = new ClientQDatabaseTransactionException(
        mysqlErrorNumber($exception),
        mysqlSqlState($exception),
        $target,
        $operation,
        $exception
    );
    clientqLog($databaseException->getMessage());

    return $databaseException;
}

function connectToMysql(
    string $dsn,
    string $user,
    string $password,
    array $options,
    string $target,
    ?callable $connector = null
): PDO {
    $connector ??= static function (
        string $connectionDsn,
        string $connectionUser,
        string $connectionPassword,
        array $connectionOptions
    ) use ($target): PDO {
        $connection = new ClientQPDO(
            $connectionDsn,
            $connectionUser,
            $connectionPassword,
            $connectionOptions
        );
        $connection->configureDatabaseTarget($target);

        return $connection;
    };

    try {
        return $connector($dsn, $user, $password, $options);
    } catch (PDOException $e) {
        $connectionException = databaseConnectionException($target, $e);
        clientqLog($connectionException->getMessage());
        throw $connectionException;
    }
}
