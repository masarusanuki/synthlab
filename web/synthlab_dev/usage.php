<?php
// usage.php -- アクセス履歴と生成内容の閲覧 (要ログイン)
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
dev_require_login();
dev_headers();
require_once __DIR__ . '/lib/md.php';
require_once __DIR__ . '/lib/layout.php';
require_once '/var/www/html/synthlab/lib/usagelog.php';
require_once '/var/www/html/synthlab/lib/disease.php';

$days = ul_days();
$sel = (string)($_GET['d'] ?? '');
if ($sel === 'all') {
    $use = array_slice($days, 0, 31);          // 直近31日分まで
} elseif (preg_match('/^\d{8}$/', $sel) && in_array($sel, $days, true)) {
    $use = [$sel];
} else {
    $sel = $days[0] ?? '';
    $use = $sel !== '' ? [$sel] : [];
}
$rows = $use ? ul_read_days($use) : [];

// localhost は書き込み時に除外しているが、除外を入れる前の記録が
// 残っている場合があるので閲覧側でも隠せるようにする。
$showLocal = !empty($_GET['local']);
$nLocal = 0;
if (!$showLocal) {
    $before = count($rows);
    $rows = array_values(array_filter($rows,
        fn($r) => !in_array((string)($r['ip'] ?? ''), UL_SKIP_IPS, true)));
    $nLocal = $before - count($rows);
} else {
    foreach ($rows as $r) {
        if (in_array((string)($r['ip'] ?? ''), UL_SKIP_IPS, true)) { $nLocal++; }
    }
}

// ------------------------------------------------------------ CSV 出力
if (($_GET['act'] ?? '') === 'csv' && $rows) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="synthlab_usage_'
        . ($sel === 'all' ? 'all' : $sel) . '.csv"');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF";
    $cols = ['t', 'ev', 'ip', 'page', 'act', 'mode', 'npat', 'pts', 'sex', 'dis', 'trunc',
             'corr', 'seed', 'sfrac', 'noise', 'intv', 'label', 'nit', 'items', 'rows',
             'ms', 'grp', 'cm', 'role', 'use', 'rate', 'len', 'file', 'where', 'msg',
             'ua', 'ref'];
    echo implode(',', $cols) . "\n";
    foreach (array_reverse($rows) as $r) {
        $out = [];
        foreach ($cols as $c) {
            $v = (string)($r[$c] ?? '');
            if (strpbrk($v, ",\"\n") !== false) {
                $v = '"' . str_replace('"', '""', $v) . '"';
            }
            $out[] = $v;
        }
        echo implode(',', $out) . "\n";
    }
    exit;
}

// ------------------------------------------------------------ 集計
$evLabel = ['view' => 'ページ閲覧', 'gen' => 'データ生成', 'fb' => 'ご意見投稿',
            'fbng' => 'ご意見エラー', 'err' => 'エラー'];
$byEv = [];
$byIp = [];
$byPage = [];
$byDis = [];
$bySex = [];
$byMode = [];
$itemCount = [];
$genRows = 0;
$genMs = 0.0;
$nGen = 0;
$byHour = array_fill(0, 24, 0);
$byDate = [];

foreach ($rows as $r) {
    $ev = (string)($r['ev'] ?? '');
    $byEv[$ev] = ($byEv[$ev] ?? 0) + 1;
    $ip = (string)($r['ip'] ?? '');
    if ($ip !== '') {
        $byIp[$ip] = ($byIp[$ip] ?? 0) + 1;
    }
    $d = substr((string)($r['t'] ?? ''), 0, 10);
    if ($d !== '') {
        $byDate[$d] = ($byDate[$d] ?? 0) + 1;
    }
    $h = (int)substr((string)($r['t'] ?? ''), 11, 2);
    if ($h >= 0 && $h < 24) {
        $byHour[$h]++;
    }
    if ($ev === 'view') {
        $pg = (string)($r['page'] ?? '');
        $byPage[$pg] = ($byPage[$pg] ?? 0) + 1;
    }
    if ($ev === 'gen') {
        $nGen++;
        $genRows += (int)($r['rows'] ?? 0);
        $genMs += (float)($r['ms'] ?? 0);
        $byDis[(string)($r['dis'] ?? '')] = ($byDis[(string)($r['dis'] ?? '')] ?? 0) + 1;
        $bySex[(string)($r['sex'] ?? '')] = ($bySex[(string)($r['sex'] ?? '')] ?? 0) + 1;
        $byMode[(string)($r['mode'] ?? '')] = ($byMode[(string)($r['mode'] ?? '')] ?? 0) + 1;
        foreach (explode(' ', (string)($r['items'] ?? '')) as $it) {
            if ($it !== '') {
                $itemCount[$it] = ($itemCount[$it] ?? 0) + 1;
            }
        }
    }
}
arsort($byIp);
arsort($byPage);
arsort($byDis);
arsort($itemCount);
krsort($byDate);

