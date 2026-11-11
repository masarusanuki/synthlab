<?php
// corr.php -- 対数スケールにおける検査項目間の相関構造
//
// 生物試料分析 47(4) の研究2 では、lnorm3 を仮定して d を減算し対数をとった
// 値のペアから分散共分散行列を構成し、Graphical LASSO (alpha = 0.04) で
// スパース化して検査項目間の関連を可視化している (同論文 Fig.4)。
// 本ツールは元データを持たないため、同論文および一般的な臨床検査の知見から
// 主要なエッジのみを明示的に与えた「疎な相関行列」を用いる。
// 指定のないペアは 0 (GLASSO で切断された状態に相当) とする。
//
// 手で並べた相関は互いに矛盾しうる (例: 赤血球系は log Ht = log RBC + log MCV
// のような恒等関係に近く、全ペアを独立に指定すると正定値性が壊れる)。
// そこで Higham の交互射影法で「最も近い相関行列」へ射影する。
//
//   R_{k+1} = P_U( P_S( R_k - dS_k ) ),   dS_{k+1} = P_S(...) - (R_k - dS_k)
//     P_S : 固有値を eps 以上にクリップして半正定値へ射影
//     P_U : 対角成分を 1 に戻す
//
// これは Frobenius ノルムの意味で必要最小限の修正しか行わないため、
// 単位行列への一様縮小 R -> (1-lambda)R + lambda I と比べて
// 矛盾していないエッジをほとんど変えない。
// 万一収束しない場合の保険として一様縮小を残してある。

declare(strict_types=1);

/**
 * 明示的に与える相関エッジ。
 * [項目A, 項目B, 相関係数] の並び。対数スケールでの相関である。
 */
function synthlab_edges(): array
{
    return [
        // --- 赤血球系 (MCV ~ Ht/RBC の恒等的関係を反映, 同論文 式4) ---
        ['RBC', 'Hb', 0.90], ['RBC', 'Ht', 0.88], ['Hb', 'Ht', 0.95],
        ['RBC', 'MCV', -0.35], ['Hb', 'MCV', 0.25], ['Ht', 'MCV', 0.05],
        ['MCV', 'MCH', 0.85], ['MCH', 'MCHC', 0.55], ['MCV', 'MCHC', 0.05],
        ['Hb', 'MCH', 0.35], ['RBC', 'MCH', -0.25],
        ['Hb', 'eGFR', 0.25],

        // --- 蛋白 ---
        ['TP', 'Alb', 0.60], ['Alb', 'Hb', 0.30], ['Alb', 'Ca', 0.45],
        ['TP', 'Ca', 0.40], ['Alb', 'CHE', 0.45], ['TP', 'CHE', 0.30],
        ['Alb', 'eGFR', 0.15],

        // --- 肝・胆道 (AST-ALT-γGT の強い正相関は同論文で明示) ---
        ['AST', 'ALT', 0.80], ['AST', 'gGTP', 0.45], ['ALT', 'gGTP', 0.55],
        ['AST', 'LD', 0.55], ['ALT', 'LD', 0.30], ['ALP', 'gGTP', 0.35],
        ['T.Bil', 'D.Bil', 0.80], ['T.Bil', 'AST', 0.20],
        ['AST', 'CK', 0.35], ['LD', 'CK', 0.25],
        ['Alb', 'PLT', 0.25], ['PLT', 'CHE', 0.30],

        // --- 腎 (eGFR と BUN/CRE の負相関は同論文で明示) ---
        ['UN', 'CRE', 0.55], ['CRE', 'eGFR', -0.85], ['UN', 'eGFR', -0.55],
        ['CRE', 'UA', 0.35], ['UA', 'eGFR', -0.30], ['IP', 'eGFR', -0.20],
        ['AMY', 'eGFR', -0.15],

        // --- 電解質 ---
        ['Na', 'Cl', 0.75], ['Na', 'K', -0.10], ['K', 'CRE', 0.25],

        // --- 糖代謝 (HbA1c と随時血糖の強い正相関は同論文で明示) ---
        ['Glu', 'HbA1c', 0.70], ['Glu', 'TG', 0.25], ['HbA1c', 'TG', 0.22],
        ['HbA1c', 'UA', 0.15],

        // --- 脂質 ---
        ['TC', 'LdL-C', 0.88], ['TC', 'HDL', 0.25], ['TC', 'TG', 0.30],
        ['TG', 'HDL', -0.45], ['TG', 'LdL-C', 0.25], ['HDL', 'LdL-C', -0.10],
        ['TG', 'UA', 0.30], ['TG', 'gGTP', 0.30], ['TG', 'CHE', 0.30],
        ['TG', 'ALT', 0.30],

        // --- 炎症 ---
        ['CRP', 'WBC', 0.35], ['CRP', 'Alb', -0.35], ['CRP', 'PLT', 0.20],
        ['WBC', 'PLT', 0.20], ['CRP', 'Hb', -0.20],
    ];
}

