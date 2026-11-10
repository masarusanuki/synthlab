<?php
// source.php -- ソースコードの閲覧 (要ログイン)
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
dev_require_login();
dev_headers();
require_once __DIR__ . '/lib/md.php';
require_once __DIR__ . '/lib/layout.php';

const SRC_DIR = __DIR__ . '/code';

/** 公開済みソースの目次を読む */
function src_list(): array
{
    $f = SRC_DIR . '/index_list.php';
    if (!is_readable($f)) {
        return [];
    }
    $l = require $f;
    return is_array($l) ? $l : [];
}

/** id からソース本文を読む */
function src_body(string $id): ?string
{
    if (!preg_match('/\A src_[A-Za-z0-9_]{1,120} \z/x', $id)) {
        return null;
    }
    $f = SRC_DIR . '/' . $id . '.php';
    $base = realpath(SRC_DIR);
    $real = realpath($f);
    if ($base === false || $real === false || !str_starts_with($real, $base . '/')) {
        return null;
    }
    if (!is_readable($real)) {
        return null;
    }
    $b64 = require $real;
    if (!is_string($b64)) {
        return null;
    }
    $s = base64_decode($b64, true);
    return $s === false ? null : $s;
}

/**
 * ごく簡単な構文着色。
 * コメントと文字列を先にプレースホルダへ退避してから語句を着色するので、
 * 文字列の中のキーワードが着色される事故が起きない。
 */
