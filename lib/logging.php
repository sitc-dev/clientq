<?php

function clientqLogSourcePath(?string $sourceFile = null): string {
    if ($sourceFile === null) {
        $internalFiles = [
            __FILE__,
            __DIR__ . '/database-errors.php',
            __DIR__ . '/guarded-pdo.php',
        ];
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $frameFile = $frame['file'] ?? null;
            if (is_string($frameFile) && !in_array($frameFile, $internalFiles, true)) {
                $sourceFile = $frameFile;
                break;
            }
        }
    }

    if ($sourceFile === null || $sourceFile === '') {
        return 'unknown';
    }

    $repositoryRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
    $resolvedSource = realpath($sourceFile) ?: $sourceFile;
    $rootPrefix = rtrim($repositoryRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    if (str_starts_with($resolvedSource, $rootPrefix)) {
        return str_replace(
            DIRECTORY_SEPARATOR,
            '/',
            substr($resolvedSource, strlen($rootPrefix))
        );
    }

    return basename($resolvedSource);
}

/** @param 'ERROR'|'INFO'|'WARNING' $level */
function clientqLog(string $message, ?string $sourceFile = null, string $level = 'ERROR'): void {
    if (!in_array($level, ['ERROR', 'INFO', 'WARNING'], true)) {
        throw new InvalidArgumentException('Log level must be ERROR, INFO, or WARNING.');
    }

    $logDirectory = getenv('CLIENTQ_LOG_DIR') ?: dirname(__DIR__) . '/logs';
    $logFile = rtrim($logDirectory, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'clientq-' . date('Y-m-d') . '.log';
    $entry = sprintf(
        "[%s] [%s] [%s] %s\n",
        date('Y-m-d H:i:s'),
        $level,
        str_replace(["\r", "\n"], ['\\r', '\\n'], clientqLogSourcePath($sourceFile)),
        str_replace(["\r", "\n"], ['\\r', '\\n'], $message)
    );

    if (!is_dir($logDirectory) && !mkdir($logDirectory, 0770, true) && !is_dir($logDirectory)) {
        error_log($entry);
        return;
    }

    if (error_log($entry, 3, $logFile) === false) {
        error_log($entry);
    }
}

function clientqLogInvalidSignupToken(string $stage, bool $tokenPresent): void {
    clientqLog(sprintf(
        'ClientQ signup invalid request token: stage=%s ip=%s session_cookie=%s token_present=%s',
        $stage,
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        isset($_COOKIE[session_name()]) ? 'yes' : 'no',
        $tokenPresent ? 'yes' : 'no'
    ), level: 'WARNING');
}

function clientqLogField(string $value): string {
    $encoded = json_encode(
        $value,
        JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES
    );

    return is_string($encoded) ? $encoded : '"unavailable"';
}

function clientqLogFailedLogin(
    string $username,
    string $role,
    string $reason,
    ?string $tenantSlug = null
): void {
    $details = sprintf(
        'ClientQ login failed: username=%s role=%s reason=%s ip=%s',
        clientqLogField($username === '' ? '<empty>' : $username),
        clientqLogField($role),
        clientqLogField($reason),
        clientqLogField($_SERVER['REMOTE_ADDR'] ?? 'unknown')
    );

    if ($tenantSlug !== null) {
        $details .= ' tenant=' . clientqLogField($tenantSlug);
    }

    clientqLog($details, level: 'WARNING');
}
