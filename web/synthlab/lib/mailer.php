<?php
// mailer.php -- 依存関係ゼロの SMTP クライアントと送信設定の保存
//
// PHPMailer 等を入れられない環境なので、ソケットで SMTP を直接話す。
// STARTTLS (587) / 暗黙SSL (465) / 平文、AUTH PLAIN / LOGIN に対応する。
// ローカル MTA が動いている場合は sendmail 経由も選べる。
//
// 設定の保存場所と保護:
//   /var/www/html/synthlab_dev/config/mail.php
//   - 定数ガード付きなので HTTP で直接叩くと 404
//   - www-data が作成し chmod 0600 にするので、
//     www-data と root 以外 (sanuki も) 読めない
//   - 中身は base64 化した JSON。パスワードは SMTP 認証で平文が必要なため
//     可逆に保持せざるを得ない。ファイル権限で守る方針

declare(strict_types=1);

const ML_CONF_DIR  = '/var/www/html/synthlab_dev/config';
const ML_CONF_FILE = ML_CONF_DIR . '/mail.php';
const ML_LOG_FILE  = ML_CONF_DIR . '/maillog.php';
const ML_LOG_KEEP  = 60;          // 保持する送信記録の件数

/** 既定の設定 */
function ml_defaults(): array
{
    return [
        'enabled'    => false,
        'method'     => 'smtp',        // smtp | sendmail
        'host'       => '',
        'port'       => 587,
        'security'   => 'starttls',    // none | starttls | ssl
        'auth'       => true,
        'user'       => '',
        'pass'       => '',
        'verifypeer' => true,
        'timeout'    => 12,
        'from_mail'  => '',
        'from_name'  => 'SynthLab',
        'to'         => '',            // カンマ区切り
        'subject'    => '[SynthLab] ご意見が届きました',
        'helo'       => '',            // 空なら自動判定
    ];
}

/** 設定を読む */
function ml_load(): array
{
    if (!defined('SYNTHLAB_DEV')) {
        define('SYNTHLAB_DEV', 1);
    }
    $c = ml_defaults();
    if (!is_readable(ML_CONF_FILE)) {
        return $c;
    }
    $b64 = @include ML_CONF_FILE;
    if (!is_string($b64)) {
        return $c;
    }
    $json = base64_decode($b64, true);
    if ($json === false) {
        return $c;
    }
    $got = json_decode($json, true);
    if (!is_array($got)) {
        return $c;
    }
    foreach ($c as $k => $v) {
        if (array_key_exists($k, $got)) {
            $c[$k] = $got[$k];
        }
    }
    return $c;
}

/** 設定を保存する */
function ml_save(array $c): array
{
    if (!is_dir(ML_CONF_DIR) || !is_writable(ML_CONF_DIR)) {
        return ['ok' => false, 'msg' => '設定ディレクトリに書き込めません: ' . ML_CONF_DIR];
    }
    $json = json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['ok' => false, 'msg' => '設定を符号化できませんでした。'];
    }
    // base64 なので英数字と + / = だけ。PHP の文法を壊す文字が入りえない
    $php = "<?php\n"
         . "// SynthLab mail settings (base64 encoded JSON).\n"
         . "// 直接アクセスは 404。パーミッションは 0600 (www-data のみ読める)。\n"
         . "if (!defined('SYNTHLAB_DEV')) { http_response_code(404); exit; }\n"
         . "return '" . base64_encode($json) . "';\n";
    $tmp = ML_CONF_FILE . '.tmp';
    if (@file_put_contents($tmp, $php, LOCK_EX) === false) {
        return ['ok' => false, 'msg' => '設定ファイルに書き込めませんでした。'];
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, ML_CONF_FILE)) {
        @unlink($tmp);
        return ['ok' => false, 'msg' => '設定ファイルを置き換えられませんでした。'];
    }
    @chmod(ML_CONF_FILE, 0600);
    return ['ok' => true, 'msg' => '設定を保存しました。'];
}

/** 設定が送信可能な状態か */
function ml_ready(array $c = null): bool
{
    $c = $c ?? ml_load();
    if (empty($c['enabled'])) {
        return false;
    }
    if (trim((string)$c['to']) === '' || trim((string)$c['from_mail']) === '') {
        return false;
    }
    if ($c['method'] === 'smtp' && trim((string)$c['host']) === '') {
        return false;
    }
    return true;
}

