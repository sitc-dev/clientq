<?php
require_once __DIR__ . '/config.php';
requireTenant();

$isGalleyView = true;
require dirname(__DIR__, 2) . '/index.php';
