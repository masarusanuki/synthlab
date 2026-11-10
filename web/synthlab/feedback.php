<?php
// feedback.php -- 使用感・ご意見の投稿 (公開・パスワードなし)
declare(strict_types=1);
@ini_set('pcre.jit', '0');
require_once __DIR__ . '/lib/feedback.php';
require_once __DIR__ . '/lib/nav.php';
require_once __DIR__ . '/lib/usagelog.php';

$done = null;
$errmsg = null;
$in = [];
$win = fb_window();
$open = ($win['state'] === 'open');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = $_POST;
    $r = fb_save($_POST);
    if ($r['ok']) {
        $done = $r['msg'];
        $in = [];
        ul_record('fb', [
            'role' => (string)($_POST['role'] ?? ''),
            'use'  => (string)($_POST['use'] ?? ''),
            'rate' => (string)($_POST['rate'] ?? ''),
            'len'  => mb_strlen(trim((string)($_POST['body'] ?? ''))),
            'file' => (string)($r['file'] ?? ''),
        ]);
        // 通知メールを送る。失敗しても投稿は成功扱いにする
        // (投稿自体はすでにファイルへ保存済みなので失われない)。
        try {
            require_once __DIR__ . '/lib/mailer.php';
            if (isset($r['rec']) && is_array($r['rec'])) {
                ml_notify_feedback($r['rec']);
            }
        } catch (Throwable $e) {
            ul_err('feedback-mail', $e->getMessage());
        }
    } else {
        $errmsg = $r['msg'];
        ul_record('fbng', ['msg' => $r['msg']]);
    }
} else {
    ul_view('feedback');
}
$v = fn(string $k): string => htmlspecialchars((string)($in[$k] ?? ''));
$sel = fn(string $k, string $val): string => (($in[$k] ?? '') === $val) ? ' selected' : '';
$chk = fn(string $k, string $val): string => (($in[$k] ?? '') === $val) ? ' checked' : '';
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ご意見・ご感想 — SynthLab</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php sl_header('feedback', 'ご意見・ご感想', '使ってみた感想や改善のご要望をお寄せください'); ?>

<div class="wrap">

<?php if ($done !== null): ?>
  <div class="note" style="border-color:var(--ok)">
    <strong><?= htmlspecialchars($done) ?></strong><br>
    いただいたご意見は開発の参考にさせていただきます。
  </div>
  <div class="actions">
    <a class="btnlink" href="index.php">データ生成に戻る</a>
    <a class="btnlink" href="feedback.php">もう一度送る</a>
  </div>
  <?php sl_footer('feedback'); ?>
  </div></body></html>
<?php exit; endif; ?>

<div class="<?= $open ? 'note' : 'warn' ?>" style="margin-top:0">
  <strong>ご意見の受付は
  <a href="<?= htmlspecialchars(FB_EVENT_URL) ?>" target="_blank" rel="noopener"
    ><?= htmlspecialchars(FB_EVENT_NAME) ?></a>
  の会期中（<?= htmlspecialchars($win['open']) ?>〜<?= htmlspecialchars($win['close']) ?>）
  のみです。</strong><br>
  <?php if ($win['state'] === 'before'): ?>
    まだ受付前です。<?= htmlspecialchars($win['open']) ?> から入力できるようになります。
    （現在 <?= htmlspecialchars($win['now']) ?> 日本時間）
  <?php elseif ($win['state'] === 'after'): ?>
    会期が終了したため、受付を締め切りました。ご協力ありがとうございました。
    （現在 <?= htmlspecialchars($win['now']) ?> 日本時間）
  <?php else: ?>
    会期中です。ぜひ会場での体験もあわせてご意見をお寄せください。
  <?php endif; ?>
  期間外でも、生成ツールと解説はこれまでどおりご利用いただけます。
</div>

<div class="note">
  このツールの使用感、分かりにくかった点、追加してほしい検査項目や疾患、
  出力形式のご要望などをお聞かせください。
  <strong>すべての項目は任意です</strong>（本文のみ必須）。
</div>

<div class="warn">
  <strong>個人情報・患者情報は入力しないでください.</strong>
  実際の患者データや、ご自身を特定できる情報は書き込まないようお願いします。
  連絡先の欄は任意で、返信が必要な場合のみご記入ください。
  投稿内容はサーバ内に保存され、開発関係者のみが閲覧します。
</div>

<?php if ($errmsg !== null): ?>
  <div class="err"><strong>送信できませんでした.</strong> <?= htmlspecialchars($errmsg) ?></div>
<?php endif; ?>

