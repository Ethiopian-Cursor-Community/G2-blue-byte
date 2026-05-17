<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';

startSession();

$error = '';
$success = '';
$email = $_GET['email'] ?? '';
$token = $_GET['token'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $token = $_POST['token'] ?? '';
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    if (!qb_csrf_verify($_POST['csrf'] ?? null)) {
        $error = 'Invalid session token.';
    } elseif ($password !== $password_confirm) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } else {
        $reset = db()->fetchOne(
            'SELECT * FROM password_resets WHERE email = ? AND token = ? AND expires_at > NOW()',
            [$email, $token]
        );

        if ($reset) {
            $hashed = hashPassword($password);
            db()->execute('UPDATE app_users SET password_hash = ? WHERE login_uid = ?', [$hashed, $email]);
            db()->execute('DELETE FROM password_resets WHERE email = ?', [$email]);
            
            qb_log_info("Password successfully reset for $email");
            $success = 'Your password has been reset successfully. You can now login with your new password.';
        } else {
            $error = 'Invalid or expired reset link. Please request a new one.';
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
    <title>Reset Password - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
</head>
<body class="auth-page">
    <div class="auth-container">
        <div class="auth-card">
            <h1 class="auth-title">Set new password</h1>
            <p class="auth-subtitle">Create a strong password to secure your account.</p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($success) ?>
                    <div class="mt-3">
                        <a href="login.php" class="btn btn-primary">Go to Login</a>
                    </div>
                </div>
            <?php else: ?>
                <form method="POST" class="auth-form">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                    
                    <div class="form-group">
                        <label for="password">New Password</label>
                        <input type="password" id="password" name="password" class="form-control" placeholder="At least 8 characters" required autofocus>
                    </div>
                    
                    <div class="form-group">
                        <label for="password_confirm">Confirm New Password</label>
                        <input type="password" id="password_confirm" name="password_confirm" class="form-control" placeholder="Confirm your new password" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block">Update Password</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
