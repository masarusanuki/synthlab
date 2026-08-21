<?php
// generate.php -- 生成の実行、プレビュー表示、CSV ダウンロード
declare(strict_types=1);

// Apache 側の実行メモリ制限により PCRE の JIT 確保が失敗し警告が出る環境のため、
// JIT を明示的に無効化する (機能は変わらず、エラーログの汚れを防ぐ)。
@ini_set('pcre.jit', '0');
require_once __DIR__ . '/lib/engine.php';
require_once __DIR__ . '/lib/nav.php';
require_once __DIR__ . '/lib/usagelog.php';

$act = $_POST['act'] ?? $_GET['act'] ?? 'preview';
$src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

$err = null;
$cfg = null;
$gen = null;
$warnings = [];

try {
    $cfg = sl_validate($src);
    $gen = sl_generate($cfg, $warnings);
} catch (SlError $e) {
    $err = $e->getMessage();
} catch (Throwable $e) {
    $err = '予期しないエラー: ' . $e->getMessage();
}

// 生成内容を記録する (何を作ったか後から追えるように)
if ($err !== null) {
    ul_err('generate', (string)$err);
} elseif ($cfg !== null && $gen !== null) {
    $genRows = $cfg['npat'] * $cfg['points'];
    ul_gen($act === 'csv' ? 'csv' : 'preview', $cfg, $genRows,
           $gen['elapsed'] * 1000.0,
           ['grp' => count($gen['groups']),
            'cm' => (string)($gen['cinfo']['method'] ?? '')]);
}

// 「設定に戻る」用の URL (条件をすべて引き継ぐ)
$backurl = 'index.php';
if ($cfg !== null) {
    $backurl = 'index.php?' . http_build_query([
        'mode' => $cfg['mode'], 'npat' => $cfg['npat'], 'points' => $cfg['points'],
        'sex' => $cfg['sex'], 'disease' => $cfg['disease'], 'label' => $cfg['label'],
        'trunc' => $cfg['trunc'], 'sfrac' => $cfg['sfrac'], 'noise' => $cfg['noise'],
        'interval' => $cfg['interval'], 'corrscale' => $cfg['corrscale'],
        'seed' => $cfg['seed'], 'writez' => $cfg['writez'] ? '1' : '',
        'batch' => $cfg['batch'] ? '1' : '',
        'items' => $cfg['items'],
    ]);
}

// ---------------------------------------------------------------- CSV 出力
if ($err === null && $act === 'csv') {
    $dis = synthlab_diseases();
    $fname = sprintf('synthlab_%s_%s_%s_%dp_seed%d.csv',
        $cfg['mode'], $cfg['disease'], strtolower($cfg['sex']), $cfg['npat'], $cfg['seed']);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('X-Content-Type-Options: nosniff');

    // Excel が UTF-8 の日本語を正しく開けるよう BOM を付ける
    echo "\xEF\xBB\xBF";
    echo "# SynthLab synthetic laboratory values -- NOT real patient data\n";
    echo "# 架空の数値です。実在の患者データではなく、臨床判断には使用できません。\n";
    echo "# generator: lnorm3 (3-parameter log-normal) + truncated multivariate normal\n";
    echo '# mode=' . $cfg['mode'] . ' npat=' . $cfg['npat']
       . ' points=' . $cfg['points'] . ' sex=' . $cfg['sex']
       . ' disease=' . $cfg['disease'] . ' trunc=' . $cfg['trunc']
       . ' corrscale=' . $cfg['corrscale'] . ' seed=' . $cfg['seed'] . "\n";
    echo '# disease_label=' . str_replace(["\r", "\n", ','], ' ',
            (string)$dis[$cfg['disease']]['label']) . "\n";
    if ($cfg['mode'] === 'series') {
        echo '# sfrac=' . $cfg['sfrac'] . '% noise=' . $cfg['noise']
           . '% interval=' . $cfg['interval'] . "weeks\n";
    }
    if ($cfg['label'] !== '') {
        echo '# cohort=' . str_replace(["\r", "\n"], ' ', $cfg['label']) . "\n";
    }
    // 使用したパラメータを性別グループごとに記録する
    echo "# sex,item,unit,mu,sigma,d,ref_lower,ref_upper\n";
    foreach ($gen['groups'] as $g) {
        foreach ($g['params'] as $k => $p) {
            printf("# %s,%s,%s,%.8f,%.8f,%.8f,%s,%s\n",
                $g['sex'], $k, $p['unit'], $p['mu'], $p['sigma'], $p['d'], $p['L'], $p['U']);
        }
    }

    $attrCols = sl_attr_cols($cfg);
    $head = $attrCols;
    foreach ($cfg['items'] as $k) {
        $head[] = $k;
    }
    if ($cfg['writez']) {
        foreach ($cfg['items'] as $k) {
            $head[] = 'z_' . $k;
        }
    }
    echo implode(',', $head) . "\n";

    // 1行ずつ書き出す (全行を配列に保持しない)
    sl_each_row($cfg, $gen, function (array $attr, array $vals, array $zs)
                use ($attrCols, $cfg) {
        $out = [];
        foreach ($attrCols as $c) {
            $v = (string)($attr[$c] ?? '');
            if (strpbrk($v, ",\"\n") !== false) {
                $v = '"' . str_replace('"', '""', $v) . '"';
            }
            $out[] = $v;
        }
        foreach ($cfg['items'] as $k) {
            $out[] = $vals[$k] ?? '';
        }
        if ($cfg['writez']) {
            foreach ($cfg['items'] as $k) {
                $out[] = $zs[$k] ?? '';
            }
        }
        echo implode(',', $out) . "\n";
    });
    exit;
}

// ---------------------------------------------------------------- 描画補助

/** 数値を見やすく整形する */
function fmt(float $v, int $dec = 2): string
{
    if (!is_finite($v)) {
        return '—';
    }
    return number_format($v, $dec, '.', ',');
}

/** lnorm3 の確率密度 */
function lnorm3_pdf(float $x, array $p): float
{
    $t = $x - $p['d'];
    if ($t <= 0) {
        return 0.0;
    }
    $z = (log($t) - $p['mu']) / $p['sigma'];
    return exp(-0.5 * $z * $z) / (sqrt(2 * M_PI) * $p['sigma'] * $t);
}