/** 送信記録を追記する (失敗の原因を設定画面で見られるように) */
function ml_log(string $result, string $detail): void
{
    if (!defined('SYNTHLAB_DEV')) {
        define('SYNTHLAB_DEV', 1);
    }
    $rows = [];
    if (is_readable(ML_LOG_FILE)) {
        $b64 = @include ML_LOG_FILE;
        if (is_string($b64)) {
            $j = base64_decode($b64, true);
            if ($j !== false) {
                $d = json_decode($j, true);
                if (is_array($d)) {
                    $rows = $d;
                }
            }
        }
    }
    array_unshift($rows, [
        'ts' => date('c'),
        'result' => $result,
        'detail' => mb_substr($detail, 0, 4000),
    ]);
    $rows = array_slice($rows, 0, ML_LOG_KEEP);
    $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    $php = "<?php\n"
         . "if (!defined('SYNTHLAB_DEV')) { http_response_code(404); exit; }\n"
         . "return '" . base64_encode($json) . "';\n";
    @file_put_contents(ML_LOG_FILE, $php, LOCK_EX);
    @chmod(ML_LOG_FILE, 0600);
}

/** 送信記録を読む */
function ml_log_read(): array
{
    if (!defined('SYNTHLAB_DEV')) {
        define('SYNTHLAB_DEV', 1);
    }
    if (!is_readable(ML_LOG_FILE)) {
        return [];
    }
    $b64 = @include ML_LOG_FILE;
    if (!is_string($b64)) {
        return [];
    }
    $j = base64_decode($b64, true);
    if ($j === false) {
        return [];
    }
    $d = json_decode($j, true);
    return is_array($d) ? $d : [];
}

/** 宛先文字列を配列にする */
function ml_recipients(string $to): array
{
    $out = [];
    foreach (preg_split('/[,;\s]+/', $to) ?: [] as $a) {
        $a = trim($a);
        if ($a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL)) {
            $out[] = $a;
        }
    }
    return array_values(array_unique($out));
}

/** 件名などを RFC 2047 で符号化する */
function ml_enc_header(string $s): string
{
    if (preg_match('/^[\x20-\x7E]*$/', $s)) {
        return $s;
    }
    // 日本語を含む場合は base64 で符号化する (75文字ごとに折る)
    $out = [];
    $chunk = '';
    foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        if (strlen(base64_encode($chunk . $ch)) > 45) {
            $out[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
            $chunk = '';
        }
        $chunk .= $ch;
    }
    if ($chunk !== '') {
        $out[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
    }
    return implode("\r\n ", $out);
}

/** ヘッダに使えない文字を落とす (ヘッダ注入対策) */
function ml_safe_header(string $s): string
{
    return trim(str_replace(["\r", "\n", "\0"], ' ', $s));
}

/**
 * メールを送る。
 * @return array{ok:bool,msg:string,trace:string}
 */
function ml_send(string $subject, string $body, array $c = null): array
{
    $c = $c ?? ml_load();
    $to = ml_recipients((string)$c['to']);
    if (!$to) {
        return ['ok' => false, 'msg' => '宛先が正しく設定されていません。', 'trace' => ''];
    }
    $from = trim((string)$c['from_mail']);
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'msg' => '差出人アドレスが正しくありません。', 'trace' => ''];
    }

    $subject = ml_safe_header($subject);
    $fromName = ml_safe_header((string)$c['from_name']);

    // 本文は UTF-8 の base64。行長制限と改行の扱いを気にしなくて済む
    $bodyEnc = chunk_split(base64_encode(str_replace("\r\n", "\n", $body)), 76, "\r\n");

    $headers = [];
    $headers[] = 'From: ' . ($fromName !== ''
        ? ml_enc_header($fromName) . ' <' . $from . '>' : $from);
    $headers[] = 'To: ' . implode(', ', $to);
    $headers[] = 'Subject: ' . ml_enc_header($subject);
    $headers[] = 'Date: ' . date('r');
    $headers[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@'
        . (parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? 'synthlab.local'), PHP_URL_HOST)
           ?: 'synthlab.local') . '>';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: base64';
    $headers[] = 'Auto-Submitted: auto-generated';
    $headers[] = 'X-Mailer: SynthLab';

    if (($c['method'] ?? 'smtp') === 'sendmail') {
        return ml_send_sendmail($to, $headers, $bodyEnc);
    }
    return ml_send_smtp($to, $from, $headers, $bodyEnc, $c);
}

/** ローカル MTA (sendmail) 経由で送る */
function ml_send_sendmail(array $to, array $headers, string $bodyEnc): array
{
    $path = ini_get('sendmail_path');
    if (!$path) {
        $path = '/usr/sbin/sendmail -t -i';
    }
    $msg = implode("\r\n", $headers) . "\r\n\r\n" . $bodyEnc;
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($path, $desc, $pipes, '/tmp');
    if (!is_resource($proc)) {
        return ['ok' => false, 'msg' => 'sendmail を起動できませんでした。', 'trace' => $path];
    }
    fwrite($pipes[0], $msg);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($proc);
    $trace = "sendmail_path: $path\nexit: $rc\nstdout: $out\nstderr: $err";
    if ($rc !== 0) {
        return ['ok' => false, 'msg' => 'sendmail が異常終了しました (exit ' . $rc . ')。',
                'trace' => $trace];
    }
    return ['ok' => true, 'msg' => 'sendmail に渡しました (' . count($to) . ' 件)。',
            'trace' => $trace];
}

