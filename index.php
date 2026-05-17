<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
startSession();

if (isLoggedIn()) {
    qb_redirect_after_login();
}

require __DIR__ . '/public_home.php';
