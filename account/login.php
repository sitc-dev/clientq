<?php

require_once __DIR__ . '/../src/php/config.php';
requirePlatformHost();
http_response_code(410);
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner access has moved - ClientQ</title>
</head>
<body>
    <main>
        <h1>Owner access has moved to your staff portal</h1>
        <p>Sign in at your business's ClientQ address followed by <code>/admin/login</code>.</p>
        <p>Owners can manage their business and pay invoices there. Employees do not have access to payments.</p>
        <p>The separate business-owner account portal is no longer available.</p>
        <a href="/">ClientQ home</a>
    </main>
</body>
</html>