$disLabels = [];
foreach (synthlab_dis_labels() as $k => $v) {
    $disLabels[$k] = $v;
}

dev_head('アクセス・生成ログ', null, []);
?>
<h1>アクセス履歴と生成内容</h1>

<p>公開ツールのページ閲覧、生成したデータの内容、ご意見の投稿を記録しています。
保存先は <code>synthlab_dev/logs/ul_YYYYMMDD.php</code> で、
1行 = base64 化した JSON の追記式です。
先頭に定数ガードと <code>__halt_compiler()</code> があるため
HTTP で直接叩いても中身は出ません。ファイルは <code>0600</code> で
<code>www-data</code> と root しか読めません。</p>

<?php if (!ul_ready()): ?>
  <div class="msg err">ログディレクトリに書き込めません: <code><?= htmlspecialchars(UL_DIR) ?></code></div>
<?php endif; ?>

<?php if (!$days): ?>
  <div class="msg info">まだ記録はありません。公開ツールを操作すると記録されます。</div>
  <?php dev_foot(null); exit; endif; ?>

<h2>表示する期間</h2>
<p style="font-size:13.5px">
  <?php foreach (array_slice($days, 0, 14) as $d):
    $lbl = substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2);
    $cur = ($sel === $d); ?>
    <a class="navlink<?= $cur ? ' cur' : '' ?>" style="padding:3px 9px;margin:0 4px 6px 0"
       href="usage.php?d=<?= $d ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
  <a class="navlink<?= $sel === 'all' ? ' cur' : '' ?>" style="padding:3px 9px"
     href="usage.php?d=all">全期間 (最大31日)</a>
</p>
<p style="font-size:13px">
  <a class="btnlink" href="usage.php?d=<?= htmlspecialchars($sel) ?>&amp;act=csv<?=
    $showLocal ? '&amp;local=1' : '' ?>">この期間のログを CSV で保存</a>
  <?php if ($nLocal > 0 || $showLocal): ?>
    <a class="navlink<?= $showLocal ? ' cur' : '' ?>" style="padding:3px 9px;margin-left:8px"
       href="usage.php?d=<?= htmlspecialchars($sel) ?><?= $showLocal ? '' : '&amp;local=1' ?>"
      ><?= $showLocal ? 'localhost を隠す' : 'localhost も表示 (' . $nLocal . ' 件)' ?></a>
  <?php endif; ?>
</p>
<p style="font-size:12.5px;color:var(--muted)">
  <?= htmlspecialchars(implode(', ', UL_SKIP_IPS)) ?> からのアクセスは
  <strong>記録しません</strong>（動作確認で埋まるのを避けるため）。
  <?php if ($nLocal > 0 && !$showLocal): ?>
    この除外を入れる前の記録が <?= $nLocal ?> 件あり、いまは隠しています。
  <?php endif; ?>
</p>

<h2>集計 (<?= number_format(count($rows)) ?> 件)</h2>
<div class="scroll">
<table>
<tbody>
<tr><th class="l" style="width:12em">種別</th><td class="l"><?php
  $t = [];
  foreach ($byEv as $k => $c) {
    $t[] = ($evLabel[$k] ?? $k) . ' <strong>' . number_format($c) . '</strong>';
  }
  echo implode(' / ', $t) ?: '—'; ?></td></tr>
<tr><th class="l">日別</th><td class="l"><?php
  $t = [];
  foreach ($byDate as $k => $c) { $t[] = $k . ' ' . number_format($c); }
  echo implode(' / ', array_slice($t, 0, 12)) ?: '—'; ?></td></tr>
