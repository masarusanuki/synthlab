<?php
// publish-code.php -- ソースコードを開発サイトで閲覧できる形に配置する
//
// Apache は /home/sanuki を辿れない (drwxr-x---) ため、
// ソースを直接読むことができない。一方 AllowOverride None で .htaccess も使えない。
//
// そこで各ファイルを「base64 化して定数ガード付き PHP に包む」形で置く。
// base64 は英数字と + / = だけなので、ソース中の記号 (ドル記号、PHP の終了タグ、
// 区切り子など) が PHP の文法を壊すことが原理的にない (nowdoc 方式より安全)。
// 注: 単一行コメント // の中に PHP 終了タグを書くと、そこで PHP モードが
// 終わってしまう。この注意書き自体でそれを踏んだので、以後は書かない。
//
// 使い方: php tools/publish-code.php

declare(strict_types=1);

$root = '/home/sanuki/synthlab';
$dst  = '/var/www/html/synthlab_dev/code';

// 公開するファイルの明示的な一覧。
// 秘密を含むもの (synthlab_dev/config.php など) は絶対に入れない。
$files = [
    // --- 論文の実装 (C++) ---
    ['group' => '論文の実装 (C++)', 'lang' => 'cpp',
     'path' => 'code/jamia26a.cpp',
     'note' => '切断多変量正規分布に対する Gibbs サンプラ。Eigen / libconfig / libcsv を使う'],
    ['group' => '論文の実装 (C++)', 'lang' => 'conf',
     'path' => 'code/jamia26a.cfg', 'note' => 'libconfig 形式の設定ファイル'],
    ['group' => '論文の実装 (C++)', 'lang' => 'csv',
     'path' => 'code/component.csv', 'note' => '各成分の mu, sd, 切断区間, d'],
    ['group' => '論文の実装 (C++)', 'lang' => 'csv',
     'path' => 'code/cov1.csv', 'note' => '分散共分散行列 1'],
    ['group' => '論文の実装 (C++)', 'lang' => 'csv',
     'path' => 'code/cov2.csv', 'note' => '分散共分散行列 2'],
    ['group' => '論文の実装 (C++)', 'lang' => 'csv',
     'path' => 'code/cov3.csv', 'note' => '分散共分散行列 3'],

    // --- SynthLab のバックエンド (C++) ---
    ['group' => 'SynthLab バックエンド (C++)', 'lang' => 'cpp',
     'path' => 'backend/synthgen.cpp',
     'note' => '依存関係ゼロの再実装。断面 (患者ごとに独立) と時系列の両モード'],

    // --- CLI ---
    ['group' => 'CLI', 'lang' => 'python',
     'path' => 'cli/synthlab.py',
     'note' => 'コマンドライン版 (Python)。numpy のみに依存'],
    ['group' => 'CLI', 'lang' => 'java',
     'path' => 'cli/java/SynthLab.java',
     'note' => 'コマンドライン版 (Java)。外部ライブラリ不要 (JSON パーサ・線形代数も自前)'],
    ['group' => 'CLI', 'lang' => 'json',
     'path' => 'data/catalog.json', 'note' => '入力(B) 検査項目カタログ'],
    ['group' => 'CLI', 'lang' => 'json',
     'path' => 'data/correlation.json', 'note' => '入力(B) 相関定義'],
    ['group' => 'CLI', 'lang' => 'php',
     'path' => 'tools/export-catalog.php', 'note' => '入力 JSON を PHP の配列から生成'],
    ['group' => 'CLI', 'lang' => 'php',
     'path' => 'tools/capture-cli.php', 'note' => 'CLI を実行して出力を記録する'],

    // --- Python 参照実装 ---
    ['group' => 'Python 参照実装', 'lang' => 'python',
     'path' => 'python/synthlab_ref.py',
     'note' => 'numpy による参照実装。読みやすさを優先。論文と同じ言語'],
    ['group' => 'Python 参照実装', 'lang' => 'python',
     'path' => 'python/validate.py',
     'note' => '3実装 + 棄却法による真の分布を突き合わせる検証スクリプト'],

    // --- Web アプリ (PHP) ---
    ['group' => 'Web アプリ (PHP)', 'lang' => 'php',
     'path' => 'web/synthlab/lib/catalog.php',
     'note' => '検査項目カタログと三つの値からの lnorm3 復元'],
    ['group' => 'Web アプリ (PHP)', 'lang' => 'php',
     'path' => 'web/synthlab/lib/corr.php',
     'note' => '相関構造と Higham の最近相関行列 (Jacobi 固有値分解つき)'],
    ['group' => 'Web アプリ (PHP)', 'lang' => 'php',
     'path' => 'web/synthlab/lib/engine.php',
     'note' => '入力検証、spec 組み立て、バックエンド起動、性別グループ'],
    ['group' => 'Web アプリ (PHP)', 'lang' => 'php',
     'path' => 'web/synthlab/lib/mailer.php',
     'note' => '依存関係ゼロの SMTP クライアント'],
    ['group' => 'Web アプリ (PHP)', 'lang' => 'php',
     'path' => 'web/synthlab/lib/usagelog.php', 'note' => 'アクセス・生成ログ'],
    ['group' => 'Web アプリ (PHP)', 'lang' => 'php',
     'path' => 'web/synthlab/lib/feedback.php', 'note' => 'ご意見の保存と読み出し'],
];

