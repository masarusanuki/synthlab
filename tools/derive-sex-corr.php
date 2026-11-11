<?php
// derive-sex-corr.php -- 男女別の相関エッジを基準範囲から導出する
//
// ## 根拠
//
// 論文2 (生物試料分析 47(4):113-121, 2024) の式4 は
//
//   MCV ~ Ht(%) / RBC(x10^4/uL) x 1,000                        (論文2 式4)
//
// という恒等関係を与え、Table 2 で TBA(F)/TBA(M)/JIC(F)/JIC(M) の
// 4 データセットすべてについて約 2% 以内で一致することを確認している。
// RBC を 10^6/uL で測るなら Ht = RBC x MCV / 10 である。
//
// 加えて MCH と MCHC は赤血球指数の定義そのものである
// (論文の式ではなく、検査項目の定義)。
//
//   MCH  = Hb / RBC x 10          MCHC = Hb / Ht x 100
//
// ## 3 つの自由変数への還元
//
// 対数スケール z = log(x - d) では、これらは加法的になる。
//
//   z_Ht   = z_RBC + z_MCV   + c1
//   z_Hb   = z_RBC + z_MCH   + c2
//   z_MCHC = z_MCH - z_MCV   + c3      (= z_Hb - z_Ht)
//
// つまり赤血球系 6 項目は (z_RBC, z_MCV, z_MCH) の 3 変数で決まる。
// 残り 3 項目 (Ht, Hb, MCHC) は独立な量ではない。
//
// ## 相関が基準範囲だけで決まること
//
// 上の 3 式の分散をとると
//
//   sigma_Ht^2   = sR^2 + sV^2 + 2 rho_RV sR sV
//   sigma_Hb^2   = sR^2 + sH^2 + 2 rho_RH sR sH
//   sigma_MCHC^2 = sV^2 + sH^2 - 2 rho_VH sV sH
//
// 未知数 3 つ (rho_RV, rho_RH, rho_VH) に対して式が 3 つなので一意に解ける。
// sigma は基準範囲の 3 値から閉じた解で求まり (論文2)、
// 基準範囲は男女別に公表されている。
// したがって **導かれる相関も男女別になる。自由パラメータはゼロ**。
//
// 基底の相関が決まれば、6 項目すべての組 (15 ペア) は線形写像
//
//   (RBC, Hb, Ht, MCV, MCH, MCHC)^T = A (z_RBC, z_MCV, z_MCH)^T + const
//
// の共分散 A S A^T から一括で決まる。ブロック内で必ず整合するので、
// Higham 射影がブロックを大きく動かすことがない。
//
// なお論文 Fig.4 には男女別の GLASSO グラフが図として載っているが、
// 数値行列は公開されていない。赤血球系以外を男女別にできないのはそのため。
//
// 使い方:
//   php tools/derive-sex-corr.php            確認のみ
//   php tools/derive-sex-corr.php --write    data/correlation.json を更新し
//                                            lib/corr.php に貼る PHP も出力

declare(strict_types=1);

require_once __DIR__ . '/../web/synthlab/lib/catalog.php';
require_once __DIR__ . '/../web/synthlab/lib/corr.php';   // nearest_correlation()

/** 相関が厳密に ±1 になると数値的に扱いにくいので端を丸める */
const DSC_CLAMP = 0.995;

/** 赤血球系 6 項目。順序は出力の並びを決める */
const DSC_ITEMS = ['RBC', 'Hb', 'Ht', 'MCV', 'MCH', 'MCHC'];

/**
 * 基底 (z_RBC, z_MCV, z_MCH) から 6 項目を作る係数行列 A。
 * 行の順は DSC_ITEMS に対応する。
 */
function dsc_matrix_a(): array
{
    return [
        [1.0,  0.0, 0.0],   // RBC  = u_RBC
        [1.0,  0.0, 1.0],   // Hb   = u_RBC + u_MCH
        [1.0,  1.0, 0.0],   // Ht   = u_RBC + u_MCV
        [0.0,  1.0, 0.0],   // MCV  = u_MCV
        [0.0,  0.0, 1.0],   // MCH  = u_MCH
        [0.0, -1.0, 1.0],   // MCHC = u_MCH - u_MCV
    ];
}

/** 指定性別の (mu, sigma, d) を取り出す */
function dsc_params(string $sex): array
{
    $cat = synthlab_catalog();
    $out = [];
    foreach ($cat as $k => $it) {
        [$L, $U, $M] = catalog_three($it, $sex);
        $p = lnorm3_from_three((float)$L, (float)$U, (float)$M);
        if ($p !== null) {
            $out[$k] = $p + ['L' => (float)$L, 'U' => (float)$U, 'M' => (float)$M];
        }
    }
    return $out;
}

/** 値を [-DSC_CLAMP, DSC_CLAMP] に収める */
function dsc_clamp(float $v): float
{
    return max(-DSC_CLAMP, min(DSC_CLAMP, $v));
}

/**
 * 1 性別分の赤血球系 15 ペアを導出する。
 *
 * @return array{edges:array,basis:array,sigma:array,notes:array}|null
 */
