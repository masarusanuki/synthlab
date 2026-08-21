<?php
// doc.php -- Markdown 文書の描画 (要ログイン)
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
dev_require_login();
dev_headers();
require_once __DIR__ . '/lib/md.php';
require_once __DIR__ . '/lib/layout.php';

$id = (string)($_GET['id'] ?? '00-index');
$path = doc_path($id);

if ($path === null) {
    http_response_code(404);
    dev_head('見つかりません', null, []);
    echo '<h1>文書が見つかりません</h1><p>指定された文書は存在しません。'
       . '<a href="index.php">目次に戻る</a></p>';
    dev_foot(null);
    exit;
}

$md = new Md();
$r = $md->render(doc_load($path));
$title = $r['title'] !== '' ? $r['title'] : $id;

dev_head($title, $id, $r['toc']);
echo $r['html'];
dev_foot($id);