// web/ 配下は実際には /var/www/html にあるので読み替える
function realsrc(string $root, string $rel): string
{
    if (str_starts_with($rel, 'web/')) {
        return '/var/www/html/' . substr($rel, 4);
    }
    return $root . '/' . $rel;
}

if (!is_dir($dst)) {
    fwrite(STDERR, "宛先がありません: $dst\n");
    exit(1);
}
foreach (glob($dst . '/src_*.php') ?: [] as $old) {
    unlink($old);
}

$index = [];
$n = 0;
foreach ($files as $f) {
    $src = realsrc($root, $f['path']);
    if (!is_readable($src)) {
        fwrite(STDERR, "読めません (飛ばします): $src\n");
        continue;
    }
    $body = (string)file_get_contents($src);
    $id = 'src_' . preg_replace('/[^A-Za-z0-9]+/', '_', $f['path']);
    $out = "<?php\n"
         . "// 自動生成 (tools/publish-code.php)。原本: {$f['path']}\n"
         . "if (!defined('SYNTHLAB_DEV')) { http_response_code(404); exit; }\n"
         . "return '" . base64_encode($body) . "';\n";
    if (file_put_contents($dst . '/' . $id . '.php', $out) === false) {
        fwrite(STDERR, "書けません: $id\n");
        exit(1);
    }
    chmod($dst . '/' . $id . '.php', 0644);

    // 往復検証
    if (!defined('SYNTHLAB_DEV')) {
        define('SYNTHLAB_DEV', 1);
    }
    $back = base64_decode((string)(require $dst . '/' . $id . '.php'), true);
    if ($back !== $body) {
        fwrite(STDERR, "往復検証に失敗: {$f['path']}\n");
        exit(1);
    }

    $index[] = [
        'id' => $id, 'path' => $f['path'], 'lang' => $f['lang'],
        'group' => $f['group'], 'note' => $f['note'],
        'bytes' => strlen($body),
        'lines' => substr_count($body, "\n") + 1,
        'mtime' => date('c', (int)filemtime($src)),
    ];
    $n++;
    printf("published %-34s %7d bytes  %5d 行\n", $f['path'], strlen($body),
        substr_count($body, "\n") + 1);
}

// 目次を書く
$idx = "<?php\n"
     . "// 自動生成 (tools/publish-code.php)\n"
     . "if (!defined('SYNTHLAB_DEV')) { http_response_code(404); exit; }\n"
     . "return " . var_export($index, true) . ";\n";
file_put_contents($dst . '/index_list.php', $idx);
chmod($dst . '/index_list.php', 0644);

echo "\n$n ファイルを $dst に配置しました\n";