/**
 * 1人の値が基準分布のどこにあるかを示す帯。
 * 横軸は偏差値 (T)。基準範囲は T=30.4〜69.6 に対応する。
 */
function svg_position(float $T, array $p): string
{
    $W = 240; $H = 30;
    // T=20〜80 を描画範囲にする (± 3 sigma)
    $lo = 20.0; $hi = 80.0;
    $sx = fn(float $t): float => 4 + ($W - 8) * (min($hi, max($lo, $t)) - $lo) / ($hi - $lo);
    $o = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" style="display:block;width:' . $W
       . 'px;max-width:100%;height:auto" role="img" aria-label="分布内の位置">';
    // 全体の帯
    $o .= '<rect x="4" y="10" width="' . ($W - 8) . '" height="9" rx="4.5"
            fill="var(--line)"/>';
    // 基準範囲 (T = 50 ± 1.96*10)
    $a = $sx(50 - 19.6); $b = $sx(50 + 19.6);
    $o .= '<rect x="' . round($a, 1) . '" y="10" width="' . round($b - $a, 1)
        . '" height="9" fill="var(--ok)" opacity="0.55"/>';
    // 中央 (T=50)
    $o .= '<line x1="' . round($sx(50), 1) . '" y1="7" x2="' . round($sx(50), 1)
        . '" y2="22" stroke="var(--muted)" stroke-width="1"/>';
    // 本人の位置
    $x = $sx($T);
    $out = ($T < 50 - 19.6 || $T > 50 + 19.6);
    $col = $out ? '#d2483c' : 'var(--accent)';
    $o .= '<circle cx="' . round($x, 1) . '" cy="14.5" r="5" fill="' . $col
        . '" stroke="var(--panel)" stroke-width="1.6"/>';
    // 範囲外なら矢印で振り切れを示す
    if ($T > $hi) {
        $o .= '<text x="' . ($W - 3) . '" y="18" font-size="11" text-anchor="end"
                fill="#d2483c">▶</text>';
    } elseif ($T < $lo) {
        $o .= '<text x="3" y="18" font-size="11" fill="#d2483c">◀</text>';
    }
    $o .= '</svg>';
    return $o;
}

/**
 * 個人内変動と集団分布を重ねて描く。
 * 論文 3 の主張「個人に着目すると検査値は分布のごく一部しか動かない」を
 * そのまま図にしたもの。灰色の曲線が集団分布、青い帯がこの人の実際の変動域。
 */
function svg_within(array $vals, array $p, int $dec): string
{
    if (!$vals) {
        return '';
    }
    $vmin = min($vals);
    $vmax = max($vals);
    $vmean = array_sum($vals) / count($vals);

    // 描画範囲は集団分布の 0.1%〜99.9% 点と本人の値を含める
    $q1 = lnorm3_quantile($p['rmu'], $p['rsigma'], $p['rd'], 0.001);
    $q2 = lnorm3_quantile($p['rmu'], $p['rsigma'], $p['rd'], 0.999);
    $lo = min($q1, $vmin);
    $hi = max($q2, $vmax);
    if (!($hi > $lo)) {
        return '';
    }
    $pad = ($hi - $lo) * 0.03;
    $lo -= $pad;
    $hi += $pad;

    $W = 470; $H = 118; $ml = 8; $mr = 8; $mt = 8; $mb = 26;
    $pw = $W - $ml - $mr; $ph = $H - $mt - $mb;
    $sx = fn(float $x): float => $ml + $pw * ($x - $lo) / ($hi - $lo);

    $o = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img"
           aria-label="個人内変動と集団分布">';
    // 集団分布 (基準分布) の密度
    $pts = [];
    $peak = 0.0;
    for ($i = 0; $i <= 200; $i++) {
        $x = $lo + ($hi - $lo) * $i / 200;
        $f = lnorm3_pdf($x, ['mu' => $p['rmu'], 'sigma' => $p['rsigma'], 'd' => $p['rd']]);
        $pts[] = [$x, $f];
        $peak = max($peak, $f);
    }
    if ($peak > 0) {
        $d = 'M' . $ml . ' ' . ($mt + $ph);
        foreach ($pts as $pt) {
            $d .= ' L' . round($sx($pt[0]), 2) . ' '
                . round($mt + $ph - $ph * $pt[1] / $peak, 2);
        }
        $d .= ' L' . ($ml + $pw) . ' ' . ($mt + $ph) . ' Z';
        $o .= '<path d="' . $d . '" fill="var(--muted)" opacity="0.20"/>';
        $o .= '<path d="' . substr($d, 0, strrpos($d, ' L')) . '" fill="none"
                stroke="var(--muted)" stroke-width="1.2" opacity="0.8"/>';
    }
    // 基準範囲の目印
    foreach ([$p['L'], $p['U']] as $vv) {
        if ($vv > $lo && $vv < $hi) {
            $o .= '<line x1="' . round($sx($vv), 1) . '" y1="' . $mt . '" x2="'
                . round($sx($vv), 1) . '" y2="' . ($mt + $ph)
                . '" stroke="var(--ok)" stroke-width="1" stroke-dasharray="3 3"/>';
        }
    }
    // この人の変動域
    $a = $sx($vmin);
    $b = $sx($vmax);
    $o .= '<rect x="' . round($a, 2) . '" y="' . ($mt + 2) . '" width="'
        . max(2.0, round($b - $a, 2)) . '" height="' . ($ph - 4)
        . '" fill="var(--accent)" opacity="0.30"/>';
    $o .= '<line x1="' . round($sx($vmean), 2) . '" y1="' . $mt . '" x2="'
        . round($sx($vmean), 2) . '" y2="' . ($mt + $ph)
        . '" stroke="var(--accent)" stroke-width="2"/>';
    // 軸
    foreach ([0.0, 0.5, 1.0] as $fr) {
        $x = $lo + ($hi - $lo) * $fr;
        $o .= '<text x="' . round($sx($x), 1) . '" y="' . ($H - 12)
            . '" font-size="9.5" fill="var(--muted)" text-anchor="'
            . ($fr == 0 ? 'start' : ($fr == 1 ? 'end' : 'middle')) . '">'
            . htmlspecialchars(fmt($x, $dec)) . '</text>';
    }
    $o .= '<text x="' . round($sx($vmean), 1) . '" y="' . ($H - 2)
        . '" font-size="9.5" fill="var(--accent)" text-anchor="middle">この人</text>';
    $o .= '</svg>';
    return $o;
}

