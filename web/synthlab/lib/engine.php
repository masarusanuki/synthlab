<?php
// engine.php -- 入力の検証、spec の組み立て、バックエンド呼び出し

declare(strict_types=1);

require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/corr.php';

const SL_MAX_PATIENTS = 20000;   // 患者数の上限
const SL_MAX_POINTS   = 500;     // 時系列点数の上限
const SL_MAX_CELLS    = 400000;  // 行数 x 項目数 の上限 (公開サーバ保護のため)
const SL_TIMEOUT      = 25;      // バックエンドのタイムアウト (秒)

/** 実行時例外 */
class SlError extends RuntimeException {}

/** バックエンド実行ファイルのパス */
function sl_binary(): string
{
    return __DIR__ . '/../bin/synthgen';
}

/**
 * リクエストを検証して正規化した設定を返す。
 * @throws SlError
 */
function sl_validate(array $in): array
{
    $cat = synthlab_catalog();

    // 既定は時系列。元の研究の主眼が個人の検査値の推移にあるため
    // (論文3 は時系列合成が主題で、論文2 は個人の値の解釈が主題)。
    $mode = ($in['mode'] ?? 'series') === 'cross' ? 'cross' : 'series';

    // 既定は「1人を詳しく見る」。集団生成は明示的に選んだときだけ。
    // 元の研究は個人の検査値をどう解釈するかが主眼なので、
    // 1人を既定にしておくほうが手法の趣旨に合う。
    $batch = !empty($in['batch']);
    $npat = $batch ? (int)($in['npat'] ?? 100) : 1;
    if ($npat < 1) {
        throw new SlError('患者数は 1 以上を指定してください。');
    }
    if ($npat > SL_MAX_PATIENTS) {
        throw new SlError('患者数の上限は ' . SL_MAX_PATIENTS . ' 人です。');
    }

    $points = (int)($in['points'] ?? 12);
    if ($mode === 'series') {
        if ($points < 2) {
            throw new SlError('時系列の点数は 2 以上を指定してください。');
        }
        if ($points > SL_MAX_POINTS) {
            throw new SlError('時系列の点数の上限は ' . SL_MAX_POINTS . ' です。');
        }
    } else {
        $points = 1;
    }

    $sex = $in['sex'] ?? 'A';
    if (!in_array($sex, ['M', 'F', 'A'], true)) {
        $sex = 'A';
    }

    $items = $in['items'] ?? [];
    if (!is_array($items)) {
        $items = [];
    }
    // カタログに存在するキーのみを、カタログの順序で採用する
    $sel = [];
    $want = array_flip(array_map('strval', $items));
    foreach ($cat as $k => $v) {
        if (isset($want[$k])) {
            $sel[] = $k;
        }
    }
    if (count($sel) === 0) {
        throw new SlError('検査項目を 1 つ以上選択してください。');
    }

    $rows = $npat * $points;
    if ($rows * count($sel) > SL_MAX_CELLS) {
        throw new SlError(sprintf(
            '出力量が上限を超えています (%d 行 x %d 項目 = %d セル、上限 %d)。'
            . '患者数・点数・項目数を減らしてください。',
            $rows, count($sel), $rows * count($sel), SL_MAX_CELLS));
    }

    $trunc = $in['trunc'] ?? 'sd5';
    if (!in_array($trunc, ['none', 'sd5', 'ri'], true)) {
        $trunc = 'sd5';
    }

    $sfrac = (float)($in['sfrac'] ?? 10.0);
    if ($sfrac < 0.1) {
        $sfrac = 0.1;
    }
    if ($sfrac > 100.0) {
        $sfrac = 100.0;
    }

    $noise = (float)($in['noise'] ?? 1.0);
    if ($noise < 0.0) {
        $noise = 0.0;
    }
    if ($noise > 50.0) {
        $noise = 50.0;
    }

    $interval = (float)($in['interval'] ?? 4.0);
    if ($interval <= 0.0) {
        $interval = 4.0;
    }
    if ($interval > 520.0) {
        $interval = 520.0;
    }

    $corrscale = (float)($in['corrscale'] ?? 100.0);
    if ($corrscale < 0.0) {
        $corrscale = 0.0;
    }
    if ($corrscale > 100.0) {
        $corrscale = 100.0;
    }

    $seedraw = trim((string)($in['seed'] ?? ''));
    if ($seedraw === '' || !ctype_digit($seedraw)) {
        $seed = random_int(1, 2000000000);
    } else {
        $seed = (int)$seedraw;
        if ($seed < 0) {
            $seed = -$seed;
        }
        if ($seed > 2000000000) {
            $seed = $seed % 2000000000;
        }
    }

    return [
        'mode' => $mode, 'npat' => $npat, 'points' => $points, 'sex' => $sex,
        'items' => $sel,
        'trunc' => $trunc, 'sfrac' => $sfrac, 'noise' => $noise,
        'interval' => $interval, 'corrscale' => $corrscale, 'seed' => $seed,
        'writez' => !empty($in['writez']),
        'batch' => $batch,
    ];
}

