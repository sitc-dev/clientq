<?php

class ClientQDatabaseException extends Exception
{
    public readonly array $errorInfo;

    public function __construct(
        public readonly int $mysqlErrorNumber,
        public readonly string $sqlState,
        public readonly string $databaseTarget,
        public readonly string $databaseOperation,
        string $failureType,
        PDOException $previous
    ) {
        $this->errorInfo = $previous->errorInfo ?? [
            $sqlState,
            $mysqlErrorNumber,
            $previous->getMessage(),
        ];

        parent::__construct(
            sprintf(
                'ClientQ database %s failed during %s on %s [MySQL error %d, SQLSTATE %s]: %s',
                $failureType,
                $databaseOperation,
                $databaseTarget,
                $mysqlErrorNumber,
                $sqlState,
                $previous->getMessage()
            ),
            $mysqlErrorNumber,
            $previous
        );
    }
}