/**
 * 男女別に上書きするエッジ。
 *
 * 論文2 (生物試料分析 47(4)) の式4 は
 *
 *   MCV ~ Ht(%) / RBC(x10^4/uL) x 1,000          (論文2 式4)
 *
 * という恒等関係を与え、Table 2 で TBA(F)/TBA(M)/JIC(F)/JIC(M) の
 * 4 データセットすべてについて約 2% 以内で一致することを確認している。
 * RBC を 10^6/uL で測ると Ht = RBC x MCV / 10 である。
 *
 * 対数スケールでは加法的になるので z_Ht = z_RBC + z_MCV + const、
 * したがって分散について
 *
 *   sigma_Ht^2 = sigma_RBC^2 + sigma_MCV^2 + 2 rho sigma_RBC sigma_MCV
 *
 * が成り立たねばならず、rho が sigma だけから決まる。
 * sigma は基準範囲の3値から閉じた解で求まり (同論文)、
 * 基準範囲は男女別に公表されているので、**導かれる相関も男女別になる**。
 *
 * MCH = Hb/RBC と MCHC = Hb/Ht は論文の式ではなく
 * 赤血球指数の定義そのものなので、同じ扱いで使える。
 *
 * 値は tools/derive-sex-corr.php が catalog.php から導出したもので、
 * 手で入れた数値ではない。導出をやり直すには
 *
 *   php tools/derive-sex-corr.php            (確認)
 *   php tools/derive-sex-corr.php --write    (data/correlation.json も更新)
 *
 * 男性では公表 sigma が同時に成立しない (sigma(Ht) が sigma(RBC) に比べて
 * 小さすぎ、基底 3x3 の行列式が負になる)。基準範囲は項目ごとに別々の報告
 * から来ているので整合する保証がそもそもない。本体と同じ Higham の
 * 最近相関行列へ射影して「最も近い成立する組」を採った (最大ずれ 0.0465)。
 *
 * 論文 Fig.4 には男女別の GLASSO グラフが載っているが図のみで、
 * 数値行列は公開されていない。赤血球系以外を男女別にできないのはそのため。
 *
 * @param string $sex 'M' | 'F' (それ以外は上書きなし)
 * @return array<int,array{0:string,1:string,2:float}>
 */
