<?php
// mailsetup.php -- メール送信設定 (要ログイン)
//
// 認証情報を扱う画面なので、必ず開発サイト側 (パスワード保護) に置く。
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
dev_require_login();
dev_headers();
require_once __DIR__ . '/lib/md.php';
require_once __DIR__ . '/lib/layout.php';
require_once '/var/www/html/synthlab/lib/mailer.php';

$msg = null;
$msgCls = 'info';
$trace = '';
$cfg = ml_load();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dev_check_token($_POST['t'] ?? null)) {
        $msg = 'フォームの有効期限が切れました。もう一度お試しください。';
        $msgCls = 'err';
    } else {
        $new = $cfg;
        $new['enabled']    = !empty($_POST['enabled']);
        $new['method']     = ($_POST['method'] ?? 'smtp') === 'sendmail' ? 'sendmail' : 'smtp';
        $new['host']       = ml_safe_header(trim((string)($_POST['host'] ?? '')));
        $new['port']       = max(1, min(65535, (int)($_POST['port'] ?? 587)));
        $sec = (string)($_POST['security'] ?? 'starttls');
        $new['security']   = in_array($sec, ['none', 'starttls', 'ssl'], true) ? $sec : 'starttls';
        $new['auth']       = !empty($_POST['auth']);
        $new['user']       = ml_safe_header(trim((string)($_POST['user'] ?? '')));
        $new['verifypeer'] = !empty($_POST['verifypeer']);
        $new['timeout']    = max(3, min(60, (int)($_POST['timeout'] ?? 12)));
        $new['from_mail']  = ml_safe_header(trim((string)($_POST['from_mail'] ?? '')));
        $new['from_name']  = ml_safe_header(trim((string)($_POST['from_name'] ?? '')));
        $new['to']         = ml_safe_header(trim((string)($_POST['to'] ?? '')));
        $new['subject']    = ml_safe_header(trim((string)($_POST['subject'] ?? '')));
        $new['helo']       = ml_safe_header(trim((string)($_POST['helo'] ?? '')));

        // パスワードは空欄なら現在の値を保つ (画面には出さない)
        $pw = (string)($_POST['pass'] ?? '');
        if ($pw !== '') {
            $new['pass'] = $pw;
        }
        if (!empty($_POST['clearpass'])) {
            $new['pass'] = '';
        }

        $act = (string)($_POST['act'] ?? 'save');
        $r = ml_save($new);
        $cfg = $new;
        if (!$r['ok']) {
            $msg = $r['msg'];
            $msgCls = 'err';
        } elseif ($act === 'test') {
            $body = "SynthLab のメール送信テストです。\n\n"
                  . "この本文が読めていれば、設定は正しく動作しています。\n"
                  . '送信日時: ' . date('c') . "\n"
                  . '方式: ' . $new['method']
                  . ($new['method'] === 'smtp'
                     ? ' / ' . $new['host'] . ':' . $new['port'] . ' / ' . $new['security'] : '')
                  . "\n";
            $s = ml_send('[SynthLab] 送信テスト', $body, $new);
            ml_log($s['ok'] ? 'ok' : 'ng', 'テスト送信: ' . $s['msg'] . "\n\n" . $s['trace']);
            $msg = ($s['ok'] ? 'テスト送信に成功しました。' : 'テスト送信に失敗しました。')
                 . ' ' . $s['msg'];
            $msgCls = $s['ok'] ? 'info' : 'err';
            $trace = $s['trace'];
        } else {
            $msg = $r['msg'];
            $msgCls = 'info';
        }
    }
}

$hasPass = ((string)$cfg['pass'] !== '');
$logs = ml_log_read();
$e = fn($k) => htmlspecialchars((string)$cfg[$k]);
$ck = fn($k) => !empty($cfg[$k]) ? ' checked' : '';
$so = fn($k, $v) => ((string)$cfg[$k] === $v) ? ' selected' : '';

dev_head('メール送信設定', null, []);
?>
<h1>メール送信設定</h1>

<p>公開ツールの<a href="/synthlab/feedback.php" target="_blank" rel="noopener">ご意見フォーム</a>に
投稿があったとき、指定した宛先へ通知メールを送ります。</p>

