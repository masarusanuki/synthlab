<?php
// auth.php -- PHP セッションによる認証ガード
//
// Apache の /var/www/ ブロックが AllowOverride None であり sudo も使えないため、
// .htaccess による Basic 認証は使えない。そこでアプリ側で認証する。
// 保護対象の全ページの先頭で require すること。
//
// 文書本体 (content/*.md.php) と設定 (config.php) は定数ガードにより
// 直接 HTTP アクセスでは 404 を返すので、認証を回避して読む経路はない。

declare(strict_types=1);

// Apache 側の実行メモリ制限により PCRE の JIT 確保が失敗し警告が出る環境のため、
// JIT を明示的に無効化する (機能は変わらず、エラーログの汚れを防ぐ)。
@ini_set('pcre.jit', '0');

if (!defined('SYNTHLAB_DEV')) {
    define('SYNTHLAB_DEV', 1);
}

/** 認証設定を読む */
function dev_config(): array
{
    static $c = null;
    if ($c === null) {
        $c = require __DIR__ . '/config.php';
    }
    return $c;
}

/** セッションを開始する (Cookie の属性を安全側に寄せる) */
function dev_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/synthlab_dev/',
        'httponly' => true,
        'samesite' => 'Lax',
        // HTTPS で運用する場合は 'secure' => true を有効にする
        'secure' => !empty($_SERVER['HTTPS']),
    ]);
    session_name('SYNTHLABDEV');
    session_start();
}

/** ログイン済みか */
function dev_logged_in(): bool
{
    dev_session();
    return !empty($_SESSION['dev_ok']);
}

/** ログインを要求する。未認証ならログイン画面へ飛ばす */
function dev_require_login(): void
{
    if (!dev_logged_in()) {
        $to = $_SERVER['REQUEST_URI'] ?? 'index.php';
        header('Location: login.php?next=' . rawurlencode($to));
        exit;
    }
    // 30 分無操作でタイムアウト
    if (isset($_SESSION['dev_seen']) && time() - (int)$_SESSION['dev_seen'] > 1800) {
        dev_logout();
        header('Location: login.php?timeout=1');
        exit;
    }
    $_SESSION['dev_seen'] = time();
}

/** ログイン処理。成功したら true */
function dev_login(string $user, string $pass): bool
{
    dev_session();
    $c = dev_config();
    $uok = ($c['user'] === '' ) || hash_equals((string)$c['user'], $user);
    $pok = password_verify($pass, (string)$c['hash']);
    // ユーザ名の一致・不一致で処理時間が変わらないよう、常に両方を評価する
    if ($uok && $pok) {
        session_regenerate_id(true);   // セッション固定化攻撃を防ぐ
        $_SESSION['dev_ok'] = true;
        $_SESSION['dev_seen'] = time();
        unset($_SESSION['dev_fail']);
        return true;
    }
    $_SESSION['dev_fail'] = (int)($_SESSION['dev_fail'] ?? 0) + 1;
    // 総当たりに対する簡易な遅延 (最大 3 秒)
    usleep((int)min(3000000, 250000 * (int)$_SESSION['dev_fail']));
    return false;
}

/** ログアウト処理 */
function dev_logout(): void
{
    dev_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '',
                  (bool)($p['secure'] ?? false), (bool)($p['httponly'] ?? true));
    }
    session_destroy();
}

/** CSRF トークンを取得する */
function dev_token(): string
{
    dev_session();
    if (empty($_SESSION['dev_csrf'])) {
        $_SESSION['dev_csrf'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['dev_csrf'];
}

/** CSRF トークンを検証する */
function dev_check_token(?string $t): bool
{
    dev_session();
    return !empty($_SESSION['dev_csrf']) && is_string($t)
        && hash_equals((string)$_SESSION['dev_csrf'], $t);
}

/** ページ共通のセキュリティヘッダ */
function dev_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store, private');
}
