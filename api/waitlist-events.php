<?php

require_once __DIR__ . '/../src/php/config.php';

requireTenant(true);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$db = getTenantDB();
$streaming = false;

try {
    $revision = static function () use ($db): string {
        $ids = $db->query('SELECT id FROM clients WHERE is_active = 1 ORDER BY id')
            ->fetchAll(PDO::FETCH_COLUMN);
        return hash('sha256', json_encode($ids, JSON_THROW_ON_ERROR));
    };
    $lastRevision = $revision();

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache, no-store');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    $streaming = true;
    echo "retry: 1000\nevent: waitlist-change\ndata: $lastRevision\n\n";
    flush();

    // Bound each connection so PHP workers are released between reconnects.
    $deadline = microtime(true) + 25;
    while (!connection_aborted() && microtime(true) < $deadline) {
        sleep(1);
        $currentRevision = $revision();
        if ($currentRevision !== $lastRevision) {
            echo "event: waitlist-change\ndata: $currentRevision\n\n";
            $lastRevision = $currentRevision;
        } else {
            echo ": heartbeat\n\n";
        }
        flush();
    }
} catch (ClientQDatabaseException $e) {
    clientqLog('ClientQ waitlist events failed: ' . $e->getMessage());
    if ($streaming) {
        echo "event: waitlist-error\ndata: Unable to monitor the waitlist right now.\n\n";
        flush();
    } else {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['error' => 'Unable to monitor the waitlist right now.']);
    }
}
