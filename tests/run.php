<?php
/**
 * Simple Test Runner for QR Bazar
 * Run from terminal: php tests/run.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ticket_gate.php';

if (PHP_SAPI !== 'cli') {
    die("This script must be run from the command line.\n");
}

$passed = 0;
$failed = 0;

function assert_test(string $name, bool $condition, string $message = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "✅ PASS: $name\n";
        $passed++;
    } else {
        echo "❌ FAIL: $name" . ($message ? " ($message)" : "") . "\n";
        $failed++;
    }
}

echo "--- QR Bazar Automated Tests ---\n";

// --- Test 1: Ticket Eligibility (Multi-day fix) ---
$now = time();
$eventStart = $now - 3600; // Started 1 hour ago
$eventEnd   = $now + 86400; // Ends in 1 day

$ticket = ['ticket_tier' => 'day_pass', 'gate_scan_count' => 0, 'status' => 'active'];
$event  = ['event_start' => date('Y-m-d H:i:s', $eventStart), 'event_end' => date('Y-m-d H:i:s', $eventEnd)];

$result = qb_ticket_gate_eligibility($ticket, $event);
assert_test("Day Pass (current window)", $result['ok'], $result['reason'] ?? '');

$eventPast = ['event_start' => date('Y-m-d H:i:s', $now - 172800), 'event_end' => date('Y-m-d H:i:s', $now - 86400)];
$resultPast = qb_ticket_gate_eligibility($ticket, $eventPast);
assert_test("Day Pass (past window)", !$resultPast['ok'], "Should be invalid for past events");

// --- Test 2: Password Hashing ---
$pw = 'password123';
$hash = hashPassword($pw);
assert_test("Password Verify", verifyPassword($pw, $hash), "Hash verification failed");
assert_test("Password Wrong", !verifyPassword('wrong', $hash), "Should not verify wrong password");

// --- Test 3: Rate Limiting ---
startSession();
$_SESSION['qb_login_rl'] = ['n' => 20, 't' => time()]; // Simulating block
assert_test("Login Rate Limit", !qb_rate_limit_login_allow(), "Should block after 20 attempts");
unset($_SESSION['qb_login_rl']);

// --- Test 4: CSRF ---
$token = qb_csrf_token();
assert_test("CSRF Generation", strlen($token) > 0);
assert_test("CSRF Verification", qb_csrf_verify($token));
assert_test("CSRF Invalid", !qb_csrf_verify('wrong-token'));

echo "\nSummary: $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