/**
 * ヒストグラムを inline SVG で描く。
 * $sets は [[params, weight], ...]。男女混合のときは混合密度を重ねる。
 */
function svg_hist(array $vals, array $sets, string $name, int $dec): string
{
    if (count($vals) < 2) {
        return '';
    }
    sort($vals);
    $n = count($vals);
    $lo = $vals[0];
    $hi = $vals[$n - 1];
    if (!($hi > $lo)) {
        return '';
    }
    $nb = max(12, min(48, (int)round(sqrt($n))));
    $w = ($hi - $lo) / $nb;
    $bins = array_fill(0, $nb, 0);
    foreach ($vals as $v) {
        $b = (int)floor(($v - $lo) / $w);
        if ($b < 0) { $b = 0; }
        if ($b >= $nb) { $b = $nb - 1; }
        $bins[$b]++;
    }
    $mx = max($bins);
    $W = 470; $H = 150; $ml = 46; $mb = 26; $mt = 8; $mr = 10;
    $pw = $W - $ml - $mr; $ph = $H - $mt - $mb;
    $sx = fn(float $x): float => $ml + $pw * ($x - $lo) / ($hi - $lo);

    $o = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" '
       . 'aria-label="' . htmlspecialchars($name) . ' のヒストグラム">';
    // 基準範囲の帯 (性別で異なる場合は和集合)
    $rl = INF; $ru = -INF;
    foreach ($sets as [$p, $wt]) {
        $rl = min($rl, $p['L']);
        $ru = max($ru, $p['U']);
    }
    $a = max($lo, $rl); $b = min($hi, $ru);
    if ($b > $a) {
        $o .= '<rect x="' . round($sx($a), 2) . '" y="' . $mt . '" width="'
            . round($sx($b) - $sx($a), 2) . '" height="' . $ph
            . '" fill="var(--ok)" opacity="0.10"/>';
    }
    $o .= '<rect x="' . $ml . '" y="' . $mt . '" width="' . $pw . '" height="' . $ph
        . '" fill="none" stroke="var(--line)"/>';
    for ($i = 0; $i < $nb; $i++) {
        $x0 = $sx($lo + $i * $w);
        $x1 = $sx($lo + ($i + 1) * $w);
        $hh = $mx ? $ph * $bins[$i] / $mx : 0;
        $o .= '<rect x="' . round($x0, 2) . '" y="' . round($mt + $ph - $hh, 2)
            . '" width="' . max(0.6, round($x1 - $x0 - 0.7, 2)) . '" height="' . round($hh, 2)
            . '" fill="var(--accent)" opacity="0.72"/>';
    }
    // 理論密度 (混合の場合は重み付き和)
    $pts = [];
    $peak = 0.0;
    for ($i = 0; $i <= 160; $i++) {
        $x = $lo + ($hi - $lo) * $i / 160;
        $f = 0.0;
        foreach ($sets as [$p, $wt]) {
            $f += $wt * lnorm3_pdf($x, $p);
        }
        $pts[] = [$x, $f];
        $peak = max($peak, $f);
    }
    if ($peak > 0) {
        $d = '';
        foreach ($pts as $i => $pt) {
            $d .= ($i ? ' L' : 'M') . round($sx($pt[0]), 2) . ' '
                . round($mt + $ph - $ph * $pt[1] / $peak, 2);
        }
        $o .= '<path d="' . $d . '" fill="none" stroke="#d2483c" stroke-width="1.6"/>';
    }
    foreach ([0.0, 0.5, 1.0] as $fr) {
        $x = $lo + ($hi - $lo) * $fr;
        $o .= '<text x="' . round($sx($x), 1) . '" y="' . ($H - 8)
            . '" font-size="10" fill="var(--muted)" text-anchor="'
            . ($fr == 0 ? 'start' : ($fr == 1 ? 'end' : 'middle')) . '">'
            . htmlspecialchars(fmt($x, $dec)) . '</text>';
    }
    $o .= '<text x="4" y="' . ($mt + 10) . '" font-size="10" fill="var(--muted)">' . $mx . '</text>';
    $o .= '<text x="4" y="' . ($mt + $ph) . '" font-size="10" fill="var(--muted)">0</text>';
    $o .= '</svg>';
    return $o;
}

/**
 * 論文 (MEDINFO 2025 Fig.1) と同じ形式の波形を inline SVG で描く。
 * 横軸 = 初回検査日からの週数、縦軸 = 検査値そのもの (実スケール)。
 *
 * @param array $main   主対象患者 [['week'=>float,'v'=>float], ...]
 * @param array $ghosts 比較用の他患者 (薄く重ねる) の配列の配列
 */