function src_highlight(string $code, string $lang): string
{
    $keep = [];
    $put = function (string $cls, string $txt) use (&$keep): string {
        $k = "\x01H" . count($keep) . "\x02";
        $keep[$k] = '<span class="' . $cls . '">' . htmlspecialchars($txt) . '</span>';
        return $k;
    };

    // --- コメントと文字列を退避 ---
    if ($lang === 'python') {
        $code = preg_replace_callback('/"""[\s\S]*?"""|\'\'\'[\s\S]*?\'\'\'/',
            fn($m) => $put('c-str', $m[0]), $code);
        $code = preg_replace_callback('/#[^\n]*/', fn($m) => $put('c-cmt', $m[0]), $code);
        $code = preg_replace_callback('/"(?:[^"\\\\\n]|\\\\.)*"|\'(?:[^\'\\\\\n]|\\\\.)*\'/',
            fn($m) => $put('c-str', $m[0]), $code);
    } elseif ($lang === 'csv' || $lang === 'conf') {
        $code = preg_replace_callback('/#[^\n]*/', fn($m) => $put('c-cmt', $m[0]), $code);
        $code = preg_replace_callback('/"(?:[^"\\\\\n]|\\\\.)*"/',
            fn($m) => $put('c-str', $m[0]), $code);
    } elseif ($lang === 'json') {
        $code = preg_replace_callback('/"(?:[^"\\\\\n]|\\\\.)*"/',
            fn($m) => $put('c-str', $m[0]), $code);
    } else {   // cpp / php / java
        $code = preg_replace_callback('#/\*[\s\S]*?\*/#', fn($m) => $put('c-cmt', $m[0]), $code);
        $code = preg_replace_callback('#//[^\n]*#', fn($m) => $put('c-cmt', $m[0]), $code);
        if ($lang === 'php') {
            $code = preg_replace_callback('/(?<![\w\\\\])#[^\n]*/',
                fn($m) => $put('c-cmt', $m[0]), $code);
        }
        $code = preg_replace_callback(
            '/<<<\'(\w+)\'[\s\S]*?^\1;?|"(?:[^"\\\\\n]|\\\\.)*"|\'(?:[^\'\\\\\n]|\\\\.)*\'/m',
            fn($m) => $put('c-str', $m[0]), $code);
    }

    $code = htmlspecialchars((string)$code);

    // --- 語句の着色 ---
    $kw = [
        'cpp' => 'alignas|auto|bool|break|case|catch|char|class|const|constexpr|continue|'
               . 'default|delete|do|double|else|enum|explicit|extern|false|float|for|friend|'
               . 'goto|if|inline|int|long|mutable|namespace|new|noexcept|nullptr|operator|'
               . 'private|protected|public|register|return|short|signed|sizeof|static|struct|'
               . 'switch|template|this|throw|true|try|typedef|typename|union|unsigned|using|'
               . 'virtual|void|volatile|while',
        'python' => 'and|as|assert|async|await|break|class|continue|def|del|elif|else|except|'
                  . 'False|finally|for|from|global|if|import|in|is|lambda|None|nonlocal|not|'
                  . 'or|pass|raise|return|True|try|while|with|yield',
        'php' => 'abstract|and|array|as|break|callable|case|catch|class|clone|const|continue|'
               . 'declare|default|do|echo|else|elseif|empty|enddeclare|endfor|endforeach|'
               . 'endif|endswitch|endwhile|enum|extends|final|finally|fn|for|foreach|function|'
               . 'global|goto|if|implements|include|include_once|instanceof|insteadof|'
               . 'interface|isset|list|match|namespace|new|or|print|private|protected|public|'
               . 'readonly|require|require_once|return|static|switch|throw|trait|try|unset|'
               . 'use|var|while|xor|yield|true|false|null|int|float|string|bool|void|mixed',
        'java' => 'abstract|assert|boolean|break|byte|case|catch|char|class|const|continue|'
                . 'default|do|double|else|enum|extends|final|finally|float|for|goto|if|'
                . 'implements|import|instanceof|int|interface|long|native|new|package|'
                . 'private|protected|public|return|short|static|strictfp|super|switch|'
                . 'synchronized|this|throw|throws|transient|try|void|volatile|while|'
                . 'true|false|null|var|record|String|Math|System',
        'json' => 'true|false|null',
        'conf' => 'true|false',
        'csv' => '',
    ][$lang] ?? '';

    if ($kw !== '') {
        $code = preg_replace('/\b(' . $kw . ')\b/', '<span class="c-kw">$1</span>', (string)$code);
    }
    // 数値
    $code = preg_replace('/\b(\d+\.?\d*(?:[eE][+-]?\d+)?)\b/',
        '<span class="c-num">$1</span>', (string)$code);
    // 前処理指令 (C++)
    if ($lang === 'cpp') {
        $code = preg_replace('/^(\s*#\s*\w+)/m', '<span class="c-pre">$1</span>', (string)$code);
    }
    // PHP 変数
    if ($lang === 'php') {
        $code = preg_replace('/(\$\w+)/', '<span class="c-var">$1</span>', (string)$code);
    }
    // 関数呼び出しらしいもの
    $code = preg_replace('/\b([a-zA-Z_]\w{2,})(\s*\()/',
        '<span class="c-fn">$1</span>$2', (string)$code);

    return strtr((string)$code, $keep);
}

$list = src_list();
$byGroup = [];
foreach ($list as $r) {
    $byGroup[$r['group']][] = $r;
}
$id = (string)($_GET['f'] ?? '');
$cur = null;
foreach ($list as $r) {
    if ($r['id'] === $id) {
        $cur = $r;
        break;
    }
}

// ------------------------------------------------------------ 生ファイル出力
if ($cur !== null && ($_GET['act'] ?? '') === 'raw') {
    $body = src_body($cur['id']);
    if ($body !== null) {
        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'
            . basename($cur['path']) . '"');
        header('X-Content-Type-Options: nosniff');
        echo $body;
        exit;
    }
}

dev_head($cur !== null ? basename($cur['path']) : 'ソースコード', null, []);
?>
<style>
.srcwrap { display: grid; grid-template-columns: 1fr; gap: 0; }
pre.src { counter-reset: ln; margin: 0; max-height: 78vh; overflow: auto;
          font-size: 12.3px; line-height: 1.58; }
