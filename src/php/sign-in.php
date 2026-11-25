<?php
require_once __DIR__ . '/config.php';
requireTenant();

header('Location: /#sign-in', true, 302);
exit;