function svg_wave(array $main, array $ghosts, array $p, string $key): string
{
    if (count($main) < 2) {
        return '';
    }
    $vals = array_column($main, 'v');
    foreach ($ghosts as $g) {
        foreach ($g as $r) { $vals[] = $r['v']; }
    }
    $vals[] = $p['L'];
    $vals[] = $p['U'];
    $ylo = min($vals);
    $yhi = max($vals);
    if (!($yhi > $ylo)) { $yhi = $ylo + max(1e-9, abs($ylo) * 0.05 + 1e-9); }
    $pad = ($yhi - $ylo) * 0.08;
    $ylo -= $pad;
    $yhi += $pad;

    $weeks = array_column($main, 'week');
    $x0 = min($weeks);
    $x1 = max($weeks);
    if (!($x1 > $x0)) { $x1 = $x0 + 1; }

    $W = 470; $H = 170; $ml = 58; $mr = 10; $mt = 10; $mb = 28;
    $pw = $W - $ml - $mr; $ph = $H - $mt - $mb;
    $sx = fn(float $x): float => $ml + $pw * ($x - $x0) / ($x1 - $x0);
    $sy = fn(float $v): float => $mt + $ph - $ph * ($v - $ylo) / ($yhi - $ylo);

    $o = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="'
       . htmlspecialchars($key) . ' の時系列">';
    // 基準範囲の帯と破線
    $bt = $sy(min($p['U'], $yhi));
    $bb = $sy(max($p['L'], $ylo));
    if ($bb > $bt) {
        $o .= '<rect x="' . $ml . '" y="' . round($bt, 2) . '" width="' . $pw
            . '" height="' . round($bb - $bt, 2) . '" fill="var(--ok)" opacity="0.11"/>';
        foreach ([$p['L'], $p['U']] as $vv) {
            if ($vv > $ylo && $vv < $yhi) {
                $yy = $sy($vv);
                $o .= '<line x1="' . $ml . '" y1="' . round($yy, 2) . '" x2="' . ($ml + $pw)
                    . '" y2="' . round($yy, 2)
                    . '" stroke="var(--ok)" stroke-width="1" stroke-dasharray="4 3" opacity="0.8"/>';
            }
        }
    }
    $o .= '<rect x="' . $ml . '" y="' . $mt . '" width="' . $pw . '" height="' . $ph
        . '" fill="none" stroke="var(--line)"/>';
    // 他患者 (薄く) — 個人差と個人内変動の違いが見える
    foreach ($ghosts as $g) {
        if (count($g) < 2) { continue; }
        $d = '';
        foreach ($g as $i => $r) {
            $d .= ($i ? ' L' : 'M') . round($sx($r['week']), 2) . ' ' . round($sy($r['v']), 2);
        }
        $o .= '<path d="' . $d . '" fill="none" stroke="var(--muted)" stroke-width="1" opacity="0.32"/>';
    }
    // 主対象
    $d = '';
    foreach ($main as $i => $r) {
        $d .= ($i ? ' L' : 'M') . round($sx($r['week']), 2) . ' ' . round($sy($r['v']), 2);
    }
    $o .= '<path d="' . $d . '" fill="none" stroke="var(--accent)" stroke-width="1.9"/>';
    foreach ($main as $r) {
        $o .= '<circle cx="' . round($sx($r['week']), 2) . '" cy="' . round($sy($r['v']), 2)
            . '" r="2.1" fill="var(--accent)"/>';
    }
    // 縦軸目盛
    $ticks = [$ylo, $yhi];
    if ($p['L'] > $ylo && $p['L'] < $yhi) { $ticks[] = $p['L']; }
    if ($p['U'] > $ylo && $p['U'] < $yhi) { $ticks[] = $p['U']; }
    sort($ticks);
    $lastY = -99.0;
    foreach ($ticks as $tv) {
        $y = $sy($tv);
        if (abs($y - $lastY) < 12) { continue; }
        $lastY = $y;
        $isRef = (abs($tv - $p['L']) < 1e-12 || abs($tv - $p['U']) < 1e-12);
        $o .= '<text x="' . ($ml - 5) . '" y="' . round($y + 3.4, 2)
            . '" font-size="9.5" text-anchor="end" fill="'
            . ($isRef ? 'var(--ok)' : 'var(--muted)') . '">'
            . htmlspecialchars(fmt($tv, $p['dec'])) . '</text>';
    }
    // 横軸目盛 (週)
    foreach ([0.0, 0.5, 1.0] as $fr) {
        $wv = $x0 + ($x1 - $x0) * $fr;
        $o .= '<text x="' . round($sx($wv), 1) . '" y="' . ($H - 8)
            . '" font-size="9.5" fill="var(--muted)" text-anchor="'
            . ($fr == 0 ? 'start' : ($fr == 1 ? 'end' : 'middle')) . '">'
            . fmt($wv, 0) . '</text>';
    }
    $o .= '</svg>';
    return $o;
}

$dis = synthlab_diseases();
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>生成結果 — SynthLab</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php sl_header('index', '生成結果', '擬似血液検査値ジェネレータ',
  '<a class="btnlink" href="' . htmlspecialchars($backurl) . '">← 設定に戻る</a>'); ?>
<div class="wrap">

<?php if ($err !== null): ?>
  <div class="err"><strong>エラー.</strong> <?= htmlspecialchars($err) ?></div>
  <p><a class="btnlink" href="<?= htmlspecialchars($backurl) ?>">← 設定に戻る</a></p>
</div></body></html>
<?php exit; endif; ?>

<div class="warn" style="margin-top:0">
  以下はすべて<strong>架空の数値</strong>です。実在の患者データではなく、臨床判断には使えません。
</div>

<?php if ($warnings): ?>
<div class="warn">
  <strong>設定に関する注意.</strong>
  <ul style="margin:6px 0 0;padding-left:1.4em">
  <?php foreach (array_unique($warnings) as $w): ?>
    <li><?= htmlspecialchars($w) ?></li>
  <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php
// ------------------------------------------------------------------
// 1 パスで走査して、要約統計・プレビュー行・グラフ用データを集める
// ------------------------------------------------------------------
$mode = $cfg['mode'];
$attrCols = sl_attr_cols($cfg);
$items = $cfg['items'];

$wavePid = 'SL000001';
$ghostPids = ['SL000002', 'SL000003', 'SL000004'];
$solo = ($cfg['npat'] === 1);          // 1人詳細モードか
$soloVals = [];                        // item => [値, ...]  (時系列なら複数)
$soloSex = 'M';

$acc = [];
foreach ($items as $k) {
    $acc[$k] = ['n' => 0, 's' => 0.0, 'ss' => 0.0, 'min' => INF, 'max' => -INF,
                'in' => 0, 'vals' => []];
}
$preview = [];
$waveMain = [];
$waveGhost = [];
$wavePidSex = 'M';
$sexCount = ['M' => 0, 'F' => 0];
$seenPid = [];
$rowCount = 0;

