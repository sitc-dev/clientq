<?php
$primary = normalizeThemeColor($tenantStatusPage['theme_primary'], TENANT_THEME_DEFAULT_PRIMARY);
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$pageName = is_string($requestPath) && ($requestPath === '/admin' || str_starts_with($requestPath, '/admin/'))
    ? 'Staff portal' : 'Waitlist';
$message = $pageName . ($tenantStatusPage['status'] === 'deleted'
    ? ' is no longer available.' : ' is currently inactive.');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($tenantStatusPage['business_name'] . ' ' . $pageName) ?></title>
</head>
<body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#f3f4f6;font-family:system-ui,sans-serif">
    <main style="box-sizing:border-box;width:min(28rem,calc(100% - 2rem));padding:2rem;text-align:center;border-radius:1rem;background:white">
        <?php if ($tenantStatusPage['logo_path']): ?>
            <img src="/<?= htmlspecialchars($tenantStatusPage['logo_path']) ?>"
                alt="<?= htmlspecialchars($tenantStatusPage['business_name']) ?> logo"
                style="max-width:12rem;max-height:6rem;object-fit:contain">
        <?php endif; ?>
        <h1 style="font-size:1.5rem;color:<?= $primary ?>"><?= htmlspecialchars($tenantStatusPage['business_name']) ?></h1>
        <p><?= $message ?></p>
    </main>
</body>
</html>
