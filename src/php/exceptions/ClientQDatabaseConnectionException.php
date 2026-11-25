<?php

require_once __DIR__ . '/ClientQDatabaseException.php';

final class ClientQDatabaseConnectionException extends ClientQDatabaseException
{
    public function __construct(
        int $mysqlErrorNumber,
        string $sqlState,
        string $target,
        PDOException $previous
    ) {
        parent::__construct(
            $mysqlErrorNumber,
            $sqlState,
            $target,
            'connect',
            'connection',
            $previous
        );
    }
}