sl_each_row($cfg, $gen, function (array $attr, array $vals, array $zs, string $sex, array $params)
    use (&$acc, &$preview, &$waveMain, &$waveGhost, &$wavePidSex, &$sexCount, &$seenPid,
         &$rowCount, &$soloVals, &$soloSex, $items, $attrCols, $mode, $wavePid, $ghostPids) {
    $rowCount++;
    $pid = $attr['patient_id'];
    if (!isset($seenPid[$pid])) {
        $seenPid[$pid] = true;
        $sexCount[$sex] = ($sexCount[$sex] ?? 0) + 1;
    }
    foreach ($items as $k) {
        if (!isset($vals[$k]) || $vals[$k] === '') {
            continue;
        }
        $v = (float)$vals[$k];
        $a =& $acc[$k];
        $a['n']++;
        $a['s'] += $v;
        $a['ss'] += $v * $v;
        $a['min'] = min($a['min'], $v);
        $a['max'] = max($a['max'], $v);
        // 「範囲内」は各行の性別に対応する基準範囲で判定する
        if (isset($params[$k]) && $v >= $params[$k]['L'] && $v <= $params[$k]['U']) {
            $a['in']++;
        }
        if (count($a['vals']) < 40000) {
            $a['vals'][] = $v;
        }
        unset($a);
    }
    if (count($preview) < 25) {
        $row = [];
        foreach ($attrCols as $c) {
            $row[] = (string)($attr[$c] ?? '');
        }
        foreach ($items as $k) {
            $row[] = $vals[$k] ?? '';
        }
        $preview[] = $row;
    }
    if ($pid === $wavePid) {
        $soloSex = $sex;
        foreach ($items as $k) {
            if (isset($vals[$k]) && $vals[$k] !== '') {
                $soloVals[$k][] = (float)$vals[$k];
            }
        }
    }
    if ($mode === 'series') {
        $wk = (float)($attr['week'] ?? 0);
        if ($pid === $wavePid) {
            $wavePidSex = $sex;
            foreach ($items as $k) {
                $waveMain[$k][] = ['week' => $wk, 'v' => (float)($vals[$k] ?? 0)];
            }
        } elseif (in_array($pid, $ghostPids, true)) {
            foreach ($items as $k) {
                $waveGhost[$pid][$k][] = ['week' => $wk, 'v' => (float)($vals[$k] ?? 0)];
            }
        }
    }
});

$gparams = [];
$gweight = [];
$totalPat = max(1, array_sum($sexCount));
foreach ($gen['groups'] as $g) {
    $gparams[$g['sex']] = $g['params'];
    $gweight[$g['sex']] = ($sexCount[$g['sex']] ?? 0) / $totalPat;
}
$sexes = array_keys($gparams);
$primary = $gparams[$sexes[0]];
$isMix = (count($sexes) > 1);

$sum = [];
foreach ($items as $k) {
    $a = $acc[$k];
    if ($a['n'] === 0) { continue; }
    $mean = $a['s'] / $a['n'];
    $var = max(0.0, $a['ss'] / $a['n'] - $mean * $mean);
    sort($a['vals']);
    $m = count($a['vals']);
    $theo = 0.0;
    foreach ($sexes as $sx) {
        $p = $gparams[$sx][$k];
        $theo += $gweight[$sx] * lnorm3_mean($p['mu'], $p['sigma'], $p['d']);
    }
    $sum[$k] = [
        'n' => $a['n'], 'mean' => $mean, 'sd' => sqrt($var),
        'min' => $a['min'], 'max' => $a['max'],
        'median' => $m ? $a['vals'][intdiv($m, 2)] : NAN,
        'inri' => 100.0 * $a['in'] / $a['n'], 'theo' => $theo,
    ];
}
?>

<div class="panel">
  <h2 style="margin-top:4px">生成条件</h2>
  <dl class="kv">
    <dt>データ形式</dt><dd><?= $mode === 'cross' ? '断面データ (1人1行)' : '時系列データ' ?></dd>
    <dt>対象</dt><dd><?= $solo ? '<strong>1人</strong>（個人票つき）'
      : number_format((int)array_sum($sexCount)) . ' 人' ?><?php
      if ($isMix) {
        echo ' (男性 ' . number_format((int)($sexCount['M'] ?? 0))
           . ' / 女性 ' . number_format((int)($sexCount['F'] ?? 0)) . ')';
      }
      if ($mode === 'series') {
        echo ' × ' . $cfg['points'] . ' 回受診 (' . fmt($cfg['interval'], 1) . ' 週間隔)';
      } ?></dd>
    <dt>性別</dt><dd><?= sl_sex_label($cfg['sex']) ?><?php
      if ($isMix) { echo '（男女それぞれの基準範囲で生成した混合コホート）'; } ?></dd>
    <dt>想定疾患</dt><dd><?= htmlspecialchars((string)$dis[$cfg['disease']]['label']) ?>
      <span class="mono" style="color:var(--muted)">(<?= htmlspecialchars($cfg['disease']) ?>)</span></dd>
    <?php if ($cfg['label'] !== ''): ?>
    <dt>コホート名</dt><dd><?= htmlspecialchars($cfg['label']) ?></dd>
    <?php endif; ?>
    <dt>検査項目</dt><dd><?= count($items) ?> 項目</dd>
    <dt>切断</dt><dd><?= ['sd5' => '±5σ', 'ri' => '基準範囲内 (2.5–97.5%)', 'none' => 'なし'][$cfg['trunc']] ?></dd>
    <dt>相関の強さ</dt><dd><?= fmt($cfg['corrscale'], 0) ?> %<?php
      $ci = $gen['cinfo'];
      if (($ci['method'] ?? '') === 'higham') {
        echo ' (正定値化: Higham 最近相関行列へ射影、最大変化 ' . fmt($ci['maxdev'], 3) . ')';
      } elseif (($ci['method'] ?? '') === 'shrinkage') {
        echo ' (正定値化: 単位行列へ ' . fmt($ci['lambda'] * 100, 1) . '% 縮小)';
      } elseif (($ci['method'] ?? '') === 'as-specified') {
        echo ' (指定どおり: 修正なし)';
      } ?></dd>
    <?php if ($mode === 'series'): ?>
    <dt>個人内変動</dt><dd>σ の <?= fmt($cfg['sfrac'], 1) ?> % / ノイズ σ の <?= fmt($cfg['noise'], 1) ?> %</dd>
    <?php endif; ?>
    <dt>乱数種</dt><dd class="mono"><?= $cfg['seed'] ?>
      <span style="color:var(--muted)">(同じ値で再現します)</span></dd>
    <dt>生成時間</dt><dd><?= fmt($gen['elapsed'] * 1000, 1) ?> ms
      (<?= number_format($rowCount) ?> 行<?= $isMix ? ' / 2 グループ' : '' ?>)</dd>
  </dl>

  <form method="post" action="generate.php" style="margin-top:16px">
    <?php
    $hid = [
      'mode' => $cfg['mode'], 'npat' => $cfg['npat'], 'points' => $cfg['points'],
      'sex' => $cfg['sex'], 'disease' => $cfg['disease'], 'label' => $cfg['label'],
      'trunc' => $cfg['trunc'], 'sfrac' => $cfg['sfrac'], 'noise' => $cfg['noise'],
      'interval' => $cfg['interval'], 'corrscale' => $cfg['corrscale'],
      'seed' => $cfg['seed'], 'writez' => $cfg['writez'] ? '1' : '',
      'batch' => $cfg['batch'] ? '1' : '',
    ];
    foreach ($hid as $k => $vv) {
      echo '<input type="hidden" name="' . htmlspecialchars($k) . '" value="'
         . htmlspecialchars((string)$vv) . '">';
    }
    foreach ($items as $k) {
      echo '<input type="hidden" name="items[]" value="' . htmlspecialchars($k) . '">';
    }
    ?>
    <div class="actions" style="margin-top:0">
      <button type="submit" name="act" value="csv">この結果を CSV でダウンロード</button>
      <a class="btnlink" href="<?= htmlspecialchars($backurl) ?>">← 設定に戻る</a>
    </div>
  </form>