/** SMTP の応答を読む (複数行に対応) */
function ml_read(mixed $fp, array &$trace): array
{
    $lines = [];
    $code = 0;
    while (true) {
        $l = fgets($fp, 1024);
        if ($l === false) {
            $trace[] = '<< (接続が切れました)';
            return [0, $lines];
        }
        $l = rtrim($l, "\r\n");
        $trace[] = '<< ' . $l;
        $lines[] = $l;
        if (preg_match('/^(\d{3})([ -])/', $l, $m)) {
            $code = (int)$m[1];
            if ($m[2] === ' ') {
                break;
            }
        } else {
            break;
        }
    }
    return [$code, $lines];
}

/** SMTP へ1行送る */
function ml_write(mixed $fp, string $cmd, array &$trace, bool $secret = false): void
{
    $trace[] = '>> ' . ($secret ? '（認証情報は記録しません）' : $cmd);
    fwrite($fp, $cmd . "\r\n");
}

/** SMTP で直接送る */
function ml_send_smtp(array $to, string $from, array $headers, string $bodyEnc, array $c): array
{
    $trace = [];
    $host = trim((string)$c['host']);
    $port = (int)$c['port'];
    $sec = (string)$c['security'];
    $tmo = max(3, min(60, (int)$c['timeout']));

    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => !empty($c['verifypeer']),
        'verify_peer_name' => !empty($c['verifypeer']),
        'SNI_enabled' => true,
        'peer_name' => $host,
    ]]);
    $scheme = ($sec === 'ssl') ? 'ssl://' : 'tcp://';
    $trace[] = '-- connect ' . $scheme . $host . ':' . $port;
    $fp = @stream_socket_client($scheme . $host . ':' . $port, $eno, $estr, $tmo,
                                STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $trace[] = '-- 接続失敗: ' . $estr . ' (' . $eno . ')';
        return ['ok' => false, 'msg' => 'SMTP サーバに接続できません: ' . $estr,
                'trace' => implode("\n", $trace)];
    }
    stream_set_timeout($fp, $tmo);

    $fail = function (string $msg) use (&$trace, $fp): array {
        @fclose($fp);
        return ['ok' => false, 'msg' => $msg, 'trace' => implode("\n", $trace)];
    };

    [$code] = ml_read($fp, $trace);
    if ($code !== 220) {
        return $fail('SMTP の初期応答が不正です (code ' . $code . ')。');
    }

    $helo = trim((string)($c['helo'] ?? ''));
    if ($helo === '') {
        $helo = gethostname() ?: 'localhost';
    }
    ml_write($fp, 'EHLO ' . $helo, $trace);
    [$code, $lines] = ml_read($fp, $trace);
    if ($code !== 250) {
        // EHLO 非対応なら HELO を試す
        ml_write($fp, 'HELO ' . $helo, $trace);
        [$code, $lines] = ml_read($fp, $trace);
        if ($code !== 250) {
            return $fail('EHLO/HELO が拒否されました (code ' . $code . ')。');
        }
    }
    $caps = strtoupper(implode(' ', $lines));

    if ($sec === 'starttls') {
        if (!str_contains($caps, 'STARTTLS')) {
            return $fail('サーバが STARTTLS に対応していません。'
                . '「暗号化なし」か「SSL/TLS (465)」をお試しください。');
        }
        ml_write($fp, 'STARTTLS', $trace);
        [$code] = ml_read($fp, $trace);
        if ($code !== 220) {
            return $fail('STARTTLS が拒否されました (code ' . $code . ')。');
        }
        $ok = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($ok !== true) {
            $trace[] = '-- TLS ハンドシェイク失敗';
            return $fail('TLS のハンドシェイクに失敗しました。'
                . '証明書の検証を外すと通る場合があります。');
        }
        $trace[] = '-- TLS 確立';
        // TLS 後は EHLO をやり直す
        ml_write($fp, 'EHLO ' . $helo, $trace);
        [$code, $lines] = ml_read($fp, $trace);
        if ($code !== 250) {
            return $fail('TLS 後の EHLO が拒否されました (code ' . $code . ')。');
        }
        $caps = strtoupper(implode(' ', $lines));
    }

    if (!empty($c['auth'])) {
        $user = (string)$c['user'];
        $pass = (string)$c['pass'];
        if ($user === '') {
            return $fail('認証が有効ですがユーザ名が空です。');
        }
        if (str_contains($caps, 'AUTH') && str_contains($caps, 'PLAIN')) {
            ml_write($fp, 'AUTH PLAIN '
                . base64_encode("\0" . $user . "\0" . $pass), $trace, true);
            [$code] = ml_read($fp, $trace);
        } elseif (str_contains($caps, 'AUTH') && str_contains($caps, 'LOGIN')) {
            ml_write($fp, 'AUTH LOGIN', $trace);
            [$code] = ml_read($fp, $trace);
            if ($code === 334) {
                ml_write($fp, base64_encode($user), $trace, true);
                [$code] = ml_read($fp, $trace);
            }
            if ($code === 334) {
                ml_write($fp, base64_encode($pass), $trace, true);
                [$code] = ml_read($fp, $trace);
            }
        } else {
            return $fail('サーバが AUTH PLAIN / AUTH LOGIN を提示していません。');
        }
        if ($code !== 235) {
            return $fail('SMTP 認証に失敗しました (code ' . $code . ')。'
                . 'ユーザ名・パスワードをご確認ください。'
                . 'Gmail などではアプリパスワードが必要です。');
        }
        $trace[] = '-- 認証成功';
    }

    ml_write($fp, 'MAIL FROM:<' . $from . '>', $trace);
    [$code] = ml_read($fp, $trace);
    if ($code !== 250) {
        return $fail('MAIL FROM が拒否されました (code ' . $code . ')。');
    }
    $accepted = 0;
    foreach ($to as $rcpt) {
        ml_write($fp, 'RCPT TO:<' . $rcpt . '>', $trace);
        [$code] = ml_read($fp, $trace);
        if ($code === 250 || $code === 251) {
            $accepted++;
        }
    }
    if ($accepted === 0) {
        return $fail('すべての宛先が拒否されました。');
    }
    ml_write($fp, 'DATA', $trace);
    [$code] = ml_read($fp, $trace);
    if ($code !== 354) {
        return $fail('DATA が拒否されました (code ' . $code . ')。');
    }
    // 行頭のドットをエスケープする (dot-stuffing)
    $data = implode("\r\n", $headers) . "\r\n\r\n" . $bodyEnc;
    $data = preg_replace('/^\./m', '..', $data);
    fwrite($fp, $data . "\r\n.\r\n");
    $trace[] = '>> （本文 ' . strlen($data) . ' バイト） + <CRLF>.<CRLF>';
    [$code] = ml_read($fp, $trace);
    if ($code !== 250) {
        return $fail('本文が受理されませんでした (code ' . $code . ')。');
    }
    ml_write($fp, 'QUIT', $trace);
    ml_read($fp, $trace);
    @fclose($fp);

    return ['ok' => true,
            'msg' => '送信しました (' . $accepted . '/' . count($to) . ' 宛先)。',
            'trace' => implode("\n", $trace)];
}

