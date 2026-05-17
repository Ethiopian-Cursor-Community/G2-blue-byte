<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

$txRef = (string) ($argv[1] ?? '');
if ($txRef === '') {
    echo "Usage: php tools/chapa_webhook_duplicate_test.php <tx_ref>" . PHP_EOL;
    exit(1);
}

$payload = ['tx_ref' => $txRef, 'status' => 'success'];
$raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
$_SERVER['HTTP_CHAPA_SIGNATURE'] = hash_hmac('sha256', (string) $raw, (string) CHAPA_ENCRYPTION_KEY);

$r1 = qb_chapa_process_webhook((string) $raw);
$r2 = qb_chapa_process_webhook((string) $raw);

echo 'FIRST=' . json_encode($r1, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
echo 'SECOND=' . json_encode($r2, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
