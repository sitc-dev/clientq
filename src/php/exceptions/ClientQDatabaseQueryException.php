<?php

require_once __DIR__ . '/ClientQDatabaseException.php';

final class ClientQDatabaseQueryException extends ClientQDatabaseException
{
    public function __construct(
        int $mysqlErrorNumber,
        string $sqlState,
        string $target,
        string $operation,
        PDOException $previous
    ) {
        parent::__construct(
            $mysqlErrorNumber,
            $sqlState,
            $target,
            $operation,
            'query',
            $previous
        );
    }
}