<?php if ($msg !== null): ?>
  <div class="msg <?= $msgCls === 'err' ? 'err' : 'info' ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<blockquote>
  <p><strong>設定の保存場所と保護.</strong>
  <code><?= htmlspecialchars(ML_CONF_FILE) ?></code> に保存します。</p>
  <ul>
    <li>中身は base64 化した JSON。先頭に定数ガードがあるので
        <strong>HTTP で直接叩くと 404</strong> になります</li>
    <li>ファイルは <code>www-data</code> が作成し <code>0600</code> にするので、
        <strong>www-data と root 以外は読めません</strong>
        (このサーバの <code>sanuki</code> ユーザからも読めません)</li>
    <li>SMTP 認証には平文のパスワードが必要なため、可逆な形で保持せざるを得ません。
        ファイル権限で守る方針です。<strong>専用のアプリパスワードを使い、
        主要なアカウントのパスワードは入れないでください</strong></li>
  </ul>
  <p style="margin-bottom:0">この画面から設定するのが基本ですが、
  ファイルを直接置きたい場合は下の「ファイルで設定する場合」をご覧ください。</p>
</blockquote>

<form method="post" action="mailsetup.php">
<input type="hidden" name="t" value="<?= htmlspecialchars(dev_token()) ?>">

<h2>1. 有効化と方式</h2>
<div class="scroll">
<table>
<tbody>
<tr><th class="l" style="width:14em">通知メール</th><td class="l">
  <label class="chk"><input type="checkbox" name="enabled" value="1"<?= $ck('enabled') ?>>
    ご意見の投稿時にメールを送る</label>
</td></tr>
<tr><th class="l">送信方式</th><td class="l">
  <select name="method">
    <option value="smtp"<?= $so('method','smtp') ?>>SMTP サーバに直接接続する（推奨）</option>
    <option value="sendmail"<?= $so('method','sendmail') ?>>ローカルの sendmail に渡す</option>
  </select>
  <div style="font-size:12.5px;color:var(--muted);margin-top:4px">
    このサーバには <code>/usr/sbin/sendmail</code> (postfix) がありますが、
    <strong>postfix は現在停止しています</strong>
    (<code>systemctl is-active postfix</code> → inactive)。
    sendmail 方式を使う場合は postfix を起動して配送設定を行う必要があります。
    外部の SMTP サーバがある場合は SMTP 方式のほうが確実です。
  </div>
</td></tr>
</tbody>
</table>
</div>

<h2>2. SMTP サーバ</h2>
<div class="scroll">
<table>
<tbody>
<tr><th class="l" style="width:14em">ホスト名</th><td class="l">
  <input type="text" name="host" value="<?= $e('host') ?>" style="width:24em"
    placeholder="例: smtp.gmail.com / smtp.office365.com / mail.example.ac.jp"></td></tr>
<tr><th class="l">ポート</th><td class="l">
  <input type="number" name="port" value="<?= $e('port') ?>" min="1" max="65535" style="width:7em">
  <span style="font-size:12.5px;color:var(--muted)">
    587 = STARTTLS / 465 = SSL・TLS / 25 = 暗号化なし</span></td></tr>
<tr><th class="l">暗号化</th><td class="l">
  <select name="security" style="width:16em">
    <option value="starttls"<?= $so('security','starttls') ?>>STARTTLS（587 番で一般的）</option>
    <option value="ssl"<?= $so('security','ssl') ?>>SSL/TLS（465 番）</option>
    <option value="none"<?= $so('security','none') ?>>暗号化なし（25 番・非推奨）</option>
  </select></td></tr>
<tr><th class="l">証明書の検証</th><td class="l">
  <label class="chk"><input type="checkbox" name="verifypeer" value="1"<?= $ck('verifypeer') ?>>
    サーバ証明書を検証する</label>
  <div style="font-size:12.5px;color:var(--muted)">
    自己署名証明書の社内サーバで TLS ハンドシェイクに失敗する場合は外してください。
    通信内容は暗号化されますが、相手が本物かの確認はしなくなります。</div></td></tr>
<tr><th class="l">タイムアウト</th><td class="l">
  <input type="number" name="timeout" value="<?= $e('timeout') ?>" min="3" max="60" style="width:7em"> 秒</td></tr>
<tr><th class="l">HELO 名</th><td class="l">
  <input type="text" name="helo" value="<?= $e('helo') ?>" style="width:20em"
    placeholder="空欄ならホスト名を自動判定">
  <div style="font-size:12.5px;color:var(--muted)">
    自動判定: <code><?= htmlspecialchars(gethostname() ?: 'localhost') ?></code></div></td></tr>
</tbody>
</table>
</div>

<h2>3. 認証</h2>
<div class="scroll">
<table>
<tbody>
<tr><th class="l" style="width:14em">SMTP 認証</th><td class="l">
  <label class="chk"><input type="checkbox" name="auth" value="1"<?= $ck('auth') ?>>
    認証を行う（AUTH PLAIN / LOGIN を自動選択）</label></td></tr>
<tr><th class="l">ユーザ名</th><td class="l">
  <input type="text" name="user" value="<?= $e('user') ?>" style="width:24em"
    autocomplete="off" placeholder="多くの場合はメールアドレス"></td></tr>
