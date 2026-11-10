<?php
// export-catalog.php -- カタログと相関定義を JSON に書き出す
//
// 「このツールの入力は何か」を形にするためのファイル。
// PHP (Web) / Python / Java の3実装がこの JSON を共通の入力として読む。
//
// 正本は lib/catalog.php と lib/corr.php の PHP 配列で、JSON はそこから生成する。
// (Web アプリが動いている状態を壊さないため。将来 JSON を正本にしてもよい)
//
// 使い方: php tools/export-catalog.php

declare(strict_types=1);
require_once '/var/www/html/synthlab/lib/catalog.php';
require_once '/var/www/html/synthlab/lib/corr.php';

$dir = '/home/sanuki/synthlab/data';
@mkdir($dir, 0755, true);

// ---- 検査項目カタログ ----
$items = [];
foreach (synthlab_catalog() as $k => $it) {
    $ri = [];
    foreach ($it['ri'] as $sex => $v) {
        $ri[$sex] = ['lower' => $v[0], 'upper' => $v[1], 'median' => $v[2]];
    }
    $items[] = [
        'key'       => $k,
        'label'     => $it['label'],
        'unit'      => $it['unit'],
        'decimals'  => $it['dec'],
        'group'     => $it['group'],
        'reference' => $ri,
    ];
}
$catalog = [
    '_comment' => '検査項目の基準範囲。lower/upper は中央95%区間の両端 (2.5/97.5 パーセンタイル)、'
                . 'median は中央値。この3つから lnorm3 の (mu, sigma, d) を閉じた形で復元する。'
                . 'キー A は男女共通、M/F は性別ごと。',
    '_source'  => '基準範囲は共用基準範囲などの公開値。median は推定値 (公開されていないため)。'
                . '論文の付録データ (doi:10.5281/zenodo.3841232) ではない。',
    '_z975'    => 1.959963985,
    'defaults' => synthlab_default_items(),
    'groups'   => synthlab_groups(),
    'items'    => $items,
];
file_put_contents($dir . '/catalog.json',
    json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

// ---- 相関定義 ----
$edges = [];
foreach (synthlab_edges() as $e) {
    $edges[] = ['a' => $e[0], 'b' => $e[1], 'r' => $e[2]];
}
$corr = [
    '_comment' => '対数スケール z = log(x - d) における相関。指定のないペアは 0 (無相関)。'
                . '手で並べた相関は正定値にならないことがあるので、'
                . '利用側で Higham の最近相関行列へ射影する (固有値の床 eigen_floor)。',
    '_source'  => '論文2 の研究2 で名前が挙がっているエッジと一般的な臨床知見から構成した。'
                . '実データから推定したものではない。',
    'eigen_floor' => 0.02,
    'edges'       => $edges,
];
file_put_contents($dir . '/correlation.json',
    json_encode($corr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

printf("catalog.json      %d 項目  %d バイト\n", count($items), filesize($dir . '/catalog.json'));
printf("correlation.json  %d エッジ %d バイト\n", count($edges), filesize($dir . '/correlation.json'));