/**
 * 各項目の lnorm3 パラメータと切断区間を決める。
 * @param array $warn 設定の競合などを呼び出し側へ返すための警告配列 (参照)
 * @return array key => ['mu','sigma','d','lo','hi','L','U','M','unit','dec','label']
 */
function sl_params(array $cfg, array &$warn = []): array
{
    $cat = synthlab_catalog();
    $out = [];
    foreach ($cfg['items'] as $k) {
        $it = $cat[$k];
        [$L, $U, $M] = catalog_three($it, $cfg['sex']);
        $p = lnorm3_from_three((float)$L, (float)$U, (float)$M);
        if ($p === null) {
            throw new SlError("項目 {$k} のパラメータを復元できませんでした。");
        }
        $out[$k] = [
            'mu' => $p['mu'], 'sigma' => $p['sigma'], 'd' => $p['d'],
            // 偏差値・パーセンタイルの基準に使う分布。
            // いまは生成に使う分布と同一だが、将来 mu をずらす仕組みを
            // 入れる場合に基準を取り違えないよう別に持っておく。
            'rmu' => $p['mu'], 'rsigma' => $p['sigma'], 'rd' => $p['d'],
            'lo' => -INF, 'hi' => INF,
            'L' => (float)$L, 'U' => (float)$U, 'M' => (float)$M,
            'unit' => $it['unit'], 'dec' => (int)$it['dec'], 'label' => $it['label'],
        ];
    }

    // 切断区間を決める
    $trunc = $cfg['trunc'];

    foreach ($out as $k => $p) {
        $lo = -INF;
        $hi = INF;
        if ($trunc === 'sd5') {
            // MEDINFO 2025 のデータ前処理 (期待値から 5 sigma を超える値を除外) に対応
            $lo = $p['mu'] - 5.0 * $p['sigma'];
            $hi = $p['mu'] + 5.0 * $p['sigma'];
        } elseif ($trunc === 'ri') {
            // 基準範囲内 (2.5-97.5 パーセンタイル) に限定する
            $lo = log($p['L'] - $p['d']);
            $hi = log($p['U'] - $p['d']);
        }
        // 物理的な下限 (検査値は負にならない)。
        // d < 0 の項目では lnorm3 の台が 0 を下回るため、
        // y = e^z + d >= 0  <=>  z >= log(-d) を課す。
        // 多くの項目では log(-d) は mu-5sigma より遥かに下で影響しないが、
        // CRP のように 0 近傍に張り付く項目では負値の出力を防ぐのに必要。
        if ($p['d'] < 0.0) {
            $lo = max($lo, log(-$p['d']));
        }
        $out[$k]['lo'] = $lo;
        $out[$k]['hi'] = $hi;
    }
    return $out;
}

/**
 * 性別の指定から生成グループを決める。
 * 'A' (男女混合) は男女それぞれの基準範囲で半分ずつ生成する混合コホートにする。
 * 男女の基準範囲を平均してから当てはめるのは、混合分布とは別物なので採らない。
 *
 * @return array<int,array{sex:string,npat:int}>
 */
function sl_sex_groups(array $cfg): array
{
    if ($cfg['sex'] === 'M' || $cfg['sex'] === 'F') {
        return [['sex' => $cfg['sex'], 'npat' => $cfg['npat']]];
    }
    $m = (int)ceil($cfg['npat'] / 2);
    $f = $cfg['npat'] - $m;
    $g = [];
    if ($m > 0) {
        $g[] = ['sex' => 'M', 'npat' => $m];
    }
    if ($f > 0) {
        $g[] = ['sex' => 'F', 'npat' => $f];
    }
    return $g;
}

/** 性別コードの表示名 */
function sl_sex_label(string $s): string
{
    return ['M' => '男性', 'F' => '女性', 'A' => '男女混合'][$s] ?? $s;
}

/**
 * 全グループを生成する。グループごとにバックエンドを1回呼ぶ。
 *
 * @return array{groups:array,elapsed:float,warn:array,cinfo:array}
 * @throws SlError
 */
