<?php
// cli.php -- コマンドライン版の紹介 (公開・パスワードなし)
//
// 掲載している実行結果は手で書いたものではなく、
// tools/capture-cli.php が実際に CLI を走らせて記録したものを読んでいる。
declare(strict_types=1);
@ini_set('pcre.jit', '0');
require_once __DIR__ . '/lib/nav.php';
require_once __DIR__ . '/lib/usagelog.php';
ul_view('cli');

// Apache は /home/sanuki を辿れないので、tools/capture-cli.php が
// ここにもコピーしている。
$exPath = __DIR__ . '/data/cli-examples.json';
$ex = null;
if (is_readable($exPath)) {
    $ex = json_decode((string)file_get_contents($exPath), true);
}
$byId = [];
foreach (($ex['examples'] ?? []) as $e) {
    $byId[$e['id']] = $e;
}

/** 取得済みの実行例を表示する */
function sample(array $byId, string $id): void
{
    $e = $byId[$id] ?? null;
    if ($e === null) {
        echo '<div class="warn">実行例が取得されていません。'
           . '<code>php tools/capture-cli.php</code> を実行してください。</div>';
        return;
    }
    echo '<div class="clisample">';
    echo '<div class="clihead"><strong>' . htmlspecialchars($e['title']) . '</strong>'
       . ($e['ok'] ? '' : ' <span class="out">（この環境では未実行）</span>') . '</div>';
    if (($e['note'] ?? '') !== '') {
        echo '<p class="clinote">' . htmlspecialchars($e['note']) . '</p>';
    }
    echo '<pre class="cmd">' . htmlspecialchars(trim($e['cmd'])) . '</pre>';
    echo '<pre class="outp">' . htmlspecialchars($e['output']) . '</pre>';
    echo '</div>';
}
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>コマンドライン版 — SynthLab</title>
<link rel="stylesheet" href="assets/style.css">
<style>
.clisample { margin: 0 0 18px; }
.clihead { font-size: 14px; margin-bottom: 2px; }
.clinote { font-size: 12.5px; color: var(--muted); margin: 0 0 6px; }
pre.cmd {
  background: #1b2430; color: #e6edf5; border: 0; border-radius: 7px 7px 0 0;
  margin: 0; padding: 9px 12px; font-size: 12.5px; white-space: pre-wrap;
  word-break: break-all;
}
pre.cmd::before { content: '$ '; color: #7fd18a; font-weight: 700; }
pre.outp {
  background: var(--code-bg, #f2f4f8); border: 1px solid var(--line);
  border-top: 0; border-radius: 0 0 7px 7px; margin: 0;
  padding: 9px 12px; font-size: 12px; line-height: 1.55; max-height: 24em;
}
@media (prefers-color-scheme: dark) {
  pre.outp { background: #10141a; }
}
.inbox { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; }
.inbox > div { border: 1px solid var(--line); border-radius: 9px; padding: 11px 14px;
               background: var(--panel); }
.inbox h4 { margin: 0 0 5px; font-size: 13.5px; }
.inbox p { margin: 0; font-size: 12.5px; color: var(--muted); line-height: 1.6; }
.inbox.no > div { border-style: dashed; opacity: .85; }
</style>
</head>
<body>
<?php sl_header('cli', 'コマンドライン版', 'Web と同じ手法・同じ入力ファイルで動く CLI'); ?>

<div class="wrap">

<div class="note" style="margin-top:0">
  Web 画面を使わず、端末から直接生成できます。<strong>Python 版</strong>と
  <strong>Java 版</strong>があり、どちらも Web 版と
  <strong>同じ入力ファイル・同じアルゴリズム</strong>を使います。
  大量生成・自動化・CI への組み込みに向きます。
</div>

<h2>このツールの「入力」は何か</h2>

<p>よくある誤解ですが、<strong>このツールは実際の検査データを入力しません（できません）</strong>。
入力は次の2種類だけです。</p>

<div class="inbox">
  <div>
    <h4>(A) 生成条件</h4>
    <p>何をどれだけ作るか。人数・検査項目・性別・受診回数・乱数種など。<br>
    Web ではフォーム、CLI ではコマンドライン引数。</p>
  </div>
  <div>
    <h4>(B) パラメータ源</h4>
    <p>各検査項目の<strong>基準範囲</strong>（下限・上限・中央値）と、項目間の<strong>相関</strong>。<br>
    <a class="mono" href="data/catalog.json">data/catalog.json</a><br>
    <a class="mono" href="data/correlation.json">data/correlation.json</a><br>
    差し替えれば別の施設・国の分布になります。<br>
    <strong>クリックすると中身が見られます。</strong></p>
  </div>
</div>

<div class="inbox no" style="margin-top:12px">
  <div>
    <h4>実際の患者データ ― 不要・受け付けません</h4>
    <p>アップロードの口はありません。(B) の<strong>3つの値から分布を閉じた形で復元</strong>し、
    そこから乱数を引いています。実データに触れずに生成器が作れるのが、
    元になった研究の要点の一つです。</p>
  </div>
</div>

<p style="font-size:13px;color:var(--muted);margin-top:10px">
  つまり出力されるのは「実データを加工したもの」ではなく、
  <strong>公開されている基準範囲から組み立てた分布から引いた乱数</strong>です。
  <a href="about.php">手法の解説 →</a>
</p>

<h2>入手と実行</h2>

<pre class="cmd">git clone https://github.com/masarusanuki/synthlab.git synthlab &amp;&amp; cd synthlab
python3 -m pip install numpy        # Python 版の唯一の依存 (scipy は任意)</pre>
<p style="font-size:12.5px;color:var(--muted);margin:6px 0 0">
  リポジトリ:
  <a class="mono" href="https://github.com/masarusanuki/synthlab"
     target="_blank" rel="noopener">github.com/masarusanuki/synthlab</a>
  <span class="out">（現在は非公開設定のため、閲覧には権限が必要です）</span>
</p>
<p style="font-size:12.5px;color:var(--muted);margin:6px 0 14px">
  Java 版は外部ライブラリ不要です（JSON パーサも線形代数も同梱）。JDK だけあれば動きます。
</p>

<?php if ($ex !== null): ?>
<h2>実行例</h2>
<p style="font-size:12.5px;color:var(--muted);margin-top:0">
  以下はすべて<strong>実際に実行した出力そのもの</strong>です
  （<code>tools/capture-cli.php</code> が記録。取得日時
  <?= htmlspecialchars(str_replace('T', ' ', substr((string)$ex['captured_at'], 0, 19))) ?>）。
</p>

<?php sample($byId, 'explain'); ?>
<?php sample($byId, 'list'); ?>
<?php sample($byId, 'default'); ?>
<?php sample($byId, 'deviation'); ?>
<?php sample($byId, 'cross'); ?>
<?php sample($byId, 'params'); ?>

<h2>男女の扱い (<span class="mono">--sex</span>)</h2>
<p style="font-size:13px;color:var(--muted);margin-top:0">
  元になった研究は検査項目を<strong>男女別に</strong>当てはめています
  （「24種（男女別を考慮すると48種）」「20歳以上の男性518名、女性512名」、
  Fig.2 は男女別の TG・TC）。本ツールもこれにならい、
  <span class="mono">--sex M</span> / <span class="mono">F</span> で基準範囲ごと
  <span class="mono">μ, σ, d</span> が切り替わります。
  <span class="mono">--sex A</span> は男女 50:50 の混合コホートです。
</p>
<?php sample($byId, 'sex'); ?>
<p style="font-size:13px;color:var(--muted)">
  <strong>相関も男女別です。</strong>赤血球系は論文2 式4 の恒等関係
  (<span class="mono">MCV ~ Ht/RBC x 1000</span>、同論文 Table 2 で4データセット
  約2%以内の一致を確認) から、<strong>男女別の基準範囲だけを入力に相関を導出</strong>
  できます。たとえば <span class="mono">ρ(RBC,MCV)</span> は男性 −0.949 / 女性 −0.726、
  <span class="mono">ρ(Ht,MCHC)</span> は男性 +0.771 / 女性 −0.346 と符号ごと変わります。
  導出は <span class="mono">tools/derive-sex-corr.php</span>、結果は
  <a class="mono" href="data/correlation.json">data/correlation.json</a> の
  <span class="mono">edges_M</span> / <span class="mono">edges_F</span> です。
  <a href="about.php#">手法の解説 →</a>
</p>
<div class="warn" style="margin-top:-2px">
  <strong>男女別にできているのは赤血球系だけです。</strong>
  論文 Fig.4 には肝機能や腎機能の男女別の相関図も載っていますが、
  図であって数値行列が公開されていないため、他の項目は男女共通のままです。
  また男性では公表された基準範囲どうしが整合せず
  (σ(Ht) が σ(RBC) に比べて小さすぎる)、Higham の最近相関行列へ
  射影して収めています (最大ずれ 0.0465)。
</div>

<h2>入力 (B) を差し替える</h2>
<p style="font-size:13px;color:var(--muted);margin-top:0">
  カタログの JSON を書き換えれば、生成される分布が変わります。
  下の例では Hb（男性）の基準範囲を 13.7–16.8 から 14.0–15.5 に狭めました。
  復元された <span class="mono">μ, σ, d</span> が変わっていることが確認できます。
</p>
<?php sample($byId, 'swap'); ?>

<h2>JSON 出力</h2>
<?php sample($byId, 'json'); ?>

<h2>Java 版</h2>
<p style="font-size:13px;color:var(--muted);margin-top:0">
  外部ライブラリは不要です（JSON パーサ・Cholesky 分解・Jacobi 法の固有値計算まで同梱）。
  JDK 25 で警告なしにコンパイルできることを確認しています。
</p>
<pre class="cmd">cd cli/java
javac SynthLab.java
java SynthLab --items Hb,AST,CRE,Glu,HbA1c,TG --deviation</pre>
<?php
$j = $byId['java'] ?? null;
if ($j !== null && !$j['ok']): ?>
  <div class="warn" style="margin-top:8px">
    <strong>この環境では Java 版の実行例を取得できていません。</strong>
    サーバに JDK が入っていないためです
    (<code>sudo apt install default-jdk</code> で導入できます)。
    ソースは <code>cli/java/SynthLab.java</code> にあります。
    JDK を入れて <code>php tools/capture-cli.php</code> を再実行すると、
    ここに実際の出力が入ります。
  </div>
<?php elseif ($j !== null): ?>
  <pre class="outp"><?= htmlspecialchars($j['output']) ?></pre>
<?php endif; ?>

<?php else: ?>
<div class="warn">実行例がまだ取得されていません。
  <code>php tools/capture-cli.php</code> を実行してください。</div>
<?php endif; ?>

<h2>主なオプション</h2>
<div class="scroll">
<table>
<thead><tr><th class="l">区分</th><th class="l">オプション</th><th class="l">意味</th><th class="l">既定</th></tr></thead>
<tbody>
<?php foreach ([
  ['(A) 条件', '--n N', '患者数', '1'],
  ['(A) 条件', '--mode series|cross', '時系列 / 断面', 'series'],
  ['(A) 条件', '--points N', '受診回数 (時系列)', '12'],
  ['(A) 条件', '--interval W', '受診間隔 (週)', '4'],
  ['(A) 条件', '--sfrac F', '個人内変動の幅 (σ に対する比)', '0.10'],
  ['(A) 条件', '--noise F', '測定ノイズ', '0.01'],
  ['(A) 条件', '--sex M|F|A', '男性 / 女性 / 男女混合 50:50', 'M'],
  ['(A) 条件', '--items a,b,c', '検査項目', 'カタログの既定25項目'],
  ['(A) 条件', '--all-items', '全項目を使う', '—'],
  ['(A) 条件', '--trunc sd5|ri|none', '値域の切断', 'sd5 (±5σ)'],
  ['(A) 条件', '--seed N', '乱数種。同じ値なら同じ結果', '20260821'],
  ['(B) 入力', '--catalog PATH', '検査項目カタログ JSON', 'data/catalog.json'],
  ['(B) 入力', '--correlation PATH', '相関定義 JSON', 'data/correlation.json'],
  ['出力', '-o PATH', '出力先', '- (標準出力)'],
  ['出力', '--format csv|json', '出力形式', 'csv'],
  ['出力', '--deviation', '偏差値の個人票を表示', '—'],
  ['出力', '--show-params', '復元した μ, σ, d を表示', '—'],
  ['情報', '--list-items', '検査項目の一覧', '—'],
  ['情報', '--explain', '入力と手法の説明', '—'],
] as [$g, $o, $m, $d]): ?>
  <tr><td class="l"><?= htmlspecialchars($g) ?></td>
      <td class="l mono"><?= htmlspecialchars($o) ?></td>
      <td class="l" style="white-space:normal"><?= htmlspecialchars($m) ?></td>
      <td class="l mono"><?= htmlspecialchars($d) ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<p style="font-size:12.5px;color:var(--muted)">
  Python 版と Java 版で<strong>オプション名・既定値は同じ</strong>です。
  ただし <span class="mono">--seed</span> による再現は<strong>同じ実装の中での話</strong>で、
  Python と Java は乱数生成器が異なるため、同じ seed でも数値そのものは一致しません
  （従う分布は同じです）。
</p>

<div class="warn">
  出力されるのは<strong>架空の数値</strong>です。実在の患者データではなく、
  臨床判断には使用できません。
</div>

<?php sl_footer('cli'); ?>
</div>
</body>
</html>