function synthlab_sex_edges(string $sex): array
{
    static $tbl = [
        'M' => [
            ['RBC', 'Hb', 0.8384], ['RBC', 'Ht', 0.9598], ['RBC', 'MCV', -0.9485],
            ['RBC', 'MCH', -0.0495], ['RBC', 'MCHC', 0.6486], ['Hb', 'Ht', 0.9291],
            ['Hb', 'MCV', -0.6548], ['Hb', 'MCH', 0.5030], ['Hb', 'MCHC', 0.9521],
            ['Ht', 'MCV', -0.8213], ['Ht', 'MCH', 0.1805], ['Ht', 'MCHC', 0.7714],
            ['MCV', 'MCH', 0.3042], ['MCV', 'MCHC', -0.4473], ['MCH', 'MCHC', 0.7160],
        ],
        'F' => [
            ['RBC', 'Hb', 0.7247], ['RBC', 'Ht', 0.8506], ['RBC', 'MCV', -0.7256],
            ['RBC', 'MCH', -0.5744], ['RBC', 'MCHC', -0.0076], ['Hb', 'Ht', 0.5522],
            ['Hb', 'MCV', -0.6100], ['Hb', 'MCH', 0.1477], ['Hb', 'MCHC', 0.5909],
            ['Ht', 'MCV', -0.2555], ['Ht', 'MCH', -0.5649], ['Ht', 'MCHC', -0.3462],
            ['MCV', 'MCH', 0.3170], ['MCV', 'MCHC', -0.4391], ['MCH', 'MCHC', 0.7129],
        ],
    ];
    return $tbl[$sex] ?? [];
}

/**
 * 指定性別で実際に使うエッジ一覧を返す。
 *
 * 基本エッジ synthlab_edges() に男女別の上書きを重ねる。
 * 同じペアが両方にあれば男女別のほうを採る。
 *
 * @param string $sex 'M' | 'F' | '' (空なら男女共通のみ)
 */
function synthlab_edges_for(string $sex = ''): array
{
    $out = [];
    foreach (synthlab_edges() as [$a, $b, $r]) {
        $out[corr_pair_key($a, $b)] = [$a, $b, $r];
    }
    foreach (synthlab_sex_edges($sex) as [$a, $b, $r]) {
        $out[corr_pair_key($a, $b)] = [$a, $b, $r];
    }
    return array_values($out);
}

/** 無向ペアを順序に依らない1つのキーにする */
function corr_pair_key(string $a, string $b): string
{
    return ($a <= $b) ? ($a . "\x00" . $b) : ($b . "\x00" . $a);
}

/**
 * 選択された項目に対する相関行列を組み立てる。
 *
 * @param string[] $keys 項目キーの並び
 * @param float    $scale エッジ強度の倍率 (0 で無相関)
 * @param string   $sex   'M' | 'F' | '' (男女別エッジの切り替え)
 * @return array{R:array<int,array<int,float>>,lambda:float}
 */
function build_correlation(array $keys, float $scale = 1.0, string $sex = ''): array
{
    // 同じ項目集合・同じ強度なら結果は決定的なのでファイルにキャッシュする
    // (Jacobi 固有値分解を含むため 36 項目で ~0.9 秒かかる)
    // 男女でエッジが変わるのでキャッシュキーにも性別を含める
    $ck = sha1(implode('|', $keys) . '#' . sprintf('%.6f', $scale)
             . '#' . $sex . '#v4');
    $cf = sys_get_temp_dir() . '/synthlab_corr_' . $ck . '.json';
    if (is_readable($cf)) {
        $j = json_decode((string)file_get_contents($cf), true);
        if (is_array($j) && isset($j['R'], $j['method'])) {
            return $j;
        }
    }
    $res = build_correlation_uncached($keys, $scale, $sex);
    @file_put_contents($cf, json_encode($res), LOCK_EX);
    return $res;
}