<tr><th class="l">パスワード</th><td class="l">
  <input type="password" name="pass" value="" style="width:24em" autocomplete="new-password"
    placeholder="<?= $hasPass ? '設定済み（変更しない場合は空欄）' : '未設定' ?>">
  <div style="font-size:12.5px;color:var(--muted);margin-top:4px">
    現在: <strong><?= $hasPass ? '設定済み' : '未設定' ?></strong>。
    <strong>入力した値は画面に表示しません。</strong>変更しないときは空欄のままにしてください。
    <label class="chk" style="display:inline-block;margin-left:8px">
      <input type="checkbox" name="clearpass" value="1"> パスワードを消す</label>
  </div>
  <div style="font-size:12.5px;color:var(--warn-ink);margin-top:6px">
    Gmail・Microsoft 365 では通常のログインパスワードは使えません。
    2段階認証を有効にしたうえで<strong>アプリパスワード</strong>を発行して入力してください。
  </div></td></tr>
</tbody>
</table>
</div>

<h2>4. 差出人と宛先</h2>
<div class="scroll">
<table>
<tbody>
<tr><th class="l" style="width:14em">差出人アドレス</th><td class="l">
  <input type="email" name="from_mail" value="<?= $e('from_mail') ?>" style="width:24em"
    placeholder="synthlab@example.ac.jp">
  <div style="font-size:12.5px;color:var(--muted)">
    多くの SMTP サーバでは認証ユーザと一致していないと拒否されます。</div></td></tr>
<tr><th class="l">差出人名</th><td class="l">
  <input type="text" name="from_name" value="<?= $e('from_name') ?>" style="width:20em"></td></tr>
<tr><th class="l">宛先</th><td class="l">
  <input type="text" name="to" value="<?= $e('to') ?>" style="width:30em"
    placeholder="you@example.ac.jp, another@example.ac.jp">
  <div style="font-size:12.5px;color:var(--muted)">
    カンマ・空白・セミコロンで複数指定できます。
    <?php $r = ml_recipients((string)$cfg['to']);
      echo '現在の解釈: ' . ($r ? '<strong>' . htmlspecialchars(implode(', ', $r))
        . '</strong> (' . count($r) . ' 件)' : '<strong>なし</strong>'); ?></div></td></tr>
<tr><th class="l">件名</th><td class="l">
  <input type="text" name="subject" value="<?= $e('subject') ?>" style="width:30em">
  <div style="font-size:12.5px;color:var(--muted)">
    日本語は自動的に RFC 2047 (<code>=?UTF-8?B?...?=</code>) で符号化します。</div></td></tr>
</tbody>
</table>
</div>

<div class="actions" style="display:flex;gap:12px;margin:22px 0">
  <button type="submit" name="act" value="save">保存する</button>
  <button type="submit" name="act" value="test" class="sec">保存してテスト送信</button>
</div>
</form>

<?php if ($trace !== ''): ?>
<h2>直前のテスト送信のやりとり</h2>
<p style="font-size:13px;color:var(--muted)">
  <code>&gt;&gt;</code> が送信、<code>&lt;&lt;</code> が受信です。
  認証情報は記録していません。</p>
<pre><?= htmlspecialchars($trace) ?></pre>
<?php endif; ?>

<h2>送信記録 (直近 <?= ML_LOG_KEEP ?> 件)</h2>
<?php if (!$logs): ?>
  <div class="msg info">まだ送信の記録はありません。</div>
<?php else: ?>
  <div class="scroll">
  <table>
    <thead><tr><th class="l">日時</th><th class="l">結果</th><th class="l">内容</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($logs, 0, 20) as $l): ?>
      <tr>
        <td class="l mono"><?= htmlspecialchars((string)$l['ts']) ?></td>
        <td class="l"><?= ($l['result'] ?? '') === 'ok'
            ? '<span style="color:var(--ok);font-weight:700">成功</span>'
            : '<span style="color:var(--err-ink);font-weight:700">失敗</span>' ?></td>
        <td class="l" style="white-space:normal">
          <details><summary><?= htmlspecialchars(mb_substr(
            (string)($l['detail'] ?? ''), 0, 90)) ?></summary>
          <pre style="margin:8px 0 0"><?= htmlspecialchars((string)($l['detail'] ?? '')) ?></pre>
          </details></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

<h2>ファイルで設定する場合</h2>

<p>この画面を使わずファイルを直接置くこともできます。
<strong>ただし <code>www-data</code> が読める必要があります。</strong>
次のコマンドを <code>sudo</code> が使える環境で実行してください
(<code>&lt;...&gt;</code> を実際の値に置き換えます)。</p>

