<?php
// catalog.php -- 検査項目カタログと lnorm3 パラメータ復元
//
// 3パラメータ対数正規分布 (lnorm3) は
//
//     lnorm3(x; mu, sigma, d) = 1/(sqrt(2 pi sigma^2)(x-d))
//                               * exp( -(log(x-d) - mu)^2 / (2 sigma^2) )
//
// で与えられる (生物試料分析 47(4) 式3, MEDINFO 2022)。
// 独立なパラメータが3つなので、基準範囲の両端 (L, U) と中央値 M の
// 「三つの値」から解析的に (mu, sigma, d) を一意に決められる。
// 導出は doc_details/05-algorithm.md を参照。
//
// 注意: ここに収録した基準範囲は公開されている共用基準範囲等に基づく
// 一般的な値であり、論文の付録データセット (Zenodo) そのものではない。
// 中央値は右裾に歪んだ分布として妥当な値を採用した推定値である。

declare(strict_types=1);

/**
 * 三つの値 (下限, 上限, 中央値) から lnorm3 のパラメータを復元する。
 *
 * X = d + exp(mu + sigma Z) と置くと
 *   M = d + e^mu,  L = d + e^{mu - z*sigma},  U = d + e^{mu + z*sigma}
 * より (M-d)^2 = (L-d)(U-d) が成り立ち、d について解けて
 *   d = (L U - M^2) / (L + U - 2M)
 *   mu = log(M - d)
 *   sigma = log((U - d)/(M - d)) / z         (z = 1.959964)
 *
 * @return array{mu:float,sigma:float,d:float}|null 復元できない場合 null
 */
function lnorm3_from_three(float $L, float $U, float $M, float $z = 1.959963985): ?array
{
    if (!($L < $M && $M < $U)) {
        return null;
    }
    $den = $L + $U - 2.0 * $M;
    $degenerate = false;
    if (abs($den) < 1e-12 * max(1.0, abs($L) + abs($U))) {
        // ほぼ対称。lnorm3 は d -> -inf の極限で正規分布に一致するため、
        // 十分遠くに d を置いて正規分布近似とする。
        $d = $M - 20.0 * ($U - $L);
        $degenerate = true;
    } else {
        $d = ($L * $U - $M * $M) / $den;
        if (!($d < $L)) {
            // 左裾に歪んだデータで lnorm3 が当てはまらない場合の退避策。
            // d を L より十分下に置いて正規分布に近い形にする。
            $d = $L - 20.0 * ($U - $L);
            $degenerate = true;
        }
    }
    if ($degenerate) {
        // d を固定した退避経路では、公表値である基準範囲の両端 (L, U) を
        // 厳密に再現するよう mu, sigma を決める。中央値のずれは無視できる。
        $mu = (log($U - $d) + log($L - $d)) / 2.0;
        $sigma = (log($U - $d) - log($L - $d)) / (2.0 * $z);
        if (!is_finite($mu) || !is_finite($sigma) || !($sigma > 0)) {
            return null;
        }
        return ['mu' => $mu, 'sigma' => $sigma, 'd' => $d];
    }
    $mu = log($M - $d);
    $sigma = log(($U - $d) / ($M - $d)) / $z;
    if (!is_finite($mu) || !is_finite($sigma) || !($sigma > 0)) {
        return null;
    }
    return ['mu' => $mu, 'sigma' => $sigma, 'd' => $d];
}

/** lnorm3 の期待値 (生物試料分析 式5)  E[X] = exp(mu + sigma^2/2) + d */
function lnorm3_mean(float $mu, float $sigma, float $d): float
{
    return exp($mu + $sigma * $sigma / 2.0) + $d;
}

/** lnorm3 の分位点  Q(p) = d + exp(mu + sigma * Phi^{-1}(p)) */
function lnorm3_quantile(float $mu, float $sigma, float $d, float $p): float
{
    return $d + exp($mu + $sigma * norm_inv($p));
}