</div>

<?php if ($solo): $sp = $gparams[$soloSex]; ?>
<div class="panel">
  <h2 style="margin-top:4px">個人票 — <?= htmlspecialchars($wavePid) ?>
    <span style="font-weight:400;font-size:14px;color:var(--muted)">
      <?= sl_sex_label($soloSex) ?> ·
      <?= htmlspecialchars((string)$dis[$cfg['disease']]['label']) ?><?php
      if ($mode === 'series') {
        echo ' · ' . $cfg['points'] . ' 回受診ぶんの平均';
      } ?></span></h2>

  <p style="font-size:13px;color:var(--muted);margin-top:0">
    <strong>偏差値</strong>は
    <span class="mono">T = 50 + 10 (log(x − d) − μ) / σ</span> で計算しています。
    対数スケールに移せば正規分布なので<strong>数値積分が要りません</strong>
    （これが論文2 の主眼です）。
    基準範囲の下限が T ≈ 30.4、中央値が T = 50、上限が T ≈ 69.6 に対応します。
    <br>
    <strong>μ, σ, d は健常の基準分布のもの</strong>を使っています。
    疾患プリセットでずらした後の分布で計算すると
    「疾患群の中での相対位置」になってしまい、
    「基準集団からどれだけ離れているか」が出ないためです。
  </p>

  <div class="scroll">
  <table>
    <thead><tr>
      <th class="l">項目</th><th class="l">単位</th>
      <th><?= $mode === 'series' ? '平均値' : '検査値' ?></th>
      <th class="l">基準範囲</th>
      <th>偏差値 T</th><th>百分位</th>
      <th class="l">基準分布内の位置</th><th class="l">判定</th>
    </tr></thead>
    <tbody>
    <?php foreach ($items as $k):
      if (empty($soloVals[$k])) { continue; }
      $q = $sp[$k];
      $vv = array_sum($soloVals[$k]) / count($soloVals[$k]);
      $T = lnorm3_deviation($vv, $q['rmu'], $q['rsigma'], $q['rd']);
      $pc = lnorm3_cdf($vv, $q['rmu'], $q['rsigma'], $q['rd']);
      $inri = ($vv >= $q['L'] && $vv <= $q['U']);
      $hi = ($vv > $q['U']);
    ?>
      <tr>
        <td class="l"><strong><?= htmlspecialchars($k) ?></strong>
          <span style="color:var(--muted)"><?= htmlspecialchars($q['label']) ?></span></td>
        <td class="l mono"><?= htmlspecialchars($q['unit']) ?></td>
        <td<?= $inri ? '' : ' class="out"' ?>><strong><?= fmt($vv, $q['dec']) ?></strong></td>
        <td class="l mono"><?= fmt($q['L'], $q['dec']) ?>–<?= fmt($q['U'], $q['dec']) ?></td>
        <td<?= $inri ? '' : ' class="out"' ?>><?= $T !== null ? fmt($T, 1) : '—' ?></td>
        <td><?php
          // 端に寄った値を 100.0% / 0.0% と出すと誤解を招くので不等号にする
          if ($pc === null) { echo '—'; }
          elseif ($pc > 0.9999) { echo '&gt;99.99%'; }
          elseif ($pc > 0.999) { echo fmt($pc * 100, 2) . '%'; }
          elseif ($pc < 0.0001) { echo '&lt;0.01%'; }
          elseif ($pc < 0.001) { echo fmt($pc * 100, 2) . '%'; }
          else { echo fmt($pc * 100, 1) . '%'; }
        ?></td>
        <td class="l"><?= $T !== null ? svg_position($T, $q) : '' ?></td>
        <td class="l"><?= $inri
            ? '<span style="color:var(--ok)">基準範囲内</span>'
            : '<span class="out">' . ($hi ? '高値' : '低値') . '</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <p style="font-size:12.5px;color:var(--muted);margin-bottom:0">
    位置の帯: 緑が基準範囲 (中央95%)、縦線が中央値、丸がこの人の値。
    赤い丸は基準範囲の外にあることを示します。
    帯の左右端は T=20 / T=80（±3σ）で、それを超える場合は矢印で示します。
  </p>
</div>