/** キャッシュを使わずに相関行列を組み立てる (build_correlation の実体) */
function build_correlation_uncached(array $keys, float $scale = 1.0,
                                    string $sex = ''): array
{
    $n = count($keys);
    $pos = [];
    foreach ($keys as $i => $k) {
        $pos[$k] = $i;
    }
    $R = [];
    for ($i = 0; $i < $n; $i++) {
        $R[$i] = array_fill(0, $n, 0.0);
        $R[$i][$i] = 1.0;
    }
    foreach (synthlab_edges_for($sex) as $e) {
        [$a, $b, $r] = $e;
        if (!isset($pos[$a]) || !isset($pos[$b])) {
            continue;
        }
        $v = $r * $scale;
        if ($v > 0.99) {
            $v = 0.99;
        }
        if ($v < -0.99) {
            $v = -0.99;
        }
        $R[$pos[$a]][$pos[$b]] = $v;
        $R[$pos[$b]][$pos[$a]] = $v;
    }
    // すでに正定値ならそのまま使う
    if (chol_ok($R)) {
        return ['R' => $R, 'lambda' => 0.0, 'method' => 'as-specified',
                'maxdev' => 0.0, 'frob' => 0.0];
    }

    // Higham の交互射影で最近相関行列へ
    // 固有値の床は 0.02。1e-8 だと行列がほぼ特異になり、Gibbs の条件付き
    // 標準偏差が 0.0001 sigma まで潰れて連鎖が動かなくなる (実際に踏んだ)。
    // 0.02 なら条件付き SD 下限が 0.13 sigma 確保され、相関の劣化は 0.01 未満。
    $H = nearest_correlation($R, 0.02, 300);
    if ($H !== null && chol_ok($H)) {
        $maxdev = 0.0;
        $frob = 0.0;
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $dd = abs($H[$i][$j] - $R[$i][$j]);
                $maxdev = max($maxdev, $dd);
                $frob += $dd * $dd;
            }
        }
        return ['R' => $H, 'lambda' => 0.0, 'method' => 'higham',
                'maxdev' => $maxdev, 'frob' => sqrt($frob)];
    }

    // 保険: 正定値になるまで単位行列へ一様縮小する
    $lambda = 0.01;
    for ($t = 0; $t < 60; $t++) {
        $S = shrink_matrix($R, $lambda);
        if (chol_ok($S)) {
            return ['R' => $S, 'lambda' => $lambda, 'method' => 'shrinkage',
                    'maxdev' => $lambda, 'frob' => -1.0];
        }
        $lambda = min(0.99, $lambda * 1.6);
    }
    return ['R' => shrink_matrix($R, 1.0), 'lambda' => 1.0, 'method' => 'identity',
            'maxdev' => 1.0, 'frob' => -1.0];
}

/**
 * Higham (2002) の交互射影法で最も近い相関行列を求める。
 * @return array|null 収束しなければ null
 */
function nearest_correlation(array $A, float $eps = 1e-8, int $iter = 200): ?array
{
    $n = count($A);
    $Y = $A;
    $dS = [];
    for ($i = 0; $i < $n; $i++) {
        $dS[$i] = array_fill(0, $n, 0.0);
    }
    for ($t = 0; $t < $iter; $t++) {
        // R = Y - dS
        $R = [];
        for ($i = 0; $i < $n; $i++) {
            $R[$i] = [];
            for ($j = 0; $j < $n; $j++) {
                $R[$i][$j] = $Y[$i][$j] - $dS[$i][$j];
            }
        }
        // X = P_S(R) : 固有値を eps 以上にクリップ
        $X = psd_clip($R, $eps);
        // dS = X - R
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $dS[$i][$j] = $X[$i][$j] - $R[$i][$j];
            }
        }
        // Y = P_U(X) : 対角を 1 に
        $Y = $X;
        for ($i = 0; $i < $n; $i++) {
            $Y[$i][$i] = 1.0;
        }
        if ($t >= 3 && chol_ok($Y)) {
            // 対角を 1 に戻した後も正定値なら採用する
            return $Y;
        }
    }
    return chol_ok($Y) ? $Y : null;
}

/** 対称行列の固有値を eps 以上にクリップして半正定値へ射影する */
function psd_clip(array $A, float $eps): array
{
    [$vals, $vecs] = jacobi_eigen($A);
    $n = count($A);
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[$i] = array_fill(0, $n, 0.0);
    }
    for ($k = 0; $k < $n; $k++) {
        $lam = max($vals[$k], $eps);
        for ($i = 0; $i < $n; $i++) {
            $vi = $vecs[$i][$k] * $lam;
            if ($vi === 0.0) {
                continue;
            }
            for ($j = $i; $j < $n; $j++) {
                $out[$i][$j] += $vi * $vecs[$j][$k];
            }
        }
    }
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $out[$j][$i] = $out[$i][$j];
        }
    }
    return $out;
}

