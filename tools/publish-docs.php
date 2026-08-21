<?php
// publish-docs.php -- doc_details/*.md を開発サイトへ「保護された形」で配置する
//
// Apache は /home/sanuki を辿れない (drwxr-x---) ため、文書をドキュメントルート
// 外に置いたままでは PHP から読めない。一方 AllowOverride None かつ sudo 不可の
// ため .htaccess でのアクセス制限もできない。
//
// そこで各 .md を「先頭に定数ガードを持つ nowdoc」として .md.php に包む。
//   - doc.php は SYNTHLAB_DEV を define してから include するので中身が読める
//   - 直接 HTTP で叩くと定数が未定義なので 404 を返して終了する
// nowdoc (<<<'DELIM') を使うのは、文書中の $ が変数展開されないようにするため。
//
// 使い方: php tools/publish-docs.php

declare(strict_types=1);

$src = '/home/sanuki/synthlab/doc_details';
$dst = '/var/www/html/synthlab_dev/content';
$base_delim = 'SYNTHLAB_MD_EOF';

if (!is_dir($dst) && !mkdir($dst, 0755, true)) {
    fwrite(STDERR, "cannot create $dst\n");
    exit(1);
}

// 既存の生成物を消す (元 md が削除された場合に追随するため)
foreach (glob($dst . '/*.md.php') ?: [] as $old) {
    unlink($old);
}

$files = glob($src . '/*.md') ?: [];
sort($files);
$n = 0;
foreach ($files as $f) {
    $id = basename($f, '.md');
    if (!preg_match('/\A[A-Za-z0-9._-]{1,80}\z/', $id)) {
        fwrite(STDERR, "skip (bad name): $f\n");
        continue;
    }
    $body = (string)file_get_contents($f);

    // 区切り子が本文中の行頭に現れると nowdoc が終端してしまう。
    // 解説自身がこの仕組みを説明していて区切り子の名前を含むため、
    // 衝突しない区切り子を選び直す (固定名だと文書を書けなくなる)。
    $delim = $base_delim;
    for ($k = 2; preg_match('/^\s*' . preg_quote($delim, '/') . '/m', $body); $k++) {
        $delim = $base_delim . '_' . $k;
        if ($k > 99) {
            fwrite(STDERR, "ERROR: cannot find a free delimiter for $f\n");
            exit(1);
        }
    }
    // nowdoc の終端判定を確実にするため末尾改行を整える
    $body = rtrim($body, "\n");

    $out = "<?php\n"
         . "// 自動生成 (tools/publish-docs.php)。編集は doc_details/{$id}.md に対して行う。\n"
         . "if (!defined('SYNTHLAB_DEV')) { http_response_code(404); exit; }\n"
         . "return <<<'{$delim}'\n"
         . $body . "\n"
         . "{$delim};\n";

    $target = $dst . '/' . $id . '.md.php';
    if (file_put_contents($target, $out) === false) {
        fwrite(STDERR, "cannot write $target\n");
        exit(1);
    }
    chmod($target, 0644);

    // 往復検証: 包んだものを読み戻して元の本文と一致するか確認する
    if (!defined('SYNTHLAB_DEV')) { define('SYNTHLAB_DEV', 1); }
    $back = require $target;
    if (rtrim((string)$back, "\n") !== $body) {
        fwrite(STDERR, "ERROR: round-trip mismatch for $id\n");
        exit(1);
    }
    $n++;
    printf("published %-34s %6d bytes\n", $id, strlen($body));
}
echo "\n$n documents published to $dst\n";