/** 標準正規分位点 (Acklam 近似)。backend の norminv と同一の係数。 */
function norm_inv(float $p): float
{
    $eps = 1.0e-16;
    if ($p <= $eps) {
        $p = $eps;
    }
    if ($p >= 1.0 - $eps) {
        $p = 1.0 - $eps;
    }
    $a = [-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02,
          1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00];
    $b = [-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02,
          6.680131188771972e+01, -1.328068155288572e+01];
    $c = [-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00,
          -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00];
    $dd = [7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00,
           3.754408661907416e+00];
    $plow = 0.02425;
    if ($p < $plow) {
        $q = sqrt(-2.0 * log($p));
        return ((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
             / (((($dd[0] * $q + $dd[1]) * $q + $dd[2]) * $q + $dd[3]) * $q + 1.0);
    }
    if ($p > 1.0 - $plow) {
        $q = sqrt(-2.0 * log(1.0 - $p));
        return -((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
             / (((($dd[0] * $q + $dd[1]) * $q + $dd[2]) * $q + $dd[3]) * $q + 1.0);
    }
    $q = $p - 0.5;
    $r = $q * $q;
    return ((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q
         / ((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1.0);
}

/**
 * 検査項目カタログ。
 * ri => [性別 => [下限, 上限, 中央値]]  ('A' は男女共通)
 * dec => 出力小数桁, group => 表示上のまとまり
 */
function synthlab_catalog(): array
{
    return [
        // ---- 血算 ----
        'WBC'   => ['label' => '白血球数',        'unit' => '10^3/uL', 'dec' => 1, 'group' => '血算',
                    'ri' => ['A' => [3.3, 8.6, 5.4]]],
        'RBC'   => ['label' => '赤血球数',        'unit' => '10^6/uL', 'dec' => 2, 'group' => '血算',
                    'ri' => ['M' => [4.35, 5.55, 4.90], 'F' => [3.86, 4.92, 4.35]]],
        'Hb'    => ['label' => 'ヘモグロビン',    'unit' => 'g/dL',    'dec' => 1, 'group' => '血算',
                    'ri' => ['M' => [13.7, 16.8, 15.1], 'F' => [11.6, 14.8, 13.1]]],
        'Ht'    => ['label' => 'ヘマトクリット',  'unit' => '%',       'dec' => 1, 'group' => '血算',
                    'ri' => ['M' => [40.7, 50.1, 45.2], 'F' => [35.1, 44.4, 39.5]]],
        'MCV'   => ['label' => '平均赤血球容積',  'unit' => 'fL',      'dec' => 1, 'group' => '血算',
                    'ri' => ['A' => [83.6, 98.2, 90.6]]],
        'MCH'   => ['label' => '平均赤血球Hb量',  'unit' => 'pg',      'dec' => 1, 'group' => '血算',
                    'ri' => ['A' => [27.5, 33.2, 30.2]]],
        'MCHC'  => ['label' => '平均赤血球Hb濃度', 'unit' => '%',      'dec' => 1, 'group' => '血算',
                    'ri' => ['A' => [31.7, 35.3, 33.4]]],
        'PLT'   => ['label' => '血小板数',        'unit' => '10^3/uL', 'dec' => 0, 'group' => '血算',
                    'ri' => ['A' => [158.0, 348.0, 240.0]]],

        // ---- 蛋白 ----
        'TP'    => ['label' => '総蛋白',          'unit' => 'g/dL',    'dec' => 1, 'group' => '蛋白',
                    'ri' => ['A' => [6.6, 8.1, 7.3]]],
        'Alb'   => ['label' => 'アルブミン',      'unit' => 'g/dL',    'dec' => 1, 'group' => '蛋白',
                    'ri' => ['A' => [4.1, 5.1, 4.6]]],

        // ---- 肝・胆道 ----
        'AST'   => ['label' => 'AST (GOT)',       'unit' => 'U/L',     'dec' => 0, 'group' => '肝・胆道',
                    'ri' => ['A' => [13.0, 30.0, 20.0]]],
        'ALT'   => ['label' => 'ALT (GPT)',       'unit' => 'U/L',     'dec' => 0, 'group' => '肝・胆道',
                    'ri' => ['M' => [10.0, 42.0, 19.0], 'F' => [7.0, 23.0, 13.0]]],
        'gGTP'  => ['label' => 'γ-GT',            'unit' => 'U/L',     'dec' => 0, 'group' => '肝・胆道',
                    'ri' => ['M' => [13.0, 64.0, 27.0], 'F' => [9.0, 32.0, 16.0]]],
        'ALP'   => ['label' => 'ALP (IFCC)',      'unit' => 'U/L',     'dec' => 0, 'group' => '肝・胆道',
                    'ri' => ['A' => [38.0, 113.0, 66.0]]],
        'LD'    => ['label' => 'LD (LDH)',        'unit' => 'U/L',     'dec' => 0, 'group' => '肝・胆道',
                    'ri' => ['A' => [124.0, 222.0, 168.0]]],
        'T.Bil' => ['label' => '総ビリルビン',    'unit' => 'mg/dL',   'dec' => 2, 'group' => '肝・胆道',
                    'ri' => ['A' => [0.4, 1.5, 0.75]]],
        'D.Bil' => ['label' => '直接ビリルビン',  'unit' => 'mg/dL',   'dec' => 2, 'group' => '肝・胆道',
                    'ri' => ['A' => [0.05, 0.4, 0.16]]],
        'CHE'   => ['label' => 'コリンエステラーゼ', 'unit' => 'U/L',  'dec' => 0, 'group' => '肝・胆道',
                    'ri' => ['M' => [240.0, 486.0, 350.0], 'F' => [201.0, 421.0, 300.0]]],

        // ---- 膵・筋 ----
        'AMY'   => ['label' => 'アミラーゼ',      'unit' => 'U/L',     'dec' => 0, 'group' => '膵・筋',
                    'ri' => ['A' => [44.0, 132.0, 80.0]]],
        'CK'    => ['label' => 'CK (CPK)',        'unit' => 'U/L',     'dec' => 0, 'group' => '膵・筋',
                    'ri' => ['M' => [59.0, 248.0, 120.0], 'F' => [41.0, 153.0, 80.0]]],

        // ---- 腎 ----
        'UN'    => ['label' => '尿素窒素 (BUN)',  'unit' => 'mg/dL',   'dec' => 1, 'group' => '腎',
                    'ri' => ['A' => [8.0, 20.0, 13.2]]],
        'CRE'   => ['label' => 'クレアチニン',    'unit' => 'mg/dL',   'dec' => 2, 'group' => '腎',
                    'ri' => ['M' => [0.65, 1.07, 0.83], 'F' => [0.46, 0.79, 0.60]]],
        'UA'    => ['label' => '尿酸',            'unit' => 'mg/dL',   'dec' => 1, 'group' => '腎',
                    'ri' => ['M' => [3.7, 7.8, 5.6], 'F' => [2.6, 5.5, 3.9]]],
        'eGFR'  => ['label' => 'eGFR',            'unit' => 'mL/min/1.73m^2', 'dec' => 1, 'group' => '腎',
                    'ri' => ['M' => [55.0, 110.0, 78.0], 'F' => [55.0, 112.0, 79.0]]],

        // ---- 電解質 ----
        'Na'    => ['label' => 'ナトリウム',      'unit' => 'mmol/L',  'dec' => 0, 'group' => '電解質',
                    'ri' => ['A' => [138.0, 145.0, 141.4]]],
        'K'     => ['label' => 'カリウム',        'unit' => 'mmol/L',  'dec' => 1, 'group' => '電解質',
                    'ri' => ['A' => [3.6, 4.8, 4.15]]],
        'Cl'    => ['label' => 'クロール',        'unit' => 'mmol/L',  'dec' => 0, 'group' => '電解質',
                    'ri' => ['A' => [101.0, 108.0, 104.4]]],
        'Ca'    => ['label' => 'カルシウム',      'unit' => 'mg/dL',   'dec' => 1, 'group' => '電解質',
                    'ri' => ['A' => [8.8, 10.1, 9.42]]],
        'IP'    => ['label' => '無機リン',        'unit' => 'mg/dL',   'dec' => 1, 'group' => '電解質',
                    'ri' => ['A' => [2.7, 4.6, 3.6]]],

        // ---- 糖代謝 ----
        'Glu'   => ['label' => '血糖 (空腹時)',   'unit' => 'mg/dL',   'dec' => 0, 'group' => '糖代謝',
                    'ri' => ['A' => [73.0, 109.0, 89.0]]],
        'HbA1c' => ['label' => 'HbA1c (NGSP)',    'unit' => '%',       'dec' => 1, 'group' => '糖代謝',
                    'ri' => ['A' => [4.9, 6.0, 5.4]]],

        // ---- 脂質 ----
        'TC'    => ['label' => '総コレステロール', 'unit' => 'mg/dL',  'dec' => 0, 'group' => '脂質',
                    'ri' => ['A' => [142.0, 248.0, 192.0]]],
        'TG'    => ['label' => '中性脂肪',        'unit' => 'mg/dL',   'dec' => 0, 'group' => '脂質',
                    'ri' => ['M' => [40.0, 234.0, 100.0], 'F' => [30.0, 117.0, 65.0]]],
        'HDL'   => ['label' => 'HDL-C',           'unit' => 'mg/dL',   'dec' => 0, 'group' => '脂質',
                    'ri' => ['M' => [38.0, 90.0, 57.0], 'F' => [48.0, 103.0, 70.0]]],
        'LdL-C' => ['label' => 'LDL-C',           'unit' => 'mg/dL',   'dec' => 0, 'group' => '脂質',
                    'ri' => ['A' => [65.0, 163.0, 110.0]]],

        // ---- 炎症 ----
        'CRP'   => ['label' => 'CRP',             'unit' => 'mg/dL',   'dec' => 2, 'group' => '炎症',
                    'ri' => ['A' => [0.001, 0.14, 0.03]]],
    ];
}

/** カタログから (性別を考慮した) 三つの値を取り出す。 */
function catalog_three(array $item, string $sex): array
{
    if (isset($item['ri'][$sex])) {
        return $item['ri'][$sex];
    }
    if (isset($item['ri']['A'])) {
        return $item['ri']['A'];
    }
    // 男女別しかない項目を 'A' 指定で引いた場合は両者の平均を使う
    $m = $item['ri']['M'];
    $f = $item['ri']['F'];
    return [($m[0] + $f[0]) / 2, ($m[1] + $f[1]) / 2, ($m[2] + $f[2]) / 2];
}

/** グループ順の定義 */
function synthlab_groups(): array
{
    return ['血算', '蛋白', '肝・胆道', '膵・筋', '腎', '電解質', '糖代謝', '脂質', '炎症'];
}

/** 既定で選択される項目 */
function synthlab_default_items(): array
{
    return ['WBC', 'RBC', 'Hb', 'Ht', 'PLT', 'TP', 'Alb', 'AST', 'ALT', 'gGTP',
            'LD', 'UN', 'CRE', 'UA', 'eGFR', 'Na', 'K', 'Cl', 'Glu', 'HbA1c',
            'TC', 'TG', 'HDL', 'LdL-C', 'CRP'];
}

/**
 * 偏差値。論文2 の主目的である量。
 *
 *   T = 50 + 10 * (log(x - d) - mu) / sigma
 *
 * 対数スケールに移せば正規分布なので**数値積分が不要**である。
 * 検査値スケールのままでは分布が非対称なため
 * 50 + 10 (x - E[X]) / SD(X) は意味を持たない。
 *
 * mu, sigma, d は「健常の基準分布」のものを渡すこと。
 */
function lnorm3_deviation(float $x, float $mu, float $sigma, float $d): ?float
{
    $t = $x - $d;
    if (!($t > 0) || !($sigma > 0)) {
        return null;
    }
    return 50.0 + 10.0 * (log($t) - $mu) / $sigma;
}

/**
 * 累積確率 (パーセンタイル)。
 *   F(x) = Phi( (log(x - d) - mu) / sigma )
 */
function lnorm3_cdf(float $x, float $mu, float $sigma, float $d): ?float
{
    $t = $x - $d;
    if (!($t > 0) || !($sigma > 0)) {
        return null;
    }
    return norm_cdf((log($t) - $mu) / $sigma);
}

/**
 * 標準正規の累積分布関数。
 *
 * PHP には erfc() が標準では無い (stats 拡張が必要) ため自前で実装する。
 * Zelen & Severo の近似 (Abramowitz & Stegun 26.2.17) を使う。
 * 絶対誤差は 7.5e-8 未満で、パーセンタイル表示には十分。
 * 対称性 Phi(-x) = 1 - Phi(x) を使って右側だけ計算する。
 */
function norm_cdf(float $x): float
{
    if (!is_finite($x)) {
        return $x > 0 ? 1.0 : 0.0;
    }
    $neg = ($x < 0.0);
    $z = abs($x);
    if ($z > 40.0) {
        return $neg ? 0.0 : 1.0;
    }
    $t = 1.0 / (1.0 + 0.2316419 * $z);
    $poly = $t * (0.319381530
          + $t * (-0.356563782
          + $t * (1.781477937
          + $t * (-1.821255978
          + $t * 1.330274429))));
    $phi = exp(-0.5 * $z * $z) / sqrt(2.0 * M_PI);
    $upper = $phi * $poly;            // 約 1 - Phi(z)
    return $neg ? $upper : 1.0 - $upper;
}
