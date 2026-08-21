<?php
// usagelog.php -- アクセスと生成内容の記録
//
// 保存形式:
//   1日1ファイル  /var/www/html/synthlab_dev/logs/ul_YYYYMMDD.php
//   先頭に定数ガード + __halt_compiler() を置き、その後ろに
//   「1行 = base64 化した JSON」を追記していく。
//
//   - HTTP で直接叩くとガードが 404 を返して終了する。
//     仮にガードを抜けても __halt_compiler() の後ろは PHP が出力しない
//   - 追記が O(1) で済む (配列全体の読み書きが不要なので競合しにくい)
//   - base64 なので利用者由来の文字列が行を壊すことがない
//
// ファイルは www-data が作成し 0600 にするので www-data と root しか読めない。

declare(strict_types=1);

const UL_DIR = '/var/www/html/synthlab_dev/logs';
const UL_HALT = '__halt_compiler();';

/**
 * 記録しない接続元。
 * localhost は動作確認や cron の自己アクセスで埋まってしまい、
 * 実利用の把握を妨げるので除外する。
 */
const UL_SKIP_IPS = ['127.0.0.1', '::1', 'localhost'];

/** 記録できる状態か */
function ul_ready(): bool
{
    return is_dir(UL_DIR) && is_writable(UL_DIR);
}

/** 当日のログファイルのパス */
function ul_file(?string $ymd = null): string
{
    return UL_DIR . '/ul_' . ($ymd ?? date('Ymd')) . '.php';
}

/** クライアントの IP を返す (プロキシ経由は考慮しない) */
function ul_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

/** この接続元を記録対象にするか */
function ul_should_log(?string $ip = null): bool
{
    $ip = $ip ?? ul_ip();
    return !in_array($ip, UL_SKIP_IPS, true);
}

/**
 * 1件記録する。
 * 失敗しても本体の動作を止めない (すべて抑制付き)。
 */
function ul_write(string $ev, array $data = []): void
{
    if (!ul_ready() || !ul_should_log()) {
        return;
    }
    $rec = [
        't'  => date('c'),
        'ev' => $ev,
        'ip' => ul_ip(),
        'ua' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
        'ref' => mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 200),
    ] + $data;
    $json = json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    $f = ul_file();
    $new = !file_exists($f);
    $line = base64_encode($json) . "\n";
    if ($new) {
        $head = "<?php\n"
              . "// SynthLab usage log. 1 行 = base64(JSON)。直接アクセスは 404。\n"
              . "if (!defined('SYNTHLAB_DEV')) { http_response_code(404); exit; }\n"
              . UL_HALT . "\n";
        @file_put_contents($f, $head . $line, LOCK_EX);
        @chmod($f, 0600);
        return;
    }
    @file_put_contents($f, $line, FILE_APPEND | LOCK_EX);
}

/** ページ閲覧を記録する */
function ul_view(string $page): void
{
    ul_write('view', ['page' => $page]);
}

/**
 * 生成内容を記録する。
 * どんなデータを作ったかを後から再現できるだけの情報を残す。
 */
function ul_gen(string $act, array $cfg, int $rows, float $ms, array $extra = []): void
{
    ul_write('gen', [
        'act'   => $act,               // preview | csv
        'mode'  => $cfg['mode'],
        'npat'  => $cfg['npat'],
        'pts'   => $cfg['points'],
        'sex'   => $cfg['sex'],
        'trunc' => $cfg['trunc'],
        'corr'  => $cfg['corrscale'],
        'seed'  => $cfg['seed'],
        'sfrac' => $cfg['sfrac'],
        'noise' => $cfg['noise'],
        'intv'  => $cfg['interval'],
        'nit'   => count($cfg['items']),
        'items' => implode(' ', $cfg['items']),
        'rows'  => $rows,
        'ms'    => round($ms, 1),
    ] + $extra);
}

/** エラーを記録する */
function ul_err(string $where, string $msg): void
{
    ul_write('err', ['where' => $where, 'msg' => mb_substr($msg, 0, 400)]);
}

/** 存在する日付 (YYYYMMDD) の一覧を新しい順に返す */
function ul_days(): array
{
    $out = [];
    foreach (glob(UL_DIR . '/ul_*.php') ?: [] as $f) {
        if (preg_match('/ul_(\d{8})\.php$/', $f, $m)) {
            $out[] = $m[1];
        }
    }
    rsort($out);
    return $out;
}

/**
 * 指定日のレコードを読む (古い順)。
 * @return array<int,array>
 */
function ul_read(string $ymd): array
{
    if (!preg_match('/^\d{8}$/', $ymd)) {
        return [];
    }
    $f = ul_file($ymd);
    if (!is_readable($f)) {
        return [];
    }
    $raw = (string)@file_get_contents($f);
    $p = strpos($raw, UL_HALT);
    if ($p === false) {
        return [];
    }
    $body = substr($raw, $p + strlen(UL_HALT));
    $out = [];
    foreach (preg_split('/\r?\n/', $body) ?: [] as $ln) {
        $ln = trim($ln);
        if ($ln === '') {
            continue;
        }
        $j = base64_decode($ln, true);
        if ($j === false) {
            continue;
        }
        $r = json_decode($j, true);
        if (is_array($r)) {
            $out[] = $r;
        }
    }
    return $out;
}

/** 複数日をまとめて読む (新しい順) */
function ul_read_days(array $days): array
{
    $out = [];
    foreach ($days as $d) {
        foreach (ul_read($d) as $r) {
            $out[] = $r;
        }
    }
    usort($out, fn($a, $b) => strcmp((string)$b['t'], (string)$a['t']));
    return $out;
}