<?php if ($mode === 'series'): ?>
<div class="panel">
  <h2 style="margin-top:4px">個人内変動と集団分布の対比</h2>
  <p style="font-size:13px;color:var(--muted);margin-top:0">
    論文3 の出発点は「<strong>個人に着目すると、検査値は分布のごく一部しか動かない</strong>」
    という観察です。集団全体から毎回独立に引くと、
    同じ人の中性脂肪が 20 から 400 まで飛び回る非現実的な系列になってしまいます。
    <br>
    灰色が<strong>集団の分布</strong>、青い帯が<strong>この人の <?= $cfg['points'] ?> 回ぶんの
    変動域</strong>、青い縦線がその平均です。緑の破線が基準範囲。
  </p>
  <?php
    // 生成モデルは z_t = z0 + f*L*w_t + noise*sd*xi なので、
    // 個人内 SD は sigma * sqrt(f^2 + noise^2) になる
    $f = $cfg['sfrac'] / 100.0;
    $eta = $cfg['noise'] / 100.0;
    $expRatio = sqrt($f * $f + $eta * $eta);
  ?>
  <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(320px,1fr))">
  <?php foreach ($items as $k):
    if (empty($soloVals[$k]) || count($soloVals[$k]) < 2) { continue; }
    $q = $sp[$k];
    $vs = $soloVals[$k];
    $vmin = min($vs); $vmax = max($vs);
    $n = count($vs);
    $m = array_sum($vs) / $n;
    $sdIn = 0.0;
    foreach ($vs as $x) { $sdIn += ($x - $m) ** 2; }
    $sdIn = $n > 1 ? sqrt($sdIn / ($n - 1)) : 0.0;
    // 集団の SD (基準分布、出力スケール)
    $sdPop = sqrt(max(0.0, (exp($q['rsigma'] ** 2) - 1.0)
             * exp(2 * $q['rmu'] + $q['rsigma'] ** 2)));
    // 比は「対数スケール」で見るのが正しい。f は log(y-d) の世界の
    // パラメータなので、出力スケールの SD 比は
    // その個人が分布のどこにいるかで変わってしまう
    // (歪んだ項目では分布の左側にいる人ほど絶対 SD が小さくなる)。
    $zs = [];
    foreach ($vs as $x) {
        $tt = $x - $q['d'];
        if ($tt > 0) { $zs[] = log($tt); }
    }
    $ratioLog = null;
    if (count($zs) > 1 && $q['sigma'] > 0) {
        $zm = array_sum($zs) / count($zs);
        $zv = 0.0;
        foreach ($zs as $z) { $zv += ($z - $zm) ** 2; }
        $ratioLog = sqrt($zv / (count($zs) - 1)) / $q['sigma'];
    }
  ?>
    <div>
      <h3 style="margin:0 0 2px"><?= htmlspecialchars($k) ?>
        <span style="font-weight:400;color:var(--muted)"><?= htmlspecialchars($q['unit']) ?></span></h3>
      <?= svg_within($vs, $q, (int)$q['dec']) ?>
      <p style="font-size:12px;color:var(--muted);margin:2px 0 0">
        この人: <?= fmt($vmin, $q['dec']) ?>–<?= fmt($vmax, $q['dec']) ?>
        (SD <?= fmt($sdIn, $q['dec'] + 2) ?>) / 集団 SD <?= fmt($sdPop, $q['dec'] + 2) ?><br>
        <strong>対数スケールの比 = <?= $ratioLog !== null ? fmt($ratioLog, 3) : '—' ?></strong>
        <span style="opacity:.8">(期待値 <?= fmt($expRatio, 3) ?>)</span>
      </p>
    </div>
  <?php endforeach; ?>
  </div>
  <p style="font-size:12.5px;color:var(--muted);margin-bottom:0">
    比は<strong>対数スケールで見るのが正しい</strong>ので、そちらを表示しています。
    生成モデルは
    <span class="mono">z_t = z_0 + f·L·w_t + η·σ·ξ</span> なので、
    個人内 SD は <span class="mono">σ·√(f² + η²)</span> になり、
    設定 (f = <?= fmt($cfg['sfrac'], 1) ?> %, η = <?= fmt($cfg['noise'], 1) ?> %) では
    <strong><?= fmt($expRatio, 3) ?></strong> が期待値です。
    実測がこれから前後する主な理由は2つあります。
    (1) <strong>標本ゆらぎ</strong>: 受診 <?= $cfg['points'] ?> 回では
    SD の標準誤差が約 <?= fmt(100 / sqrt(2 * max(1, $cfg['points'] - 1)), 0) ?> % あります。
    (2) <strong>出力の丸め</strong>: 小数桁の粗い項目 (HbA1c など) では
    丸めの量子化が個人内変動と同じくらいの大きさになり、比を押し上げます。
    <br>
    <strong>出力スケールの SD 比は一致しません。</strong>
    f は log(y − d) の世界のパラメータなので、
    歪んだ項目では<strong>その人が分布のどこにいるか</strong>で絶対 SD が変わります。
    分布の左側にいる人ほど小さくなります。
  </p>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($mode === 'series' && $waveMain): ?>
