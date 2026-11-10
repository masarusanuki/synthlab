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

/** 通算カウンタのファイル */
function ul_counter_file(): string
{
    return UL_DIR . '/counter.php';
}

/**
 * User-Agent からボットらしさを判定する。
 * アクセス「数」を人が見る数として意味あるものにするため、
 * クローラや自動取得は分けて数える。
 */
function ul_is_bot(?string $ua = null): bool
{
    $ua = strtolower($ua ?? (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') {
        return true;                      // UA 無しは機械とみなす
    }
    static $pat = '/bot|crawl|spider|slurp|bingpreview|yandex|baidu|duckduck|'
                . 'facebookexternalhit|semrush|ahrefs|mj12|dotbot|petalbot|'
                . 'python-requests|curl|wget|libwww|scrapy|headlesschrome|'
                . 'go-http-client|okhttp|java\/|masscan|zgrab|nmap|monitoring|uptime/';
    return (bool)preg_match($pat, $ua);
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

/** 明細と通算の両方を記録する (呼び出し側はこれを使う) */
function ul_record(string $ev, array $data = []): void
{
    ul_write($ev, $data);
    ul_bump($ev, $data);
}

/**
 * 通算カウンタを更新する。
 *
 * 日別ログとは別に、消えない通算値を持つ。
 * ログを整理しても数が残るようにするため。
 * 中身は base64 化 JSON + 定数ガードで、ファイルは 0600。
 * 日別ログから作り直せる (tools/rebuild-counter.php)。
 */
function ul_bump(string $ev, array $data = []): void
{
    if (!ul_ready() || !ul_should_log()) {
        return;
    }
    $f = ul_counter_file();
    $c = ul_counter_read();
    $bot = ul_is_bot();
    $now = date('c');
    $today = substr($now, 0, 10);

    $c['total'] = (int)($c['total'] ?? 0) + 1;
    $c['last'] = $now;
    if (($c['since'] ?? '') === '') {
        $c['since'] = $now;
    }
    if ($bot) {
        $c['bots'] = (int)($c['bots'] ?? 0) + 1;
    } else {
        $c['human'] = (int)($c['human'] ?? 0) + 1;
        $c['by_ev'][$ev] = (int)($c['by_ev'][$ev] ?? 0) + 1;
        $c['by_date'][$today] = (int)($c['by_date'][$today] ?? 0) + 1;
        if ($ev === 'view' && isset($data['page'])) {
            $pg = (string)$data['page'];
            $c['by_page'][$pg] = (int)($c['by_page'][$pg] ?? 0) + 1;
        }
        if ($ev === 'gen') {
            $c['gen_rows'] = (int)($c['gen_rows'] ?? 0) + (int)($data['rows'] ?? 0);
            $k = (string)($data['act'] ?? '');
            if ($k !== '') {
                $c['by_act'][$k] = (int)($c['by_act'][$k] ?? 0) + 1;
            }
        }
        // 重複しない訪問者の数。生の IP は残さずハッシュで持つ
        $h = substr(hash('sha256', 'synthlab-uniq|' . ul_ip()), 0, 12);
        if (!isset($c['uniq'][$h])) {
            if (count($c['uniq'] ?? []) < 50000) {
                $c['uniq'][$h] = $today;
            }
        } else {
            $c['uniq'][$h] = $today;
        }
    }
    // 日別は 800 日分まで
    if (isset($c['by_date']) && count($c['by_date']) > 800) {
        ksort($c['by_date']);
        $c['by_date'] = array_slice($c['by_date'], -800, null, true);
    }
    ul_counter_write($c);
}

/** 通算カウンタを読む */
function ul_counter_read(): array
{
    if (!defined('SYNTHLAB_DEV')) {
        define('SYNTHLAB_DEV', 1);
    }
    $f = ul_counter_file();
    $base = ['total' => 0, 'human' => 0, 'bots' => 0, 'since' => '', 'last' => '',
             'by_ev' => [], 'by_page' => [], 'by_date' => [], 'by_act' => [],
             'gen_rows' => 0, 'uniq' => []];
    if (!is_readable($f)) {
        return $base;
    }
    $b64 = @include $f;
    if (!is_string($b64)) {
        return $base;
    }
    $j = base64_decode($b64, true);
    if ($j === false) {
        return $base;
    }
    $d = json_decode($j, true);
    return is_array($d) ? ($d + $base) : $base;
}

/** 通算カウンタを書く */
function ul_counter_write(array $c): void
{
    $json = json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    $php = "<?php\n"
         . "// SynthLab 通算カウンタ。直接アクセスは 404。\n"
         . "if (!defined('SYNTHLAB_DEV')) { http_response_code(404); exit; }\n"
         . "return '" . base64_encode($json) . "';\n";
    @file_put_contents(ul_counter_file(), $php, LOCK_EX);
    @chmod(ul_counter_file(), 0600);
}

/** ページ閲覧を記録する */
function ul_view(string $page): void
{
    ul_record('view', ['page' => $page]);
}

/**
 * 生成内容を記録する。
 * どんなデータを作ったかを後から再現できるだけの情報を残す。
 */
function ul_gen(string $act, array $cfg, int $rows, float $ms, array $extra = []): void
{
    ul_record('gen', [
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
    ul_record('err', ['where' => $where, 'msg' => mb_substr($msg, 0, 400)]);
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

/**
 * 日別ログから通算カウンタを作り直す。
 *
 * 日別ログが常に正本で、カウンタはその要約。
 * 判定条件 (ボット判定など) を変えたときや、
 * カウンタを消してしまったときに呼ぶ。
 */
function ul_counter_rebuild(): array
{
    $c = ['total' => 0, 'human' => 0, 'bots' => 0, 'since' => '', 'last' => '',
          'by_ev' => [], 'by_page' => [], 'by_date' => [], 'by_act' => [],
          'gen_rows' => 0, 'uniq' => [], 'rebuilt' => date('c')];
    $days = ul_days();
    sort($days);
    foreach ($days as $ymd) {
        foreach (ul_read($ymd) as $r) {
            $ip = (string)($r['ip'] ?? '');
            if (in_array($ip, UL_SKIP_IPS, true)) {
                continue;
            }
            $t = (string)($r['t'] ?? '');
            $ev = (string)($r['ev'] ?? '');
            $date = substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2);

            $c['total']++;
            if ($c['since'] === '' || ($t !== '' && $t < $c['since'])) { $c['since'] = $t; }
            if ($t > $c['last']) { $c['last'] = $t; }

            if (ul_is_bot((string)($r['ua'] ?? ''))) {
                $c['bots']++;
                continue;
            }
            $c['human']++;
            $c['by_ev'][$ev] = (int)($c['by_ev'][$ev] ?? 0) + 1;
            $c['by_date'][$date] = (int)($c['by_date'][$date] ?? 0) + 1;
            if ($ev === 'view' && ($r['page'] ?? '') !== '') {
                $pg = (string)$r['page'];
                $c['by_page'][$pg] = (int)($c['by_page'][$pg] ?? 0) + 1;
            }
            if ($ev === 'gen') {
                $c['gen_rows'] += (int)($r['rows'] ?? 0);
                $k = (string)($r['act'] ?? '');
                if ($k !== '') { $c['by_act'][$k] = (int)($c['by_act'][$k] ?? 0) + 1; }
            }
            $h = substr(hash('sha256', 'synthlab-uniq|' . $ip), 0, 12);
            $c['uniq'][$h] = $date;
        }
    }
    ksort($c['by_date']);
    ul_counter_write($c);
    return $c;
}
