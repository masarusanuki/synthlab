<?php
// index.php -- 目次 (要ログイン)
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
dev_require_login();
dev_headers();
require_once __DIR__ . '/lib/md.php';
require_once __DIR__ . '/lib/layout.php';

$docs = doc_list();
dev_head('目次', null, []);
?>
<h1>SynthLab 技術解説</h1>

<p><code>paper/</code> に収録された4本の論文と <code>code/jamia26a.cpp</code> の実装を
数式レベルで解説したもの。あわせて、この解説を書く過程で構成した擬似検査値生成ツールの
アルゴリズムと、Synthea との設計思想の違いを整理している。</p>

<blockquote>
<p><strong>全体を貫く一本の筋.</strong>
要点は「3パラメータ対数正規分布の $(\mu,\sigma)$ が正規分布の $(\mu,\sigma)$ に
完全に対応する」という一点である。$X = d + e^{Z},\; Z \sim \mathcal{N}(\mu,\sigma^2)$
と書けるので、$d$ を引いて対数をとればあとは全部正規分布の話になる。
これにより偏差値の計算・Graphical LASSO・Cholesky 分解・切断多変量正規の
条件付き分布・三つの値からの逆問題が<strong>すべて同じ一つの理由で</strong>成り立つ。</p>
</blockquote>

<h2>文書一覧</h2>
<div class="cards">
<?php foreach ($docs as $d):
    $num = preg_match('/^(\d+)-/', $d['id'], $m) ? $m[1] : '–'; ?>
  <div class="card">
    <div class="n"><?= htmlspecialchars($num) ?></div>
    <a href="doc.php?id=<?= rawurlencode($d['id']) ?>"><?= htmlspecialchars($d['title']) ?></a>
    <p><?= htmlspecialchars(doc_blurb($d['id'])) ?></p>
  </div>
<?php endforeach; ?>
</div>

<h2>対象論文</h2>
<div class="scroll">
<table>
<thead><tr><th>#</th><th>論文</th><th>掲載</th><th>ファイル</th></tr></thead>
<tbody>
<tr><td>1</td><td>Shiro M., Kagawa R. <em>Validity of Lognormal Distribution in Analyzing
  Laboratory Test Values</em></td><td>Stud Health Technol Inform 290:1066–1067 (2022)<br>
  doi:10.3233/SHTI220270</td><td><code>paper/medinfo22.pdf</code></td></tr>
<tr><td>2</td><td>城 真範 <em>血液検査値に偏差値をつける</em> (教育講演)</td>
  <td>生物試料分析 47(4):113–121 (2024)</td><td><code>paper/生物試料.pdf</code></td></tr>
<tr><td>3</td><td>Furukawa T., Kagawa R., Sanuki M., Shiro M.
  <em>A Method of Synthesis of Time Series Data of Blood Test Values</em></td>
  <td>MEDINFO 2025: 1854–1855<br>doi:10.3233/SHTI251248</td>
  <td><code>paper/medinfo25-paper.pdf</code></td></tr>
<tr><td>4</td><td>同 3 の投稿原稿 (手法の記述が詳しい)</td><td>—</td>
  <td><code>paper/medinfo2025.docx</code></td></tr>
</tbody>
</table>
</div>

<h2>関連物</h2>
<ul>
<li><a href="/synthlab/" target="_blank" rel="noopener">公開ツール (擬似検査値ジェネレータ)</a>
  — パスワードなし・一般利用向け。
  <a href="/synthlab/about.php" target="_blank" rel="noopener">手法の解説ページ</a>も公開している</li>
<li><a href="feedback.php">寄せられたご意見</a>
  — 公開ツールの意見フォームへの投稿一覧</li>
<li><a href="source.php">ソースコード閲覧</a>
  — 論文実装 (C++)、SynthLab バックエンド (C++)、Python 参照実装、Web アプリ (PHP)</li>
<li><a href="usage.php">アクセス・生成ログ</a>
  — 誰がいつ何を生成したかの記録。CSV で書き出せる</li>
<li><a href="mailsetup.php">メール送信設定</a>
  — ご意見投稿時の通知メール。SMTP サーバとパスワードを設定する</li>
<li><code>backend/synthgen.cpp</code> — 依存関係ゼロの C++ バックエンド
  (<code>code/jamia26a.cpp</code> の数学的核心を引き継ぎ、時系列モードを追加)</li>
<li><code>tools/publish-docs.php</code> — <code>doc_details/*.md</code> を
  本サイトへ保護された形で配置する</li>
</ul>

<?php dev_foot(null); ?>
