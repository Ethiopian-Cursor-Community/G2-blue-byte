<?php
/**
 * Simple Logger for QR Bazar
 */

function qb_log(string $level, string $message, array $context = []): void {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }

    $logFile = $logDir . '/app.log';
    $timestamp = date('Y-m-d H:i:s');
    $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
    $formatted = sprintf("[%s] [%s] %s%s\n", $timestamp, strtoupper($level), $message, $contextStr);

    file_put_contents($logFile, $formatted, FILE_APPEND);
}

function qb_log_error(string $message, array $context = []): void {
    qb_log('error', $message, $context);
}

function qb_log_info(string $message, array $context = []): void {
    qb_log('info', $message, $context);
}