pre.src .ln { display: block; }
pre.src .ln::before {
  counter-increment: ln; content: counter(ln);
  display: inline-block; width: 3.6em; margin-right: 1em; text-align: right;
  color: var(--muted); opacity: .6; user-select: none;
  border-right: 1px solid var(--line); padding-right: .6em;
}
pre.src .ln:target, pre.src .ln:hover { background: var(--accent-soft); }
.c-cmt { color: #6a8759; font-style: italic; }
.c-str { color: #b5762f; }
.c-kw  { color: #1f5fa8; font-weight: 600; }
.c-num { color: #8a54c4; }
.c-pre { color: #a3407a; }
.c-var { color: #177f8f; }
.c-fn  { color: var(--ink); font-weight: 600; }
@media (prefers-color-scheme: dark) {
  .c-cmt { color: #7fa06a; }
  .c-str { color: #d9a05b; }
  .c-kw  { color: #6dabe8; }
  .c-num { color: #c39bea; }
  .c-pre { color: #e08ac0; }
  .c-var { color: #57c3cf; }
}
.filelist { font-size: 13.2px; }
.filelist table { font-size: 13px; }
.filelist a.f { font-family: var(--mono); font-weight: 600; }
</style>

<h1>ソースコード</h1>

<p>本ツールと論文実装のソースを閲覧できます。
Apache は <code>/home/sanuki</code> を辿れないため、
<code>tools/publish-code.php</code> が各ファイルを
<strong>base64 化して定数ガード付き PHP に包み</strong>
<code>synthlab_dev/code/</code> へ配置しています。
直接 HTTP で叩くと 404 になります。
原本を編集したら <code>php tools/publish-code.php</code> で再配置してください。</p>

<?php if (!$list): ?>
  <div class="msg err">配置されたソースがありません。
    <code>php tools/publish-code.php</code> を実行してください。</div>
  <?php dev_foot(null); exit; endif; ?>

<div class="filelist">
<?php foreach ($byGroup as $g => $rows): ?>
  <h2><?= htmlspecialchars($g) ?></h2>
  <div class="scroll">
  <table>
    <thead><tr><th class="l">ファイル</th><th>行数</th><th>サイズ</th>
      <th class="l">内容</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr<?= ($cur && $cur['id'] === $r['id'])
            ? ' style="background:var(--accent-soft)"' : '' ?>>
        <td class="l"><a class="f" href="source.php?f=<?= urlencode($r['id']) ?>"
          ><?= htmlspecialchars($r['path']) ?></a></td>
        <td><?= number_format((int)$r['lines']) ?></td>
        <td><?= number_format((int)$r['bytes']) ?></td>
        <td class="l" style="white-space:normal"><?= htmlspecialchars($r['note']) ?></td>
        <td><a href="source.php?f=<?= urlencode($r['id']) ?>&amp;act=raw">生</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endforeach; ?>
</div>

<?php if ($cur !== null):
  $body = src_body($cur['id']);
  if ($body === null): ?>
    <div class="msg err">ファイルを読めませんでした。</div>
  <?php else:
    $hl = src_highlight($body, (string)$cur['lang']);
    $lines = explode("\n", $hl);
  ?>
  <h2 style="margin-top:34px"><?= htmlspecialchars($cur['path']) ?></h2>
  <p style="font-size:13px;color:var(--muted);margin-top:0">
    <?= htmlspecialchars($cur['note']) ?><br>
    <?= number_format((int)$cur['lines']) ?> 行 /
    <?= number_format((int)$cur['bytes']) ?> バイト /
    最終更新 <?= htmlspecialchars(str_replace('T', ' ',
      substr((string)$cur['mtime'], 0, 19))) ?> ·
    <a href="source.php?f=<?= urlencode($cur['id']) ?>&amp;act=raw">生ファイルを保存</a>
  </p>
  <div class="panel" style="padding:12px 8px">
  <pre class="src"><?php
    foreach ($lines as $i => $l) {
        // 行番号は CSS カウンタで振る。空行でも高さを保つため &nbsp; を入れる
        echo '<span class="ln" id="L' . ($i + 1) . '">'
           . ($l === '' ? '&nbsp;' : $l) . "</span>\n";
    }
  ?></pre>
  </div>
  <?php endif; ?>
<?php else: ?>
  <div class="msg info" style="margin-top:24px">
    上の一覧からファイルを選ぶと内容が表示されます。
  </div>
<?php endif; ?>

<?php dev_foot(null); ?>