function dsc_block(string $sex): ?array
{
    $P = dsc_params($sex);
    foreach (DSC_ITEMS as $k) {
        if (!isset($P[$k])) {
            return null;
        }
    }
    $sR = $P['RBC']['sigma'];
    $sV = $P['MCV']['sigma'];
    $sH = $P['MCH']['sigma'];
    $sHt = $P['Ht']['sigma'];
    $sHb = $P['Hb']['sigma'];
    $sMC = $P['MCHC']['sigma'];

    // --- 3 本の分散式を解いて基底の相関を得る ---
    $rawRV = ($sHt * $sHt - $sR * $sR - $sV * $sV) / (2.0 * $sR * $sV);
    $rawRH = ($sHb * $sHb - $sR * $sR - $sH * $sH) / (2.0 * $sR * $sH);
    $rawVH = ($sV * $sV + $sH * $sH - $sMC * $sMC) / (2.0 * $sV * $sH);

    $rRV = dsc_clamp($rawRV);
    $rRH = dsc_clamp($rawRH);
    $rVH = dsc_clamp($rawVH);

    // 基底の 3x3 相関行列
    $C = [[1.0, $rRV, $rRH], [$rRV, 1.0, $rVH], [$rRH, $rVH, 1.0]];

    // 3 変数が同時に成立するか (相関行列として正定値か) を確認する
    $det = 1.0 + 2.0 * $rRV * $rRH * $rVH
         - $rRV * $rRV - $rRH * $rRH - $rVH * $rVH;

    // 公表された 3 つの sigma が同時に成り立たないことがある。
    // 男性では sigma(Ht) が sigma(RBC) に比べて小さすぎ、
    // rho(RBC,MCV) が -1 に張り付いて行列式が負になる。
    // 基準範囲は項目ごとに別々の集団・別々の報告から来ているので、
    // 互いに整合する保証がそもそもない。
    // ここで諦めず、Higham の最近相関行列へ射影して「最も近い成立する組」を採る。
    // 本体の相関行列と同じ手法・同じ固有値の床を使う。
    $projected = false;
    $shift = 0.0;
    if ($det <= 1e-9) {
        $NC = nearest_correlation($C, 0.02, 300);
        if ($NC !== null) {
            for ($i = 0; $i < 3; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    $shift = max($shift, abs($NC[$i][$j] - $C[$i][$j]));
                }
            }
            $C = $NC;
            $rRV = $C[0][1];
            $rRH = $C[0][2];
            $rVH = $C[1][2];
            $det = 1.0 + 2.0 * $rRV * $rRH * $rVH
                 - $rRV * $rRV - $rRH * $rRH - $rVH * $rVH;
            $projected = true;
        }
    }

    // --- 共分散 S = diag(s) C diag(s) ---
    $s = [$sR, $sV, $sH];
    $S = [];
    for ($i = 0; $i < 3; $i++) {
        for ($j = 0; $j < 3; $j++) {
            $S[$i][$j] = $C[$i][$j] * $s[$i] * $s[$j];
        }
    }

    // --- Cov = A S A^T ---
    $A = dsc_matrix_a();
    $n = count(DSC_ITEMS);
    $AS = [];
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j < 3; $j++) {
            $v = 0.0;
            for ($k = 0; $k < 3; $k++) {
                $v += $A[$i][$k] * $S[$k][$j];
            }
            $AS[$i][$j] = $v;
        }
    }
    $Cov = [];
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j < $n; $j++) {
            $v = 0.0;
            for ($k = 0; $k < 3; $k++) {
                $v += $AS[$i][$k] * $A[$j][$k];
            }
            $Cov[$i][$j] = $v;
        }
    }

    // --- 相関へ正規化し、エッジ一覧にする ---
    $edges = [];
    $sdImplied = [];
    for ($i = 0; $i < $n; $i++) {
        $sdImplied[DSC_ITEMS[$i]] = sqrt($Cov[$i][$i]);
    }
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $r = $Cov[$i][$j] / sqrt($Cov[$i][$i] * $Cov[$j][$j]);
            $edges[] = ['a' => DSC_ITEMS[$i], 'b' => DSC_ITEMS[$j],
                        'r' => round(dsc_clamp($r), 4)];
        }
    }

    // --- 中央値が恒等関係と合っているかの確認 ---
    $notes = [];
    $notes['Ht = RBC x MCV / 10'] =
        ($P['RBC']['M'] * $P['MCV']['M'] / 10.0 - $P['Ht']['M']) / $P['Ht']['M'] * 100.0;
    $notes['Hb = RBC x MCH / 10'] =
        ($P['RBC']['M'] * $P['MCH']['M'] / 10.0 - $P['Hb']['M']) / $P['Hb']['M'] * 100.0;
    $notes['Hb = Ht x MCHC / 100'] =
        ($P['Ht']['M'] * $P['MCHC']['M'] / 100.0 - $P['Hb']['M']) / $P['Hb']['M'] * 100.0;

    return [
        'edges' => $edges,
        'basis' => ['RBC-MCV' => $rRV, 'RBC-MCH' => $rRH, 'MCV-MCH' => $rVH,
                    'raw' => ['RBC-MCV' => $rawRV, 'RBC-MCH' => $rawRH, 'MCV-MCH' => $rawVH],
                    'det' => $det, 'projected' => $projected, 'shift' => $shift],
        'sigma' => ['published' => ['RBC' => $sR, 'MCV' => $sV, 'MCH' => $sH,
                                    'Ht' => $sHt, 'Hb' => $sHb, 'MCHC' => $sMC],
                    'implied' => $sdImplied],
        'notes' => $notes,
    ];
}

