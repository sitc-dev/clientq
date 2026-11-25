<?php
session_start();
require_once __DIR__ . '/../src/php/config.php';
requireTenant();
$resetPortal = 'tenant';
$resetMode = 'request';
require __DIR__ . '/../src/php/password-reset.php';
