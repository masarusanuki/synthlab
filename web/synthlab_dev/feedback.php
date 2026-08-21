<?php
// feedback.php -- 公開ツールに寄せられたご意見の閲覧 (要ログイン)
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
dev_require_login();
dev_headers();
require_once __DIR__ . '/lib/md.php';
require_once __DIR__ . '/lib/layout.php';
require_once '/var/www/html/synthlab/lib/feedback.php';

$all = fb_load_all();

// ------------------------------------------------------------ CSV 出力
if (($_GET['act'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="synthlab_feedback.csv"');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF";
    $cols = ['ts', 'role', 'use', 'rate', 'body', 'contact', 'ua', 'iph', 'file'];
    echo implode(',', $cols) . "\n";
    foreach ($all as $r) {
        $out = [];
        foreach ($cols as $c) {
            $v = (string)($r[$c] ?? '');
            if (strpbrk($v, ",\"\n\r") !== false) {
                $v = '"' . str_replace('"', '""', $v) . '"';
            }
            $out[] = $v;
        }
        echo implode(',', $out) . "\n";
    }
    exit;
}

// 集計
$byRate = [];
$byRole = [];
$byUse = [];
foreach ($all as $r) {
    if ($r['rate'] !== null) { $byRate[(int)$r['rate']] = ($byRate[(int)$r['rate']] ?? 0) + 1; }
    if (($r['role'] ?? '') !== '') { $byRole[$r['role']] = ($byRole[$r['role']] ?? 0) + 1; }
    if (($r['use'] ?? '') !== '') { $byUse[$r['use']] = ($byUse[$r['use']] ?? 0) + 1; }
}
$rated = array_sum($byRate);
$avg = 0.0;
foreach ($byRate as $k => $c) { $avg += $k * $c; }
$avg = $rated ? $avg / $rated : 0.0;

dev_head('ご意見一覧', null, []);
?>
<h1>公開ツールへのご意見</h1>

<p>公開ツール (<code>/synthlab/feedback.php</code>) から寄せられた投稿です。
保存先は <code>synthlab_dev/feedback/</code>。各投稿は base64 化した JSON を
定数ガード付きの PHP ファイルに包んで置いてあるため、
HTTP で直接叩いても 404 になります。</p>

<?php if (!$all): ?>
  <div class="msg info">まだ投稿はありません。</div>
<?php else: ?>

<h2>集計 (<?= count($all) ?> 件)</h2>
<div class="scroll">
<table>
  <thead><tr><th class="l">項目</th><th class="l">内訳</th></tr></thead>
  <tbody>
    <tr><td class="l">使いやすさ</td><td class="l">
      <?php if ($rated): ?>
        平均 <strong><?= number_format($avg, 2) ?></strong> / 5 (<?= $rated ?> 件)
        &nbsp;
        <?php for ($i = 5; $i >= 1; $i--): ?>
          <?= $i ?>:<?= (int)($byRate[$i] ?? 0) ?>&nbsp;
        <?php endfor; ?>
      <?php else: ?>—<?php endif; ?>
    </td></tr>
    <tr><td class="l">職種・立場</td><td class="l"><?php
      if ($byRole) { arsort($byRole);
        $t = []; foreach ($byRole as $k => $c) { $t[] = htmlspecialchars($k) . ' ' . $c; }
        echo implode(' / ', $t);
      } else { echo '—'; } ?></td></tr>
    <tr><td class="l">利用目的</td><td class="l"><?php
      if ($byUse) { arsort($byUse);
        $t = []; foreach ($byUse as $k => $c) { $t[] = htmlspecialchars($k) . ' ' . $c; }
        echo implode(' / ', $t);
      } else { echo '—'; } ?></td></tr>
  </tbody>
</table>
</div>

<h2>投稿 (新しい順・<?= count($all) ?> 件)</h2>

<p style="font-size:13px;color:var(--muted)">
  本文は投稿されたままの文字列です（表示時にエスケープしています）。
  <a href="feedback.php?act=csv">CSV で保存</a>することもできます。
</p>

<div class="scroll">
<table class="fbtable">
  <thead><tr>
    <th class="l">日時</th>
    <th class="l">職種・立場</th>
    <th class="l">利用目的</th>
    <th>使いやすさ</th>
    <th class="l">ご意見・ご感想</th>
    <th class="l">連絡先</th>
    <th class="l">環境</th>
  </tr></thead>
  <tbody>
  <?php foreach ($all as $r): ?>
    <tr>
      <td class="l mono" style="white-space:nowrap"><?= htmlspecialchars(
          str_replace('T', ' ', substr((string)$r['ts'], 0, 19))) ?>
        <div style="font-size:10.5px;color:var(--muted)"><?= htmlspecialchars(
          (string)$r['file']) ?></div></td>
      <td class="l"><?= ($r['role'] ?? '') !== ''
          ? htmlspecialchars((string)$r['role'])
          : '<span style="color:var(--muted)">—</span>' ?></td>
      <td class="l"><?= ($r['use'] ?? '') !== ''
          ? htmlspecialchars((string)$r['use'])
          : '<span style="color:var(--muted)">—</span>' ?></td>
      <td style="white-space:nowrap"><?php
        if ($r['rate'] !== null) {
            $n = (int)$r['rate'];
            echo '<strong>' . $n . '</strong>/5<br><span style="color:var(--accent)">'
               . str_repeat('★', $n) . '</span><span style="color:var(--line)">'
               . str_repeat('★', 5 - $n) . '</span>';
        } else {
            echo '<span style="color:var(--muted)">—</span>';
        } ?></td>
      <td class="l fbbody"><?= htmlspecialchars((string)$r['body']) ?></td>
      <td class="l"><?= ($r['contact'] ?? '') !== ''
          ? '<span class="mono" style="font-size:11.5px">'
            . htmlspecialchars((string)$r['contact']) . '</span>'
          : '<span style="color:var(--muted)">—</span>' ?></td>
      <td class="l" style="font-size:11px;color:var(--muted);white-space:normal;max-width:16em">
        <?= htmlspecialchars(mb_substr((string)($r['ua'] ?? ''), 0, 110)) ?>
        <?php if (($r['iph'] ?? '') !== ''): ?>
          <div class="mono" style="font-size:10.5px">IP hash <?= htmlspecialchars(
            (string)$r['iph']) ?></div>
        <?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<style>
/* 本文は長くなるので折り返し、改行を保つ */
table.fbtable td.fbbody {
  white-space: pre-wrap;
  min-width: 22em;
  max-width: 40em;
  line-height: 1.65;
}
table.fbtable td { vertical-align: top; }
</style>


<?php endif; ?>

<?php dev_foot(null); ?>