/**
 * ご意見の通知メールを送る。
 * 送信の成否は記録するが、失敗しても投稿自体は成功扱いにする。
 */
function ml_notify_feedback(array $rec): void
{
    $c = ml_load();
    if (!ml_ready($c)) {
        return;
    }
    $lines = [];
    $lines[] = 'SynthLab の公開ツールにご意見が届きました。';
    $lines[] = '';
    $lines[] = '日時      : ' . ($rec['ts'] ?? '');
    $lines[] = '職種・立場: ' . (($rec['role'] ?? '') !== '' ? $rec['role'] : '(未記入)');
    $lines[] = '利用目的  : ' . (($rec['use'] ?? '') !== '' ? $rec['use'] : '(未記入)');
    $lines[] = '使いやすさ: ' . ($rec['rate'] !== null ? $rec['rate'] . ' / 5' : '(未記入)');
    $lines[] = '連絡先    : ' . (($rec['contact'] ?? '') !== '' ? $rec['contact'] : '(なし)');
    $lines[] = '';
    $lines[] = '--- 本文 ---';
    $lines[] = (string)($rec['body'] ?? '');
    $lines[] = '-------------';
    $lines[] = '';
    $lines[] = '一覧: http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
             . '/synthlab_dev/feedback.php';
    $lines[] = '(このメールは自動送信です)';

    try {
        $r = ml_send((string)$c['subject'], implode("\n", $lines), $c);
        ml_log($r['ok'] ? 'ok' : 'ng',
               'ご意見通知: ' . $r['msg'] . "\n\n" . $r['trace']);
    } catch (Throwable $e) {
        ml_log('ng', 'ご意見通知で例外: ' . $e->getMessage());
    }
}
