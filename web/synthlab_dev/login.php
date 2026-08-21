<?php
// login.php -- ログイン画面
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
dev_headers();
dev_session();

$msg = null;
$cls = 'err';

if (dev_logged_in()) {
    header('Location: index.php');
    exit;
}
if (!empty($_GET['timeout'])) {
    $msg = '一定時間操作がなかったためログアウトしました。';
    $cls = 'info';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dev_check_token($_POST['t'] ?? null)) {
        $msg = 'フォームの有効期限が切れました。もう一度お試しください。';
    } elseif (dev_login((string)($_POST['user'] ?? ''), (string)($_POST['pass'] ?? ''))) {
        $next = (string)($_POST['next'] ?? 'index.php');
        // 外部サイトへのリダイレクトを防ぐ (自サイト内のパスのみ許可)
        if (!preg_match('#^/synthlab_dev/[A-Za-z0-9._~/?=&%-]*$#', $next)) {
            $next = 'index.php';
        }
        header('Location: ' . $next);
        exit;
    } else {
        $msg = 'ユーザ名またはパスワードが違います。';
    }
}
$cfg = dev_config();
$next = (string)($_POST['next'] ?? $_GET['next'] ?? 'index.php');
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>ログイン — SynthLab 開発解説</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="login">
  <h1>SynthLab 開発解説</h1>
  <p class="sub">論文解説・アルゴリズム解説・開発日記<br>関係者向け (要パスワード)</p>
  <?php if ($msg): ?><div class="msg <?= $cls ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <form method="post" action="login.php">
    <input type="hidden" name="t" value="<?= htmlspecialchars(dev_token()) ?>">
    <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
    <?php if (($cfg['user'] ?? '') !== ''): ?>
    <label for="user">ユーザ名</label>
    <input type="text" id="user" name="user" autocomplete="username" required autofocus>
    <?php endif; ?>
    <label for="pass">パスワード</label>
    <input type="password" id="pass" name="pass" autocomplete="current-password" required
      <?= ($cfg['user'] ?? '') === '' ? 'autofocus' : '' ?>>
    <button type="submit">ログイン</button>
  </form>
</div>
</body>
</html>