<?php if (!fb_ready()): ?>
  <div class="err"><strong>現在ご意見の受付ができません.</strong>
    保存先が利用できない状態です。管理者にご連絡ください。</div>
<?php endif; ?>

<?php if (!$open): ?>
  <div class="panel" style="text-align:center;padding:26px 18px">
    <p style="margin:0 0 6px;font-size:15px"><strong>現在、投稿フォームは閉じています。</strong></p>
    <p style="margin:0;font-size:13px;color:var(--muted)">
      受付期間: <?= htmlspecialchars($win['open']) ?>〜<?= htmlspecialchars($win['close']) ?>
      （<a href="<?= htmlspecialchars(FB_EVENT_URL) ?>" target="_blank" rel="noopener"
        ><?= htmlspecialchars(FB_EVENT_NAME) ?></a>の会期）
    </p>
    <div class="actions" style="justify-content:center">
      <a class="btnlink" href="index.php">データ生成を試す</a>
      <a class="btnlink" href="about.php">手法の解説を読む</a>
    </div>
  </div>
<?php else: ?>
<form method="post" action="feedback.php">
<div class="panel">
  <h2 style="margin-top:4px">1. 差し支えなければ教えてください（任意）</h2>
  <div class="grid">
    <div>
      <label class="f" for="role">ご職種・立場</label>
      <select id="role" name="role">
        <option value=""<?= $sel('role','') ?>>（未選択）</option>
        <?php foreach (['医師','臨床検査技師','看護師','研究者','学生','技術者・開発者','その他'] as $o): ?>
          <option value="<?= htmlspecialchars($o) ?>"<?= $sel('role',$o) ?>><?= htmlspecialchars($o) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="f" for="use">主な利用目的</label>
      <select id="use" name="use">
        <option value=""<?= $sel('use','') ?>>（未選択）</option>
        <?php foreach (['教育・研修','研究','ソフトウェア開発・動作確認','統計手法の検証','見てみただけ','その他'] as $o): ?>
          <option value="<?= htmlspecialchars($o) ?>"<?= $sel('use',$o) ?>><?= htmlspecialchars($o) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <h3 style="margin-top:18px">全体的な使いやすさ</h3>
  <div style="display:flex;gap:18px;flex-wrap:wrap;font-size:13.5px">
    <?php foreach ([5=>'とても使いやすい',4=>'使いやすい',3=>'ふつう',2=>'使いにくい',1=>'とても使いにくい'] as $n=>$lbl): ?>
      <label class="chk" style="display:inline-block">
        <input type="radio" name="rate" value="<?= $n ?>"<?= $chk('rate',(string)$n) ?>>
        <?= htmlspecialchars($lbl) ?></label>
    <?php endforeach; ?>
  </div>
</div>

<div class="panel">
  <h2 style="margin-top:4px">2. ご意見・ご感想 <span style="color:var(--err-ink)">*必須</span></h2>
  <p style="font-size:13px;color:var(--muted);margin-top:0">
    たとえば次のような点についてお書きいただけると助かります。<br>
    使ってみて分かりにくかったところ / 出力されたデータの現実味 /
    追加してほしい検査項目・疾患 / 欲しい出力形式 / 動作しなかった操作
  </p>
  <textarea name="body" id="body" rows="10" required maxlength="<?= FB_MAX_BODY ?>"
    style="width:100%;padding:10px 12px;font:inherit;font-size:14.5px;background:var(--bg);
           color:var(--ink);border:1px solid var(--line);border-radius:7px;resize:vertical"
    placeholder="例: 疾患プリセットに甲状腺機能低下症を追加してほしい。時系列の間隔を不規則にできると実際の受診に近いと思う。"><?= $v('body') ?></textarea>

  <div style="margin-top:14px">
    <label class="f" for="contact">連絡先（任意・返信が必要な場合のみ）
      <span class="hint">メールアドレスなど。不要な場合は空欄で結構です</span></label>
    <input type="text" id="contact" name="contact" maxlength="200" value="<?= $v('contact') ?>">
  </div>

  <?php /* ハニーポット: 人間には見えない欄。埋まっていたら機械的な投稿と判断する */ ?>
  <div style="position:absolute;left:-9999px" aria-hidden="true">
    <label for="website">Website</label>
    <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
  </div>

  <div class="actions">
    <button type="submit"<?= fb_ready() ? '' : ' disabled' ?>>送信する</button>
    <a href="index.php">やめる</a>
  </div>
</div>
</form>
<?php endif; ?>

<?php sl_footer('feedback'); ?>
</div>
</body>
</html>