function sl_generate(array $cfg, array &$warn = []): array
{
    $groups = [];
    $elapsed = 0.0;
    $cinfo = [];
    foreach (sl_sex_groups($cfg) as $gi => $g) {
        $sub = $cfg;
        $sub['sex'] = $g['sex'];
        $sub['npat'] = $g['npat'];
        // グループごとに乱数種をずらす (同じ患者番号で同じ乱数列を使わないため)
        $sub['seed'] = ($cfg['seed'] + 7919 * $gi) % 2147483647;
        $params = sl_params($sub, $warn);
        $sp = sl_spec($sub, $params);
        $cinfo = $sp['cinfo'];
        $res = sl_run($sp['spec']);
        $elapsed += $res['elapsed'];
        $groups[] = [
            'sex' => $g['sex'], 'npat' => $g['npat'], 'params' => $params,
            'csv' => $res['csv'], 'diag' => $res['diag'], 'spec' => $sp['spec'],
        ];
    }
    return ['groups' => $groups, 'elapsed' => $elapsed, 'warn' => $warn, 'cinfo' => $cinfo];
}

/** 出力する属性列 (項目より前に置く列) を返す */
function sl_attr_cols(array $cfg): array
{
    $c = ['patient_id', 'sex'];
    if ($cfg['mode'] === 'series') {
        $c[] = 'visit';
        $c[] = 'week';
    }
    return $c;
}

/**
 * 生成結果を1行ずつコールバックへ渡す。
 * 全行を配列に持たずに済むよう、グループの CSV をなめながら属性列を前置する。
 *
 * コールバックは (array $attr, array $vals, array $zvals, string $sex, array $params)
 * を受け取る。$vals は 項目キー => 文字列。
 */
function sl_each_row(array $cfg, array $gen, callable $fn): void
{
    $pid = 0;
    foreach ($gen['groups'] as $g) {
        $lines = preg_split('/\r?\n/', trim($g['csv']));
        $head = explode(',', (string)array_shift($lines));
        $idx = array_flip($head);
        $isSeries = ($cfg['mode'] === 'series');
        $lastRaw = null;
        foreach ($lines as $ln) {
            if ($ln === '') {
                continue;
            }
            $f = explode(',', $ln);
            // バックエンドの患者IDが変わったら通し番号を進める
            if ($f[0] !== $lastRaw) {
                $pid++;
                $lastRaw = $f[0];
            }
            $attr = [
                'patient_id' => sprintf('SL%06d', $pid),
                'sex' => $g['sex'],
            ];
            if ($isSeries) {
                $attr['visit'] = $f[$idx['visit']] ?? '';
                $attr['week'] = $f[$idx['week']] ?? '';
            }
            $vals = [];
            $zs = [];
            foreach ($cfg['items'] as $k) {
                $vals[$k] = $f[$idx[$k]] ?? '';
                if ($cfg['writez'] && isset($idx['z_' . $k])) {
                    $zs[$k] = $f[$idx['z_' . $k]] ?? '';
                }
            }
            $fn($attr, $vals, $zs, $g['sex'], $g['params']);
        }
    }
}

/** 実数を spec 用の文字列にする (inf を許容) */
function sl_num(float $v): string
{
    if (is_infinite($v)) {
        return $v > 0 ? 'inf' : '-inf';
    }
    return sprintf('%.12g', $v);
}

/** バックエンドへ渡す spec テキストを組み立てる */
function sl_spec(array $cfg, array $params): array
{
    $keys = array_keys($params);
    $corr = build_correlation($keys, $cfg['corrscale'] / 100.0, (string)($cfg['sex'] ?? ''));

    $L = [];
    $L[] = '# generated by SynthLab';
    $L[] = 'seed ' . $cfg['seed'];
    $L[] = 'npat ' . $cfg['npat'];
    $L[] = 'mode ' . $cfg['mode'];
    $L[] = 'writez ' . ($cfg['writez'] ? '1' : '0');
    $L[] = 'writediag 1';
    if ($cfg['mode'] === 'cross') {
        $L[] = 'burnin 400';
        $L[] = 'thin 1';
        $L[] = 'randomscan 1';
    } else {
        $L[] = 'points ' . $cfg['points'];
        $L[] = 'sfrac ' . sl_num($cfg['sfrac'] / 100.0);
        $L[] = 'noise ' . sl_num($cfg['noise'] / 100.0);
        $L[] = 'interval ' . sl_num($cfg['interval']);
    }
    foreach ($params as $k => $p) {
        // item <name> <unit> <mu> <sd> <d> <lo_z> <hi_z> <decimals>
        $L[] = sprintf('item %s %s %s %s %s %s %s %d',
            $k,
            str_replace([' ', "\t"], '_', $p['unit']),
            sl_num($p['mu']), sl_num($p['sigma']), sl_num($p['d']),
            sl_num($p['lo']), sl_num($p['hi']), $p['dec']);
    }
    $n = count($keys);
    for ($i = 0; $i < $n; $i++) {
        $row = [];
        for ($j = 0; $j < $n; $j++) {
            $row[] = sl_num($corr['R'][$i][$j]);
        }
        $L[] = 'corr ' . implode(' ', $row);
    }
    return ['spec' => implode("\n", $L) . "\n", 'cinfo' => $corr];
}

/**
 * バックエンドを実行して CSV を得る。
 * @return array{csv:string,diag:string,elapsed:float}
 * @throws SlError
 */