/**
 * 対称行列の固有値分解 (循環 Jacobi 法)。
 * @return array{0:float[],1:array<int,array<int,float>>} [固有値, 固有ベクトル(列)]
 */
function jacobi_eigen(array $A, int $sweeps = 100): array
{
    $n = count($A);
    $a = [];
    for ($i = 0; $i < $n; $i++) {
        $a[$i] = array_values($A[$i]);
    }
    $v = [];
    for ($i = 0; $i < $n; $i++) {
        $v[$i] = array_fill(0, $n, 0.0);
        $v[$i][$i] = 1.0;
    }
    for ($s = 0; $s < $sweeps; $s++) {
        $off = 0.0;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $off += $a[$i][$j] * $a[$i][$j];
            }
        }
        if ($off < 1e-22) {
            break;
        }
        for ($p = 0; $p < $n - 1; $p++) {
            for ($q = $p + 1; $q < $n; $q++) {
                if (abs($a[$p][$q]) < 1e-18) {
                    continue;
                }
                $theta = ($a[$q][$q] - $a[$p][$p]) / (2.0 * $a[$p][$q]);
                $tt = ($theta >= 0 ? 1.0 : -1.0)
                    / (abs($theta) + sqrt($theta * $theta + 1.0));
                $c = 1.0 / sqrt($tt * $tt + 1.0);
                $sn = $tt * $c;
                for ($k = 0; $k < $n; $k++) {
                    $akp = $a[$k][$p];
                    $akq = $a[$k][$q];
                    $a[$k][$p] = $c * $akp - $sn * $akq;
                    $a[$k][$q] = $sn * $akp + $c * $akq;
                }
                for ($k = 0; $k < $n; $k++) {
                    $apk = $a[$p][$k];
                    $aqk = $a[$q][$k];
                    $a[$p][$k] = $c * $apk - $sn * $aqk;
                    $a[$q][$k] = $sn * $apk + $c * $aqk;
                }
                for ($k = 0; $k < $n; $k++) {
                    $vkp = $v[$k][$p];
                    $vkq = $v[$k][$q];
                    $v[$k][$p] = $c * $vkp - $sn * $vkq;
                    $v[$k][$q] = $sn * $vkp + $c * $vkq;
                }
            }
        }
    }
    $vals = [];
    for ($i = 0; $i < $n; $i++) {
        $vals[$i] = $a[$i][$i];
    }
    return [$vals, $v];
}

/** R(lambda) = (1-lambda) R + lambda I を返す */
function shrink_matrix(array $R, float $lambda): array
{
    $n = count($R);
    $S = [];
    for ($i = 0; $i < $n; $i++) {
        $S[$i] = array_fill(0, $n, 0.0);
        for ($j = 0; $j < $n; $j++) {
            $S[$i][$j] = (1.0 - $lambda) * $R[$i][$j] + ($i === $j ? $lambda : 0.0);
        }
    }
    return $S;
}

/** Cholesky 分解が成功するか (= 正定値か) を判定する */
function chol_ok(array $A): bool
{
    $n = count($A);
    $L = [];
    for ($i = 0; $i < $n; $i++) {
        $L[$i] = array_fill(0, $n, 0.0);
    }
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j <= $i; $j++) {
            $s = $A[$i][$j];
            for ($k = 0; $k < $j; $k++) {
                $s -= $L[$i][$k] * $L[$j][$k];
            }
            if ($i === $j) {
                if (!($s > 1e-12)) {
                    return false;
                }
                $L[$i][$j] = sqrt($s);
            } else {
                $L[$i][$j] = $s / $L[$j][$j];
            }
        }
    }
    return true;
}