<tr><th class="l">接続元 (上位)</th><td class="l"><?php
  $t = [];
  foreach (array_slice($byIp, 0, 10, true) as $k => $c) {
    $t[] = '<span class="mono">' . htmlspecialchars($k) . '</span> ' . number_format($c);
  }
  echo implode(' / ', $t) ?: '—';
  echo ' <span style="color:var(--muted)">(異なる接続元 '
     . number_format(count($byIp)) . ' 件)</span>'; ?></td></tr>
<tr><th class="l">閲覧ページ</th><td class="l"><?php
  $t = [];
  foreach ($byPage as $k => $c) { $t[] = htmlspecialchars($k) . ' ' . number_format($c); }
  echo implode(' / ', $t) ?: '—'; ?></td></tr>
</tbody>
</table>
</div>

<?php if ($nGen > 0): ?>
<h3>生成の内容</h3>
<div class="scroll">
<table>
<tbody>
<tr><th class="l" style="width:12em">生成回数</th><td class="l">
  <strong><?= number_format($nGen) ?></strong> 回 /
  出力行数の合計 <strong><?= number_format($genRows) ?></strong> 行 /
  バックエンド合計 <strong><?= number_format($genMs, 1) ?></strong> ms
  (平均 <?= number_format($genMs / max(1, $nGen), 1) ?> ms)</td></tr>
<tr><th class="l">データ形式</th><td class="l"><?php
  $t = [];
  foreach ($byMode as $k => $c) {
    $t[] = ($k === 'cross' ? '断面' : '時系列') . ' ' . number_format($c);
  }
  echo implode(' / ', $t); ?></td></tr>
<tr><th class="l">性別</th><td class="l"><?php
  $t = [];
  foreach ($bySex as $k => $c) {
    $t[] = (['M' => '男性', 'F' => '女性', 'A' => '男女混合'][$k] ?? $k) . ' ' . number_format($c);
  }
  echo implode(' / ', $t); ?></td></tr>
<tr><th class="l">疾患プリセット</th><td class="l" style="white-space:normal"><?php
  $t = [];
  foreach ($byDis as $k => $c) {
    $t[] = htmlspecialchars($disLabels[$k] ?? $k) . ' <strong>' . number_format($c) . '</strong>';
  }
  echo implode(' / ', $t); ?></td></tr>
<tr><th class="l">よく選ばれた項目</th><td class="l" style="white-space:normal"><?php
  $t = [];
  foreach (array_slice($itemCount, 0, 20, true) as $k => $c) {
    $t[] = htmlspecialchars($k) . ' ' . $c;
  }
  echo implode(' / ', $t) ?: '—'; ?></td></tr>
</tbody>
</table>
</div>
<?php endif; ?>

<h3>時刻分布</h3>
<?php
$mx = max($byHour) ?: 1;
$W = 700; $H = 110; $ml = 30; $mb = 22; $mt = 8;
$pw = $W - $ml - 10; $ph = $H - $mt - $mb;
$bw = $pw / 24;
?>
<div class="scroll">
<svg viewBox="0 0 <?= $W ?> <?= $H ?>" style="display:block;max-width:100%;height:auto"
     role="img" aria-label="時刻別のアクセス数">
  <rect x="<?= $ml ?>" y="<?= $mt ?>" width="<?= $pw ?>" height="<?= $ph ?>"
        fill="none" stroke="var(--line)"/>
  <?php for ($h = 0; $h < 24; $h++):
    $bh = $ph * $byHour[$h] / $mx; ?>
    <rect x="<?= round($ml + $h * $bw + 1, 2) ?>" y="<?= round($mt + $ph - $bh, 2) ?>"
          width="<?= round($bw - 2, 2) ?>" height="<?= round($bh, 2) ?>"
          fill="var(--accent)" opacity="0.75"/>
    <?php if ($h % 3 === 0): ?>
      <text x="<?= round($ml + $h * $bw + $bw / 2, 1) ?>" y="<?= $H - 7 ?>"
            font-size="9.5" fill="var(--muted)" text-anchor="middle"><?= $h ?></text>
    <?php endif; ?>
  <?php endfor; ?>
  <text x="4" y="<?= $mt + 10 ?>" font-size="9.5" fill="var(--muted)"><?= $mx ?></text>
  <text x="4" y="<?= $mt + $ph ?>" font-size="9.5" fill="var(--muted)">0</text>
</svg>
</div>

