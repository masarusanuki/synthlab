<?php
// layout.php -- 共通のページ骨格

declare(strict_types=1);

/** 文書の短い説明 (目次カード用) */
function doc_blurb(string $id): string
{
    return [
        '00-index' => '4本の論文と実装を貫く一本の筋。ここから読むとよい。',
        '01-medinfo2022-lognormal' => '12種の統計分布を横断比較して lnorm3 を選んだ研究。'
            . 'なぜパラメータ数を減らすことが本質なのか。',
        '02-seibutsu-shiryo-2024' => '実質的な主論文。式1〜5がすべてここにある。'
            . '研究1 (分布選定)、研究2 (GLASSO による同時分布)、研究3 (除外クライテリア)。',
        '03-medinfo2025-timeseries' => 'Cholesky 分解と逆関数法による高速時系列生成。'
            . '刊行版と投稿原稿 (docx) を突き合わせて読んでいる。',
        '04-code-jamia26a' => '切断多変量正規分布に対する Gibbs サンプラの実装解説。'
            . '裾の数値処理と入力検証を中心に。',
        '05-algorithm' => '論文とコードを貫く数理の統一的な導出。'
            . '三つの値からのパラメータ復元の閉じた解を含む。',
        '06-synthea-comparison' => '設計思想・生成機序・評価軸の比較。AI による解釈。',
        '07-webapp-spec' => '公開ツールの構成と検証結果。パラメータ復元と疾患モデルの実装。',
        '08-devdiary' => '実装中に判明した事実と、方針を変えた判断の記録。',
    ][$id] ?? '';
}

/** ページ先頭を出力する */
function dev_head(string $title, ?string $curId = null, array $toc = []): void
{
    $docs = doc_list();
    ?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars($title) ?> — SynthLab 開発解説</title>
<link rel="stylesheet" href="assets/style.css">
<script>
window.MathJax = {
  tex: {
    inlineMath: [['$', '$'], ['\\(', '\\)']],
    displayMath: [['$$', '$$'], ['\\[', '\\]']],
    processEscapes: true,
    tags: 'none'
  },
  options: { skipHtmlTags: ['script', 'noscript', 'style', 'textarea', 'pre', 'code'] },
  // tex-svg-full.js は全拡張を同梱しているので、\boldsymbol などで
  // 拡張が遅延読み込みされて 404 になることがない (slim 版では起きていた)。
  svg: { fontCache: 'global' }
};
</script>
<script defer src="assets/mathjax-tex-svg-full.js"></script>
</head>
<body>
<header class="top">
  <div class="brand">SynthLab <span>開発解説</span></div>
  <span class="lock">🔒 関係者向け</span>
  <nav>
    <a href="index.php">目次</a>
    <a href="feedback.php">ご意見</a>
    <a href="source.php">ソース</a>
    <a href="usage.php">ログ</a>
    <a href="mailsetup.php">メール設定</a>
    <a href="/synthlab/" target="_blank" rel="noopener">公開ツール ↗</a>
    <a href="logout.php">ログアウト</a>
  </nav>
</header>
<div class="layout">
<aside>
  <h3>文書</h3>
  <ol>
  <?php foreach ($docs as $d):
      $num = preg_match('/^(\d+)-/', $d['id'], $m) ? $m[1] : '–';
      $cur = ($curId === $d['id']); ?>
    <li><a class="<?= $cur ? 'cur' : '' ?>" href="doc.php?id=<?= rawurlencode($d['id']) ?>"
      ><span class="num"><?= htmlspecialchars($num) ?></span><?= htmlspecialchars(
        preg_replace('/^[^—]*— */u', '', $d['title']) ?: $d['title']) ?></a></li>
  <?php endforeach; ?>
  </ol>
  <?php if ($toc): ?>
  <h3>この文書の見出し</h3>
  <ol class="toc">
    <?php foreach ($toc as $t):
        if ($t['level'] < 2 || $t['level'] > 4) { continue; } ?>
      <li><a class="l<?= (int)$t['level'] ?>" href="#<?= htmlspecialchars($t['id']) ?>"
        ><?= htmlspecialchars($t['text']) ?></a></li>
    <?php endforeach; ?>
  </ol>
  <?php endif; ?>
</aside>
<main>
<?php }

/** ページ末尾を出力する */
function dev_foot(?string $curId = null): void
{
    $docs = doc_list();
    $prev = $next = null;
    if ($curId !== null) {
        $ids = array_column($docs, 'id');
        $k = array_search($curId, $ids, true);
        if ($k !== false) {
            $prev = $k > 0 ? $docs[$k - 1] : null;
            $next = $k < count($docs) - 1 ? $docs[$k + 1] : null;
        }
    }
    ?>
<?php if ($curId !== null): ?>
<div class="pager">
  <span><?php if ($prev): ?><a href="doc.php?id=<?= rawurlencode($prev['id']) ?>">←
    <?= htmlspecialchars($prev['title']) ?></a><?php endif; ?></span>
  <span style="text-align:right"><?php if ($next): ?><a href="doc.php?id=<?= rawurlencode($next['id']) ?>"
    ><?= htmlspecialchars($next['title']) ?> →</a><?php endif; ?></span>
</div>
<?php endif; ?>
<footer class="foot">
  SynthLab 開発解説 · 出典は <code>paper/</code> の4論文および <code>code/jamia26a.cpp</code> ·
  本文は <code>doc_details/*.md</code> を描画している
</footer>
</main>
</div>
</body>
</html>
<?php }
