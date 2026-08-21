<?php
// feedback.php (lib) -- ご意見の保存と読み出し
//
// 保存先は /var/www/html/synthlab_dev/feedback/ で、
// 閲覧用のページは開発サイト側 (パスワード保護) に置く。
//
// 保存形式について:
//   利用者が入力した文字列をそのまま PHP ファイルに書くと、
//   区切り子の衝突やコード注入の危険がある。そこで
//   「JSON を base64 に符号化した文字列」を単一引用符の中に置く。
//   base64 は英数字と + / = だけなので、PHP の文法を壊す文字を一切含まない。
//   さらに先頭に定数ガードを置くので、HTTP で直接叩かれても 404 になる。

declare(strict_types=1);

const FB_DIR = '/var/www/html/synthlab_dev/feedback';
const FB_MAX_BODY = 4000;      // 本文の最大文字数
const FB_MAX_FILES = 5000;     // 保存件数の上限 (これを超えたら受け付けない)
const FB_MIN_INTERVAL = 20;    // 同一 IP の連投を防ぐ最小間隔 (秒)

/** 保存ディレクトリを用意する */
function fb_ready(): bool
{
    if (!is_dir(FB_DIR)) {
        return false;
    }
    return is_writable(FB_DIR);
}

/** IP のハッシュ (生の IP は保存しない) */
function fb_ip_hash(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return substr(hash('sha256', 'synthlab-fb|' . $ip), 0, 16);
}

/** 連投チェック。前回投稿から FB_MIN_INTERVAL 秒未満なら false */
function fb_rate_ok(): bool
{
    $f = FB_DIR . '/.rate_' . fb_ip_hash();
    $now = time();
    if (is_readable($f)) {
        $t = (int)@file_get_contents($f);
        if ($now - $t < FB_MIN_INTERVAL) {
            return false;
        }
    }
    @file_put_contents($f, (string)$now);
    return true;
}

/**
 * 投稿を保存する。
 * @return array{ok:bool,msg:string}
 */
function fb_save(array $in): array
{
    if (!fb_ready()) {
        return ['ok' => false, 'msg' => '保存先が利用できません。管理者にご連絡ください。'];
    }
    // ハニーポット (人間には見えない欄。埋まっていたら機械的な投稿)
    if (trim((string)($in['website'] ?? '')) !== '') {
        return ['ok' => false, 'msg' => '送信できませんでした。'];
    }
    $body = trim((string)($in['body'] ?? ''));
    if ($body === '') {
        return ['ok' => false, 'msg' => 'ご意見の本文を入力してください。'];
    }
    if (mb_strlen($body) > FB_MAX_BODY) {
        return ['ok' => false, 'msg' => '本文が長すぎます (' . FB_MAX_BODY . ' 文字まで)。'];
    }
    if (!fb_rate_ok()) {
        return ['ok' => false, 'msg' => '短時間に複数回送信されています。少し待ってからお試しください。'];
    }
    $files = glob(FB_DIR . '/fb_*.php') ?: [];
    if (count($files) >= FB_MAX_FILES) {
        return ['ok' => false, 'msg' => '保存件数の上限に達しています。管理者にご連絡ください。'];
    }

    // 選択式の項目は既知の値だけを受け付ける
    $roleOpts = ['', '医師', '臨床検査技師', '看護師', '研究者', '学生', '技術者・開発者', 'その他'];
    $useOpts  = ['', '教育・研修', '研究', 'ソフトウェア開発・動作確認', '統計手法の検証',
                 '見てみただけ', 'その他'];
    // 未指定のキーを参照しないよう、先に取り出してから照合する。
    // in_array('', $opts) は真になるため、三項演算子の真側で $in[...] を
    // 直接触ると未定義キー警告が出る。
    $roleIn = (string)($in['role'] ?? '');
    $useIn  = (string)($in['use'] ?? '');
    $role = in_array($roleIn, $roleOpts, true) ? $roleIn : '';
    $use  = in_array($useIn, $useOpts, true) ? $useIn : '';

    $rate = (string)($in['rate'] ?? '');
    $rate = ctype_digit($rate) && (int)$rate >= 1 && (int)$rate <= 5 ? (int)$rate : null;

    $rec = [
        'ts'      => date('c'),
        'role'    => $role,
        'use'     => $use,
        'rate'    => $rate,
        'body'    => $body,
        'contact' => mb_substr(trim((string)($in['contact'] ?? '')), 0, 200),
        'ua'      => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
        'iph'     => fb_ip_hash(),
    ];
    $json = json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['ok' => false, 'msg' => '保存に失敗しました。'];
    }
    $b64 = base64_encode($json);

    $name = sprintf('fb_%s_%s.php', date('Ymd_His'), bin2hex(random_bytes(4)));
    $php = "<?php\n"
         . "// SynthLab feedback (base64 encoded JSON). 直接アクセスは 404。\n"
         . "if (!defined('SYNTHLAB_DEV')) { http_response_code(404); exit; }\n"
         . "return '" . $b64 . "';\n";
    if (@file_put_contents(FB_DIR . '/' . $name, $php, LOCK_EX) === false) {
        return ['ok' => false, 'msg' => '保存に失敗しました。'];
    }
    @chmod(FB_DIR . '/' . $name, 0644);
    // 呼び出し側が通知メールとログに使えるよう、保存した内容も返す
    return ['ok' => true, 'msg' => 'ご意見をお送りいただきありがとうございました。',
            'file' => $name, 'rec' => $rec];
}

/**
 * 保存済みの投稿を新しい順に読む (開発サイトの閲覧ページ用)。
 * @return array<int,array>
 */
function fb_load_all(): array
{
    if (!defined('SYNTHLAB_DEV')) {
        define('SYNTHLAB_DEV', 1);
    }
    $out = [];
    $files = glob(FB_DIR . '/fb_*.php') ?: [];
    rsort($files);
    foreach ($files as $f) {
        $b64 = @include $f;
        if (!is_string($b64)) {
            continue;
        }
        $json = base64_decode($b64, true);
        if ($json === false) {
            continue;
        }
        $rec = json_decode($json, true);
        if (!is_array($rec)) {
            continue;
        }
        $rec['file'] = basename($f);
        $out[] = $rec;
    }
    return $out;
}