<h2>明細 (新しい順・最大 400 件)</h2>
<div class="scroll">
<table>
<thead><tr>
  <th class="l">日時</th><th class="l">種別</th><th class="l">接続元</th>
  <th class="l">内容</th>
</tr></thead>
<tbody>
<?php foreach (array_slice($rows, 0, 400) as $r):
  $ev = (string)($r['ev'] ?? ''); ?>
  <tr>
    <td class="l mono" style="white-space:nowrap"><?= htmlspecialchars(
        str_replace('T', ' ', substr((string)($r['t'] ?? ''), 0, 19))) ?></td>
    <td class="l"><?= htmlspecialchars($evLabel[$ev] ?? $ev) ?></td>
    <td class="l mono"><?= htmlspecialchars((string)($r['ip'] ?? '')) ?></td>
    <td class="l" style="white-space:normal">
    <?php if ($ev === 'gen'): ?>
      <?= htmlspecialchars((string)($r['act'] ?? '')) ?> ·
      <?= (string)($r['mode'] ?? '') === 'cross' ? '断面' : '時系列' ?> ·
      <?= number_format((int)($r['npat'] ?? 0)) ?>人<?php
        if ((string)($r['mode'] ?? '') === 'series') {
          echo '×' . (int)($r['pts'] ?? 0) . '回';
        } ?> ·
      <?= (['M' => '男', 'F' => '女', 'A' => '混合'][(string)($r['sex'] ?? '')] ?? '') ?> ·
      <strong><?= htmlspecialchars($disLabels[(string)($r['dis'] ?? '')]
          ?? (string)($r['dis'] ?? '')) ?></strong> ·
      <?= (int)($r['nit'] ?? 0) ?>項目 ·
      seed <span class="mono"><?= htmlspecialchars((string)($r['seed'] ?? '')) ?></span> ·
      <?= number_format((float)($r['ms'] ?? 0), 1) ?>ms
      <?php if ((string)($r['label'] ?? '') !== ''): ?>
        · コホート「<?= htmlspecialchars((string)$r['label']) ?>」<?php endif; ?>
      <details style="margin-top:3px"><summary style="font-size:12px">詳細</summary>
        <div class="mono" style="font-size:11.5px;margin-top:4px">
          trunc=<?= htmlspecialchars((string)($r['trunc'] ?? '')) ?>
          corr=<?= htmlspecialchars((string)($r['corr'] ?? '')) ?>
          sfrac=<?= htmlspecialchars((string)($r['sfrac'] ?? '')) ?>
          noise=<?= htmlspecialchars((string)($r['noise'] ?? '')) ?>
          intv=<?= htmlspecialchars((string)($r['intv'] ?? '')) ?>
          rows=<?= htmlspecialchars((string)($r['rows'] ?? '')) ?>
          grp=<?= htmlspecialchars((string)($r['grp'] ?? '')) ?>
          cm=<?= htmlspecialchars((string)($r['cm'] ?? '')) ?><br>
          items: <?= htmlspecialchars((string)($r['items'] ?? '')) ?>
        </div></details>
    <?php elseif ($ev === 'view'): ?>
      <?= htmlspecialchars((string)($r['page'] ?? '')) ?>
    <?php elseif ($ev === 'fb'): ?>
      投稿 <?= (int)($r['len'] ?? 0) ?> 文字
      <?php if ((string)($r['role'] ?? '') !== ''): ?>
        · <?= htmlspecialchars((string)$r['role']) ?><?php endif; ?>
      <?php if ((string)($r['rate'] ?? '') !== ''): ?>
        · 使いやすさ <?= htmlspecialchars((string)$r['rate']) ?>/5<?php endif; ?>
    <?php elseif ($ev === 'err' || $ev === 'fbng'): ?>
      <span style="color:var(--err-ink)"><?= htmlspecialchars(
        (string)($r['where'] ?? '') . ' ' . (string)($r['msg'] ?? '')) ?></span>
    <?php endif; ?>
      <?php if ((string)($r['ua'] ?? '') !== ''): ?>
        <div style="font-size:11px;color:var(--muted);margin-top:2px">
          <?= htmlspecialchars(mb_substr((string)$r['ua'], 0, 120)) ?></div>
      <?php endif; ?>
    </td>
  </tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php dev_foot(null); ?>
