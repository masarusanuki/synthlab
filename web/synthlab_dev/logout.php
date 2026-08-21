<?php
// logout.php -- ログアウト
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
dev_headers();
dev_logout();
header('Location: login.php');
