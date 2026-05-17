<?php
/** @deprecated Use admin/reports.php */
require_once __DIR__ . '/../config.php';
header('Location: ' . APP_URL . '/admin/reports.php', true, 302);
exit;