<pre><?= htmlspecialchars(<<<'SH'
# 1) 設定内容を JSON で作る
cat > /tmp/synthlab-mail.json <<'JSON'
{
  "enabled":    true,
  "method":     "smtp",
  "host":       "smtp.example.ac.jp",
  "port":       587,
  "security":   "starttls",
  "auth":       true,
  "user":       "synthlab@example.ac.jp",
  "pass":       "＜アプリパスワード＞",
  "verifypeer": true,
  "timeout":    12,
  "from_mail":  "synthlab@example.ac.jp",
  "from_name":  "SynthLab",
  "to":         "you@example.ac.jp",
  "subject":    "[SynthLab] ご意見が届きました",
  "helo":       ""
}
JSON

# 2) base64 にしてガード付き PHP に包む
{
  echo '<?php'
  echo '// SynthLab mail settings (base64 encoded JSON).'
  echo "if (!defined('SYNTHLAB_DEV')) { http_response_code(404); exit; }"
  echo "return '$(base64 -w0 /tmp/synthlab-mail.json)';"
} | sudo tee /var/www/html/synthlab_dev/config/mail.php > /dev/null

# 3) www-data だけが読めるようにする
sudo chown www-data:www-data /var/www/html/synthlab_dev/config/mail.php
sudo chmod 0600 /var/www/html/synthlab_dev/config/mail.php

# 4) 平文の JSON を消す
shred -u /tmp/synthlab-mail.json
SH
) ?></pre>

<p>設定を読む側は <code>/var/www/html/synthlab/lib/mailer.php</code> の
<code>ml_load()</code> です。項目名は上の JSON のキーと同じで、
足りないキーは既定値が使われます。</p>

<div class="scroll">
<table>
<thead><tr><th class="l">キー</th><th class="l">意味</th><th class="l">既定</th></tr></thead>
<tbody>
<?php foreach ([
  ['enabled', '通知メールを送るか (true/false)', 'false'],
  ['method', '"smtp" または "sendmail"', '"smtp"'],
  ['host', 'SMTP サーバのホスト名', '""'],
  ['port', 'ポート番号', '587'],
  ['security', '"starttls" / "ssl" / "none"', '"starttls"'],
  ['auth', 'SMTP 認証を行うか', 'true'],
  ['user', '認証ユーザ名', '""'],
  ['pass', '認証パスワード（アプリパスワード推奨）', '""'],
  ['verifypeer', 'サーバ証明書を検証するか', 'true'],
  ['timeout', '接続・応答のタイムアウト秒', '12'],
  ['from_mail', '差出人アドレス', '""'],
  ['from_name', '差出人名', '"SynthLab"'],
  ['to', '宛先（カンマ区切りで複数可）', '""'],
  ['subject', '件名', '"[SynthLab] ご意見が届きました"'],
  ['helo', 'HELO/EHLO で名乗る名前（空なら自動）', '""'],
] as [$k, $d, $v]): ?>
  <tr><td class="l mono"><?= htmlspecialchars($k) ?></td>
      <td class="l" style="white-space:normal"><?= htmlspecialchars($d) ?></td>
      <td class="l mono"><?= htmlspecialchars($v) ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<h2>うまくいかないときの見どころ</h2>
<div class="scroll">
<table>
<thead><tr><th class="l">症状</th><th class="l">よくある原因</th></tr></thead>
<tbody>
<tr><td class="l">接続できません</td><td class="l" style="white-space:normal">
  ポート番号の指定違い、または外向き通信が塞がれている。
  このサーバからは 587 番への接続が確認できています</td></tr>
<tr><td class="l">SMTP 認証に失敗 (535 など)</td><td class="l" style="white-space:normal">
  Gmail / Microsoft 365 で通常のパスワードを使っている。
  <strong>アプリパスワード</strong>を発行してください</td></tr>
<tr><td class="l">TLS ハンドシェイク失敗</td><td class="l" style="white-space:normal">
  自己署名証明書。「証明書の検証」を外すと通ります</td></tr>
<tr><td class="l">MAIL FROM が拒否 (550 など)</td><td class="l" style="white-space:normal">
  差出人アドレスが認証ユーザと一致していない</td></tr>
<tr><td class="l">STARTTLS 非対応と言われる</td><td class="l" style="white-space:normal">
  465 番なら「SSL/TLS」を選ぶ。25 番なら「暗号化なし」</td></tr>
<tr><td class="l">成功するが届かない</td><td class="l" style="white-space:normal">
  迷惑メール扱い。差出人ドメインの SPF / DKIM を確認</td></tr>
</tbody>
</table>
</div>

<?php dev_foot(null); ?>
