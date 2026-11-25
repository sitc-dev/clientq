<?php
session_start();
require_once __DIR__ . '/../src/php/config.php';
requirePlatformHost();
$resetPortal = 'admin';
$resetMode = 'reset';
require __DIR__ . '/../src/php/password-reset.php';
