<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
logoutApp();
header('Location: ' . APP_URL . '/login.php');
exit;