// ----------------------------------------------------------------- 実行
$write = in_array('--write', $argv, true);
$all = [];

foreach (['M' => '男性', 'F' => '女性'] as $sex => $label) {
    $b = dsc_block($sex);
    if ($b === null) {
        echo "{$label}: 赤血球系の項目が揃いません\n";
        continue;
    }
    $all[$sex] = $b;

    echo "=== {$label} ({$sex}) ===\n";
    echo "  基準範囲から復元した対数スケールの SD:\n";
    printf("    基底 : RBC %.5f  MCV %.5f  MCH %.5f\n",
        $b['sigma']['published']['RBC'], $b['sigma']['published']['MCV'],
        $b['sigma']['published']['MCH']);
    printf("    従属 : Ht  %.5f  Hb  %.5f  MCHC %.5f  (恒等式から決まる量)\n",
        $b['sigma']['published']['Ht'], $b['sigma']['published']['Hb'],
        $b['sigma']['published']['MCHC']);
    echo "  3 本の分散式を解いた基底の相関:\n";
    foreach (['RBC-MCV', 'RBC-MCH', 'MCV-MCH'] as $k) {
        $raw = $b['basis']['raw'][$k];
        $use = $b['basis'][$k];
        printf("    rho(%-8s) = %+.4f%s\n", $k, $use,
            abs($raw - $use) > 1e-12 ? sprintf('   (生値 %+.4f を丸めた)', $raw) : '');
    }
    printf("    基底 3x3 の行列式 = %+.5f %s\n", $b['basis']['det'],
        $b['basis']['det'] > 0 ? '(正定値)' : '(!! 正定値でない)');
    if ($b['basis']['projected']) {
        printf("    ※ 公表 sigma が同時に成立しないため Higham で最近相関行列へ射影した"
             . " (最大ずれ %.4f)\n", $b['basis']['shift']);
    }
    echo "  中央値が恒等関係とどれだけ合うか:\n";
    foreach ($b['notes'] as $k => $v) {
        printf("    %-24s %+.2f%%\n", $k, $v);
    }
    printf("  → 15 ペアを導出\n\n");
}

if (count($all) === 2) {
    echo "=== 男女の差 ===\n";
    printf("  %-12s %9s %9s %9s\n", 'ペア', '男性', '女性', '差');
    foreach ($all['M']['edges'] as $i => $e) {
        $m = $e['r'];
        $f = $all['F']['edges'][$i]['r'];
        printf("  %-12s %+9.3f %+9.3f %+9.3f%s\n",
            $e['a'] . '-' . $e['b'], $m, $f, $m - $f,
            abs($m - $f) >= 0.30 ? '  ←大きい' : '');
    }
    echo "\n";
}

if (!$write) {
    echo "(--write を付けると data/correlation.json を更新し、\n";
    echo " lib/corr.php に貼る PHP も出力します)\n";
    exit(0);
}

// --- data/correlation.json を更新 ---
$path = __DIR__ . '/../data/correlation.json';
$j = json_decode((string)file_get_contents($path), true);
foreach ($all as $sex => $b) {
    $j['edges_' . $sex] = $b['edges'];
}
$j['_sex_comment'] =
    'edges_M / edges_F は edges を上書きする差分。論文2 式4 の恒等関係 '
  . '(Ht = RBC x MCV / 10) と赤血球指数の定義 (MCH = Hb/RBC, MCHC = Hb/Ht) から、'
  . '男女別の基準範囲だけを入力として tools/derive-sex-corr.php が導出した。'
  . '赤血球系 6 項目は 3 つの自由変数に還元されるため、15 ペアすべてが一意に決まる。'
  . '指定のないペアは edges の値をそのまま使う。';
file_put_contents($path,
    json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo "data/correlation.json を更新しました\n\n";

// --- lib/corr.php に貼る PHP を出力 ---
echo "--- synthlab_sex_edges() の static \$tbl に貼る内容 ---\n";
foreach ($all as $sex => $b) {
    echo "        '{$sex}' => [\n";
    $i = 0;
    foreach ($b['edges'] as $e) {
        if ($i % 3 === 0) {
            echo '           ';
        }
        printf(" ['%s', '%s', %s],", $e['a'], $e['b'], number_format($e['r'], 4, '.', ''));
        $i++;
        if ($i % 3 === 0) {
            echo "\n";
        }
    }
    if ($i % 3 !== 0) {
        echo "\n";
    }
    echo "        ],\n";
}