function sl_run(string $spec): array
{
    $bin = sl_binary();
    if (!is_executable($bin)) {
        throw new SlError('バックエンドが見つかりません (bin/synthgen)。');
    }
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $t0 = microtime(true);
    // 引数を渡さず spec は標準入力のみ。シェルを経由しないので注入の余地はない。
    $proc = proc_open([$bin], $desc, $pipes, sys_get_temp_dir());
    if (!is_resource($proc)) {
        throw new SlError('バックエンドを起動できませんでした。');
    }
    fwrite($pipes[0], $spec);
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $err = '';
    $deadline = $t0 + SL_TIMEOUT;
    while (true) {
        $r = [];
        if (!feof($pipes[1])) {
            $r[] = $pipes[1];
        }
        if (!feof($pipes[2])) {
            $r[] = $pipes[2];
        }
        if (!$r) {
            break;
        }
        $w = null;
        $x = null;
        if (stream_select($r, $w, $x, 1) === false) {
            break;
        }
        foreach ($r as $h) {
            $chunk = fread($h, 1 << 16);
            if ($chunk === false || $chunk === '') {
                continue;
            }
            if ($h === $pipes[1]) {
                $out .= $chunk;
            } else {
                $err .= $chunk;
            }
        }
        if (microtime(true) > $deadline) {
            proc_terminate($proc, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            throw new SlError('生成処理がタイムアウトしました。条件を小さくしてください。');
        }
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($proc);
    $elapsed = microtime(true) - $t0;
    if ($rc !== 0) {
        throw new SlError('生成に失敗しました: ' . trim($err));
    }
    return ['csv' => $out, 'diag' => trim($err), 'elapsed' => $elapsed];
}

/** CSV 文字列を [ヘッダ, 行配列] に分解する */
function sl_parse_csv(string $csv, int $limit = 0): array
{
    $lines = preg_split('/\r?\n/', trim($csv));
    if (!$lines || $lines[0] === '') {
        return [[], []];
    }
    $head = explode(',', array_shift($lines));
    $rows = [];
    foreach ($lines as $i => $ln) {
        if ($ln === '') {
            continue;
        }
        if ($limit > 0 && count($rows) >= $limit) {
            break;
        }
        $rows[] = explode(',', $ln);
    }
    return [$head, $rows];
}

/** 列ごとの要約統計 (平均, SD, 最小, 中央, 最大, 基準範囲内割合) */
function sl_summary(string $csv, array $params, string $mode): array
{
    $lines = preg_split('/\r?\n/', trim($csv));
    $head = explode(',', array_shift($lines));
    $skip = ($mode === 'series') ? 3 : 1;
    $cols = [];
    for ($c = $skip; $c < count($head); $c++) {
        if (str_starts_with($head[$c], 'z_')) {
            continue;
        }
        $cols[$c] = $head[$c];
    }
    $acc = [];
    foreach ($cols as $c => $name) {
        $acc[$c] = ['n' => 0, 's' => 0.0, 'ss' => 0.0, 'min' => INF, 'max' => -INF,
                    'in' => 0, 'vals' => []];
    }
    foreach ($lines as $ln) {
        if ($ln === '') {
            continue;
        }
        $f = explode(',', $ln);
        foreach ($cols as $c => $name) {
            if (!isset($f[$c])) {
                continue;
            }
            $v = (float)$f[$c];
            $a =& $acc[$c];
            $a['n']++;
            $a['s'] += $v;
            $a['ss'] += $v * $v;
            $a['min'] = min($a['min'], $v);
            $a['max'] = max($a['max'], $v);
            if (isset($params[$name]) && $v >= $params[$name]['L'] && $v <= $params[$name]['U']) {
                $a['in']++;
            }
            if (count($a['vals']) < 40000) {
                $a['vals'][] = $v;
            }
            unset($a);
        }
    }
    $res = [];
    foreach ($cols as $c => $name) {
        $a = $acc[$c];
        if ($a['n'] === 0) {
            continue;
        }
        $mean = $a['s'] / $a['n'];
        $var = max(0.0, $a['ss'] / $a['n'] - $mean * $mean);
        sort($a['vals']);
        $m = count($a['vals']);
        $med = $m ? $a['vals'][intdiv($m, 2)] : NAN;
        $res[$name] = [
            'n' => $a['n'], 'mean' => $mean, 'sd' => sqrt($var),
            'min' => $a['min'], 'max' => $a['max'], 'median' => $med,
            'inri' => $a['n'] ? 100.0 * $a['in'] / $a['n'] : 0.0,
            'theo' => isset($params[$name])
                ? lnorm3_mean($params[$name]['mu'], $params[$name]['sigma'], $params[$name]['d'])
                : NAN,
        ];
    }
    return $res;
}