<div class="panel">
  <h2 style="margin-top:4px">波形 (患者 <?= htmlspecialchars($wavePid) ?><?php
    if ($isMix) { echo ' · ' . sl_sex_label($wavePidSex); } ?>)</h2>
  <p style="font-size:13px;color:var(--muted);margin-top:0">
    論文 (MEDINFO 2025 Fig.1) と同じ形式です。<strong>横軸 = 初回検査日からの週数、
    縦軸 = 検査値そのもの</strong>。緑の帯と破線が基準範囲。
    濃い線が対象患者、薄い灰色の線が他の患者
    (<?= htmlspecialchars(implode(', ', $ghostPids)) ?>) です。
    各患者が<strong>自分の水準の周りだけ</strong>を動き、集団全体の分布を渡らないことが
    見て取れます。これが MEDINFO 2025 の狙いです。
  </p>
  <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(320px,1fr))">
  <?php foreach ($items as $k):
      $pp = $gparams[$wavePidSex][$k] ?? $primary[$k];
      $gh = [];
      foreach ($ghostPids as $gp) {
          if (isset($waveGhost[$gp][$k])) { $gh[] = $waveGhost[$gp][$k]; }
      }
  ?>
    <div>
      <h3 style="margin:0 0 2px"><?= htmlspecialchars($k) ?>
        <span style="font-weight:400;color:var(--muted)"><?= htmlspecialchars($pp['label']) ?>
        (<?= htmlspecialchars($pp['unit']) ?>)</span></h3>
      <?= svg_wave($waveMain[$k], $gh, $pp, $k) ?>
    </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="panel">
  <h2 style="margin-top:4px">要約統計</h2>
  <p style="font-size:13px;color:var(--muted);margin-top:0">
    「理論期待値」は <span class="mono">E[X] = exp(μ + σ²/2) + d</span><?php
      if ($isMix) { echo '（男女の重み付き平均）'; } ?>。
    切断や疾患効果を有効にしている場合は標本平均と一致しません。
    「範囲内%」は各患者の性別に対応する基準範囲で判定しています。
  </p>
  <div class="scroll">
  <table>
    <thead><tr>
      <th class="l">項目</th><th class="l">単位</th>
      <th>標本平均</th><th>標本SD</th><th>中央値</th><th>最小</th><th>最大</th>
      <th>理論期待値</th><th>基準範囲</th><th>範囲内%</th>
    </tr></thead>
    <tbody>
    <?php foreach ($items as $k): $s = $sum[$k] ?? null; if (!$s) { continue; }
      $p0 = $primary[$k];
      $ris = [];
      foreach ($sexes as $sx) {
        $q = $gparams[$sx][$k];
        $ris[$sx] = fmt($q['L'], $q['dec']) . '–' . fmt($q['U'], $q['dec']);
      }
      $riTxt = (count(array_unique($ris)) === 1) ? (string)reset($ris)
             : implode(' / ', array_map(fn($sx) => $sx . ' ' . $ris[$sx], $sexes));
    ?>
      <tr>
        <td class="l"><strong><?= htmlspecialchars($k) ?></strong>
          <span style="color:var(--muted)"><?= htmlspecialchars($p0['label']) ?></span></td>
        <td class="l mono"><?= htmlspecialchars($p0['unit']) ?></td>
        <td><?= fmt($s['mean'], $p0['dec'] + 1) ?></td>
        <td><?= fmt($s['sd'], $p0['dec'] + 1) ?></td>
        <td><?= fmt($s['median'], $p0['dec']) ?></td>
        <td><?= fmt($s['min'], $p0['dec']) ?></td>
        <td><?= fmt($s['max'], $p0['dec']) ?></td>
        <td><?= fmt($s['theo'], $p0['dec'] + 1) ?></td>
        <td class="mono"><?= htmlspecialchars($riTxt) ?></td>
        <td<?= $s['inri'] < 50 ? ' class="out"' : '' ?>><?= fmt($s['inri'], 1) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <details style="margin-top:14px">
    <summary>使用した lnorm3 パラメータ<?= $isMix ? ' (性別ごと)' : '' ?></summary>
    <div class="scroll" style="margin-top:10px">
    <table>
      <thead><tr><th class="l">項目</th><?php foreach ($sexes as $sx): ?>
        <th>μ (<?= htmlspecialchars($sx) ?>)</th><th>σ (<?= htmlspecialchars($sx) ?>)</th>
        <th>d (<?= htmlspecialchars($sx) ?>)</th>
      <?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($items as $k): ?>
        <tr><td class="l"><?= htmlspecialchars($k) ?></td>
        <?php foreach ($sexes as $sx): $q = $gparams[$sx][$k]; ?>
          <td class="mono"><?= fmt($q['mu'], 4) ?></td>
          <td class="mono"><?= fmt($q['sigma'], 4) ?></td>
          <td class="mono"><?= fmt($q['d'], 3) ?></td>
        <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </details>
</div>

<?php if ($mode === 'cross'): ?>
<div class="panel">
  <h2 style="margin-top:4px">分布</h2>
  <p style="font-size:13px;color:var(--muted);margin-top:0">
    青が生成された標本のヒストグラム、赤線が理論 lnorm3 の確率密度<?php
      if ($isMix) { echo '（男女の混合密度）'; } ?>(最大値で規格化)、緑の帯が基準範囲<?php
      if ($isMix) { echo '（男女で異なる場合は和集合）'; } ?>。
  </p>
  <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(330px,1fr))">
  <?php foreach ($items as $k):
      $sets = [];
      foreach ($sexes as $sx) { $sets[] = [$gparams[$sx][$k], $gweight[$sx]]; }
  ?>
    <div>
      <h3 style="margin:0 0 2px"><?= htmlspecialchars($k) ?>
        <span style="font-weight:400;color:var(--muted)"><?= htmlspecialchars($primary[$k]['label']) ?>
        (<?= htmlspecialchars($primary[$k]['unit']) ?>)</span></h3>
      <?= svg_hist($acc[$k]['vals'], $sets, $k, (int)$primary[$k]['dec']) ?>
    </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="panel">
  <h2 style="margin-top:4px">データ先頭 <?= count($preview) ?> 行</h2>
  <div class="scroll">
  <table>
    <thead><tr>
      <?php foreach ($attrCols as $c): ?><th class="l"><?= htmlspecialchars($c) ?></th><?php endforeach; ?>
      <?php foreach ($items as $k): ?><th><?= htmlspecialchars($k) ?></th><?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($preview as $r): ?>
      <tr><?php foreach ($r as $ci2 => $c): ?>
        <td class="<?= $ci2 < count($attrCols) ? 'l mono' : '' ?>"><?= htmlspecialchars((string)$c) ?></td>
      <?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<details class="panel">
  <summary>バックエンドの診断情報と spec (再現用)</summary>
  <?php foreach ($gen['groups'] as $g): ?>
    <h3><?= sl_sex_label($g['sex']) ?> グループ (<?= (int)$g['npat'] ?> 人)</h3>
    <pre><?= htmlspecialchars($g['diag']) ?></pre>
    <details><summary>spec</summary><pre><?= htmlspecialchars($g['spec']) ?></pre></details>
  <?php endforeach; ?>
</details>

<?php sl_footer('index'); ?>
</div>
</body>
</html>
