<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';

startSession();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (!qb_csrf_verify($_POST['csrf'] ?? null)) {
        $error = 'Invalid session token.';
    } elseif ($email === '') {
        $error = 'Please enter your email address.';
    } else {
        $user = db()->fetchOne('SELECT id FROM app_users WHERE login_uid = ?', [$email]);
        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour
            
            db()->execute('DELETE FROM password_resets WHERE email = ?', [$email]);
            db()->execute(
                'INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)',
                [$email, $token, $expires]
            );

            // In a real app, we would send an email here.
            // For this demo, we'll log it and show a success message.
            $resetLink = APP_URL . '/reset-password.php?token=' . $token . '&email=' . urlencode($email);
            qb_log_info("Password reset requested for $email. Link: $resetLink");
            
            $success = 'If an account exists with that email, a reset link has been sent. Please check your inbox (and spam folder).';
            
            // DEV NOTE: For the user to test easily without checking logs:
            if (qb_env('APP_ENV') === 'development') {
                $success .= ' [DEV] Reset Link: <a href="' . $resetLink . '">Click here to reset</a>';
            }
        } else {
            // Same message for security (don't reveal if email exists)
            $success = 'If an account exists with that email, a reset link has been sent.';
        }
    }
}

$csrf = qb_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
</head>
<body class="auth-page">
    <div class="auth-container">
        <div class="auth-card">
            <h1 class="auth-title">Reset your password</h1>
            <p class="auth-subtitle">Enter your email and we'll send you a link to get back into your account.</p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= $success ?></div>
            <?php else: ?>
                <form method="POST" class="auth-form">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" class="form-control" placeholder="name@example.com" required autofocus>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Send Reset Link</button>
                </form>
            <?php endif; ?>

            <div class="auth-footer">
                <a href="login.php" class="auth-link">Back to Login</a>
            </div>
        </div>
    </div>
</body>
</html>
