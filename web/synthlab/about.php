<?php
// about.php -- 手法の解説 (公開・パスワードなし)
declare(strict_types=1);
@ini_set('pcre.jit', '0');
require_once __DIR__ . '/lib/engine.php';
require_once __DIR__ . '/lib/nav.php';
require_once __DIR__ . '/lib/usagelog.php';
ul_view('about');

// 実際に使っている値で表を作るので、カタログから TG (男性) を例に取る
$cat = synthlab_catalog();
$ex = catalog_three($cat['TG'], 'M');           // [L, U, M]
$exp = lnorm3_from_three((float)$ex[0], (float)$ex[1], (float)$ex[2]);
$nitem = count($cat);
$nedge = count(synthlab_edges());
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>手法の解説 — SynthLab</title>
<link rel="stylesheet" href="assets/style.css">
<script>
window.MathJax = {
  tex: { inlineMath: [['$','$'],['\\(','\\)']], displayMath: [['$$','$$'],['\\[','\\]']],
         processEscapes: true, tags: 'none' },
  options: { skipHtmlTags: ['script','noscript','style','textarea','pre','code'] },
  svg: { fontCache: 'global' }
};
</script>
<script defer src="assets/mathjax-tex-svg-full.js"></script>
</head>
<body>
<?php sl_header('about', '手法の解説', '擬似血液検査値はどうやって作られているか'); ?>

<div class="wrap">

<div class="note">
  このページは、SynthLab がどういう考え方で擬似的な検査値を作っているかを
  ひととおり説明するものです。数式は出てきますが、
  <strong>各節の最初の段落だけ読めば筋は通る</strong>ように書いています。
  より詳しい導出や実装の話は関係者向けの技術文書にあります。
</div>

<div class="note" style="border-left:4px solid var(--accent)">
  <strong>この研究は「個人」に着目しています.</strong>
  元になった研究の主眼は、集団のデータセットを作ることではなく
  <strong>目の前の1人の検査値を統計的にどう解釈するか</strong>です。
  論文2 の題は「血液検査値に<strong>偏差値</strong>をつける」で、
  論文3 の出発点は「個人に着目すると検査値は分布のごく一部しか動かない」という観察です。
  <br>
  そのため本ツールも<strong>既定は「1人を詳しく見る」</strong>になっています。
  生成すると各項目の<strong>偏差値・パーセンタイル・基準分布内の位置</strong>を並べた
  個人票が出ます。N人分をまとめて出力することもできますが、
  そちらは利便のために足した副次的な機能で、
  <strong>集団としての疫学的な妥当性は考慮していません</strong>
  （有病率や年齢構成を持っていないため）。
  <br>
  <strong>生成されるのは「疾患名をもたない成人」の分布に基づく値だけです。</strong>
  元の研究が対象としているのがそこなので、疾患を指定する機能は持ちません。
</div>

<h2>0. このツールの「入力」は何か</h2>

<p>先に誤解を解いておきます。
<strong>このツールは実際の検査データを入力しません（できません）。</strong>
入力は次の2種類だけです。</p>

<div class="scroll">
<table>
<thead><tr><th class="l">入力</th><th class="l">中身</th><th class="l">どこで指定するか</th></tr></thead>
<tbody>
<tr><td class="l"><strong>(A) 生成条件</strong></td>
    <td class="l" style="white-space:normal">何をどれだけ作るか。人数・検査項目・性別・受診回数・乱数種など</td>
    <td class="l">生成画面のフォーム / CLI の引数</td></tr>
<tr><td class="l"><strong>(B) パラメータ源</strong></td>
    <td class="l" style="white-space:normal">各検査項目の<strong>基準範囲</strong>（下限・上限・中央値）と項目間の<strong>相関</strong>。
      差し替えれば別の施設・国の分布になります</td>
    <td class="l"><a href="data/catalog.json">catalog.json</a> /
      <a href="data/correlation.json">correlation.json</a></td></tr>
<tr><td class="l" style="color:var(--muted)">実際の患者データ</td>
    <td class="l" style="white-space:normal;color:var(--muted)">
      <strong>不要。受け付ける口もありません</strong></td>
    <td class="l" style="color:var(--muted)">—</td></tr>
</tbody>
</table>
</div>

<p>(B) の<strong>3つの値から分布を閉じた形で復元</strong>し（4節）、
そこから乱数を引いています（5〜7節）。
実データに触れずに生成器が作れるのが、元になった研究の要点の一つです。</p>

<h2>1. 出発点 — 検査値は左右対称ではない</h2>

<p>健康診断の結果表には「基準範囲」が書かれています。たとえば中性脂肪 (TG) の男性なら
<?= (int)$ex[0] ?>–<?= (int)$ex[1] ?> mg/dL のように、下限と上限の2つの数字です。
これは「疾患名をもたない人の検査値の<strong>中央 95% 区間</strong>」として決められています。</p>

<p>ここで問題になるのが、血液検査値の分布は<strong>多くが左右対称でない</strong>ことです。
右側の裾が長い形をしています。左右対称でない分布では</p>

<div class="note" style="text-align:center">
最頻値 &ne; 中央値 &ne; 平均値
</div>

<p>となるため、ある値が基準範囲のちょうど真ん中にあっても、
<strong>統計的な位置は真ん中ではありません</strong>。
「自分の値が標準からどれだけ離れているか」を数値で言うには、
分布そのものの形が分かっている必要があります。</p>

<h2>2. どんな分布なのか — 3パラメータ対数正規分布</h2>

<p>この点について、11〜12種類の統計分布を実データに当てはめて比べた研究があります
(Shiro &amp; Kagawa, MEDINFO 2022)。筑波大学附属病院の健康診断データ 48 項目
(24項目×男女) で残差平方和を比べた結果、最も多くの項目で最良だったのが
<strong>3パラメータ対数正規分布</strong> (lnorm3) でした。</p>

<div class="scroll">
<table>
<thead><tr><th class="l">分布</th><th>最良だった項目数</th></tr></thead>
<tbody>
<tr><td class="l"><strong>3パラメータ対数正規分布 (lnorm3)</strong></td><td><strong>17</strong></td></tr>
<tr><td class="l">ロジスティック分布</td><td>8</td></tr>
<tr><td class="l">F 分布</td><td>7</td></tr>
<tr><td class="l">修正 Box-Cox 変換の逆変換</td><td>6</td></tr>
<tr><td class="l">ワイブル分布</td><td>5</td></tr>
<tr><td class="l">ガンマ分布</td><td>3</td></tr>
<tr><td class="l">正規分布</td><td>2</td></tr>
<tr><td class="l">コーシー・対数正規・カイ二乗・ポアソン</td><td>0</td></tr>
</tbody>
</table>
</div>

<p>lnorm3 の確率密度関数は次の形です。</p>

$$
f(x;\mu,\sigma,d) = \frac{1}{\sqrt{2\pi\sigma^2}\,(x-d)}
\exp\!\left\{-\frac{\bigl(\log(x-d)-\mu\bigr)^2}{2\sigma^2}\right\},
\qquad x > d
$$

<p>パラメータは3つだけです。</p>

<div class="scroll">
<table>
<thead><tr><th class="l">記号</th><th class="l">意味</th></tr></thead>
<tbody>
<tr><td class="l">$\mu$</td><td class="l">対数をとった後の平均。分布の位置を決める</td></tr>
<tr><td class="l">$\sigma$</td><td class="l">対数をとった後の標準偏差。ばらつきと歪みの強さを決める</td></tr>
<tr><td class="l">$d$</td><td class="l">横方向のシフト。分布の左端 (これより小さい値は出ない) を決める。<strong>形そのものは変えない</strong></td></tr>
</tbody>
</table>
</div>

<h2>3. なぜこの分布が便利なのか — 対数をとると正規分布になる</h2>

<p>ここが本手法のいちばん大事な点です。lnorm3 に従う値 $X$ は、次のように書けます。</p>

$$
X = d + e^{Z}, \qquad Z \sim \mathcal{N}(\mu, \sigma^2)
$$

<p>つまり <strong>$d$ を引いて対数をとれば、あとは普通の正規分布の話になる</strong>ということです。
これだけで次のことが全部できるようになります。</p>

<ul>
<li><strong>偏差値が計算できる</strong>（これが研究の目的です） — 数値積分は不要で、
    $\;T = 50 + 10\,\dfrac{\log(x-d)-\mu}{\sigma}\;$ で済みます。
    基準範囲の下限が $T \approx 30.4$、中央値が $T = 50$、
    上限が $T \approx 69.6$ に対応します。
    絶対値が桁違いの項目どうしを<strong>同じ尺度で比較できる</strong>ようになります</li>
<li><strong>項目間の相関を分散共分散行列で扱える</strong> — 正規分布なので普通の多変量正規の道具が使えます</li>
<li><strong>Cholesky 分解で相関つきの乱数を作れる</strong></li>
<li><strong>基準範囲から分布を復元できる</strong> — 次の節</li>
</ul>

<p>パラメータが3つで、そのうち2つ ($\mu,\sigma$) が正規分布のものと完全に一致する、
という構造が効いています。パラメータが4つある方法 (修正 Box-Cox 変換) では
この対応が崩れるため、上のどれも使えなくなります。</p>

<h2>4. 公開されている数字だけで分布を復元する</h2>

<p>lnorm3 のパラメータは3つです。ということは、
<strong>独立な数字が3つ分かれば分布が一つに決まります</strong>。
基準範囲の下限 $L$・上限 $U$ と中央値 $M$ の3つを使いましょう。</p>

<p>$L$ は 2.5 パーセンタイル、$U$ は 97.5 パーセンタイル、$M$ は中央値なので、
$z^\ast = 1.96$ として</p>

$$
M-d = e^{\mu}, \qquad L-d = e^{\mu-z^\ast\sigma}, \qquad U-d = e^{\mu+z^\ast\sigma}
$$

<p>後ろの2つを掛けると $e^{2\mu}$ になり、これは1つ目の2乗と等しくなります。
つまり $(L-d)(U-d) = (M-d)^2$ で、これを $d$ について解くと $d^2$ が消えて、
きれいな形になります。</p>

$$
\boxed{\;d = \frac{LU-M^2}{L+U-2M},\qquad
\mu = \log(M-d),\qquad
\sigma = \frac{1}{z^\ast}\log\frac{U-d}{M-d}\;}
$$

<p>実際に当てはめてみます。TG (男性) の
$L=<?= (int)$ex[0] ?>$、$U=<?= (int)$ex[1] ?>$、$M=<?= (int)$ex[2] ?>$ を入れると</p>

$$
d = \frac{<?= (int)$ex[0] ?>\cdot<?= (int)$ex[1] ?> - <?= (int)$ex[2] ?>^2}
{<?= (int)$ex[0] ?>+<?= (int)$ex[1] ?>-2\cdot<?= (int)$ex[2] ?>}
= <?= number_format($exp['d'], 4) ?>,\qquad
\mu = <?= number_format($exp['mu'], 4) ?>,\qquad
\sigma = <?= number_format($exp['sigma'], 4) ?>
$$

<p>この $(\mu,\sigma,d)$ から作った乱数の分位点を実測すると、
2.5% 点が <?= (int)$ex[0] ?>、中央値が <?= (int)$ex[2] ?>、97.5% 点が <?= (int)$ex[1] ?>
に戻ってきます。<strong>実データを一切使わずに、公開されている基準範囲だけから
分布が組み立てられる</strong>ということです。</p>

<div class="note">
<strong>$d$ の符号には意味があります.</strong>
中央値 $M$ が $L$ と $U$ の幾何平均 $\sqrt{LU}$ と等しいとき $d=0$ で、
これは普通の (2パラメータの) 対数正規分布です。
$d$ は「普通の対数正規分布からどれだけずれているか」を測る量になっています。
</div>

<h2>5. 項目どうしの関係 — 同時分布</h2>

<p>検査値は互いに無関係ではありません。AST と ALT は一緒に上がりますし、
HbA1c と血糖も連動します。eGFR が下がればクレアチニンは上がります。
こうした関係を無視して各項目を独立に生成すると、
1項目ずつ見ればもっともらしいのに<strong>組み合わせとしてありえない</strong>
データができてしまいます。</p>

<p>そこで、対数スケールに移した値
$z_i = \log(x_i - d_i)$ の分散共分散行列 $\Sigma$ を与えます。
$z$ は正規分布に従うので、$\Sigma$ をコレスキー分解して $\Sigma = LL^{\mathsf T}$ とすれば、
無相関な正規乱数 $\mathbf{w}$ から</p>

$$
\mathbf{z} = \boldsymbol\mu + L\mathbf{w}
$$

<p>で目的の相関をもつ乱数が作れます。SynthLab は
<?= $nitem ?> 項目のあいだに <?= $nedge ?> 本の相関を与えています。
指定していない組み合わせは 0 (無相関) です。</p>

<div class="warn">
<strong>ここは論文の再現ではありません.</strong>
元の研究では実際の入院患者データ (10万人規模) から分散共分散行列を推定し、
Graphical LASSO という方法で関係の弱い組み合わせを切り落としています。
SynthLab はその元データを持っていないため、
論文中で名前が挙がっている関係 (AST–ALT–γGT、HbA1c–血糖、eGFR–BUN/クレアチニン など) と
一般的な臨床検査の知見から相関を組み立てています。
</div>

<h3>手で並べた相関は矛盾しうる</h3>

<p>相関を人手で並べると、しばしば数学的に成立しない組み合わせになります。
とくに赤血球系は定義上ほぼ恒等関係にあり、対数をとると<strong>線形</strong>になります。</p>

$$
\log \mathrm{Ht} = \log \mathrm{RBC} + \log \mathrm{MCV} + \text{定数}
$$

<p>この拘束があるのに RBC–Hb を 0.90、Hb–Ht を 0.95、RBC–Ht を 0.88 と
別々に指定すると、そんな行列は存在しません (正定値になりません)。
SynthLab は Higham の方法で「もっとも近い、成立する相関行列」に直します。
どのくらい直したかは生成結果のページに表示されます。</p>

<h3>相関は男女で違う — 基準範囲から導ける</h3>

<p>赤血球系については、上の恒等関係を<strong>逆向きに使えます</strong>。
対数スケールで線形になるということは、両辺の分散をとれば
標準偏差どうしの等式になる、ということです。</p>

$$
\sigma_{\mathrm{Ht}}^2 = \sigma_{\mathrm{RBC}}^2 + \sigma_{\mathrm{MCV}}^2
+ 2\rho\,\sigma_{\mathrm{RBC}}\sigma_{\mathrm{MCV}}
\;\Longrightarrow\;
\rho = \frac{\sigma_{\mathrm{Ht}}^2 - \sigma_{\mathrm{RBC}}^2 - \sigma_{\mathrm{MCV}}^2}
{2\,\sigma_{\mathrm{RBC}}\,\sigma_{\mathrm{MCV}}}
$$

<p>$\sigma$ は基準範囲の3つの値から復元できます (4節)。
そして<strong>基準範囲は男女別に公表されています</strong>。
つまり相関が男女別に決まります。新しいデータは要りません。
MCH・MCHC も加えると赤血球系6項目は3つの自由変数に還元され、
<strong>15組すべてが一意に決まります (自由パラメータなし)</strong>。</p>

<div class="scroll">
<table>
<thead><tr><th class="l">ペア</th><th>男性</th><th>女性</th><th>差</th></tr></thead>
<tbody>
<?php foreach ([
  ['RBC–MCV', -0.949, -0.726], ['RBC–MCH', -0.050, -0.574],
  ['Ht–MCV',  -0.821, -0.256], ['Ht–MCH',  +0.181, -0.565],
  ['Ht–MCHC', +0.771, -0.346], ['Hb–Ht',   +0.929, +0.552],
] as [$pair, $m, $f]): ?>
  <tr><td class="l"><?= $pair ?></td>
      <td><?= sprintf('%+.3f', $m) ?></td>
      <td><?= sprintf('%+.3f', $f) ?></td>
      <td><?= sprintf('%+.3f', $m - $f) ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<p style="font-size:13px;color:var(--muted)">
Ht–MCHC は符号ごと反転します。男女を取り違えると、
1項目ずつ見れば正常でも<strong>組み合わせとしておかしなデータ</strong>になります。
</p>

<div class="warn">
<strong>公表された基準範囲は互いに整合していません.</strong>
男性では上の式が $\rho = -0.998$ を要求し、相関行列として成立しません
($\sigma_{\mathrm{Ht}}$ が $\sigma_{\mathrm{RBC}}$ に比べて小さすぎます)。
基準範囲は項目ごとに別々の報告から来ているので、互いに整合する保証がありません。
中央値のほうはよく合います
(RBC×MCV÷10 が男性 44.4 対 公表 45.2、女性 39.4 対 39.5)。
ここでも Higham の方法で「もっとも近い、成立する組」に直しています。
</div>

<p style="font-size:13px;color:var(--muted)">
なお男女別にできているのは<strong>赤血球系だけ</strong>です。
元の論文には肝機能や腎機能についても男女別の相関図が載っていますが、
図であって数値が読めないため、他の項目は男女共通のままです。
</p>

<h2>6. 外れ値の扱い — 切断</h2>

<p>元の研究では、実データの前処理として
「平均から標準偏差の5倍を超える値を除外」しています。
除外したのであれば、生成する側も<strong>その範囲に収まる分布</strong>から
引かなければ整合しません。</p>

<p>SynthLab は切断つきの多変量正規分布からサンプリングします。
このとき単純な棄却法 (範囲外なら引き直す) を使うと、次元が上がると破綻します。
各項目が範囲に入る確率を $q$ とすると、$n$ 項目すべてが入る確率は $q^n$ なので、
$q=0.8$、$n=57$ なら</p>

$$
0.8^{-57} \approx 4.2 \times 10^{5}
$$

<p>1個のサンプルを得るのに約42万回引き直すことになります。
そこで<strong>逆関数法</strong>を使い、必ず1回で範囲内の値が得られるようにします。
さらに相関と切断を同時に厳密に満たすため、
条件付き分布 (1次元の切断正規分布になります) を順に更新する
ギブスサンプリングを併用しています。</p>

<h2>7. 時系列 — 個人は分布全体を動き回らない</h2>

<p>同じ人を何回も検査したときの値は、
<strong>その人の水準の周りをわずかに動くだけ</strong>です。
集団全体の分布から毎回独立に引いてしまうと、
同じ人の中性脂肪が 20 から 400 まで飛び回る非現実的な系列になります。</p>

<p>そこで個人間のばらつきと個人内のばらつきを分けます。</p>

$$
z_{p,t,i} = \underbrace{\mu_i + \eta_{p,i}}_{\text{個人 } p \text{ の水準}}
\;+\; \underbrace{\varepsilon_{p,t,i}}_{\text{その人の中での変動}}
$$

$$
\boldsymbol\eta_p \sim \mathcal{N}(\mathbf 0, \Sigma), \qquad
\boldsymbol\varepsilon_{p,t} \sim \mathcal{N}(\mathbf 0, f^2\Sigma)
$$

<p>$f$ が「個人内変動の幅」です。元の論文の設定は $\sigma$ の 10〜20% で、
SynthLab の既定値は 10% です。生成画面から変えられます。
1人・時系列で生成すると、<strong>集団の分布とその人の変動域を重ねた図</strong>が出るので、
「個人は分布のごく一部しか動かない」ことが目で確かめられます
（実測の比も表示します。$\sigma\sqrt{f^2+\eta^2}$ が期待値です）。
時系列の生成結果ページでは、対象患者の波形に他の患者の波形を薄く重ねて表示するので、
<strong>個人差 (線の高さの違い) と個人内変動 (線の揺れ幅) の違い</strong>が
見て取れるようになっています。</p>

<div class="note">
<strong>現在の限界.</strong> 個人内の変動は各時点で独立に引いているため、
<strong>時間的な滑らかさ (自己相関) がありません</strong>。
また項目ごとの時定数を持っていないので、
たとえば HbA1c は赤血球の寿命 (約120日) を反映する指標で
本来は週単位でこれほど動かないのに、電解質と同じ速さで動いてしまいます。
これは元の論文自身が課題として挙げている点でもあります。
</div>

<h2>8. 出力されるもの</h2>

<div class="scroll">
<table>
<thead><tr><th class="l">列</th><th class="l">内容</th></tr></thead>
<tbody>
<tr><td class="l mono">patient_id</td><td class="l">架空の患者ID (<span class="mono">SL000001</span> の形式)</td></tr>
<tr><td class="l mono">sex</td><td class="l">性別 (<span class="mono">M</span> / <span class="mono">F</span>)。男女混合を選ぶと男女それぞれの基準範囲で半数ずつ生成します</td></tr>
<tr><td class="l mono">visit / week</td><td class="l">時系列のときの受診回と初回からの週数</td></tr>
<tr><td class="l mono">各検査項目</td><td class="l">検査値。単位と小数桁は項目ごとに設定されています</td></tr>
</tbody>
</table>
</div>

<p>CSV の先頭には、使用した $(\mu,\sigma,d)$ と基準範囲、乱数種などが
コメント行 (<span class="mono">#</span> 始まり) として入ります。
<strong>同じ乱数種を入れれば同じデータが再現できます。</strong></p>

<h2>9. Synthea との違い</h2>

<p>合成医療データの生成ツールとしてよく知られているものに
<strong>Synthea</strong> (MITRE) があります。
「どちらを使えばよいか」という問いには、
<strong>解こうとしている問題が違うので競合しない</strong>というのが答えになります。</p>

<div class="note">
<strong>一言でいえば.</strong><br>
Synthea は「もっともらしい<strong>患者の物語</strong>」を作り、検査値はその小道具です。<br>
SynthLab は「統計的に正しい<strong>検査値</strong>」を作り、物語は持ちません。
</div>

<p style="font-size:13px;color:var(--muted)">
※ この対比は本ツール側の解釈です。元の論文は Synthea に言及していません。
</p>

<h2>10. 元になった研究</h2>

<ol>
<li>Shiro M., Kagawa R.
  <em>Validity of Lognormal Distribution in Analyzing Laboratory Test Values.</em>
  Stud Health Technol Inform 290:1066–1067, 2022. doi:10.3233/SHTI220270</li>
<li>城 真範. <em>血液検査値に偏差値をつける.</em> 生物試料分析 47(4):113–121, 2024</li>
<li>Furukawa T., Kagawa R., Sanuki M., Shiro M.
  <em>A Method of Synthesis of Time Series Data of Blood Test Values.</em>
  MEDINFO 2025:1854–1855. doi:10.3233/SHTI251248</li>
</ol>

<p>基準範囲は日本臨床検査標準協議会の共用基準範囲などの公開値に基づいています。
中央値は公開されていないため推定値を用いています。
したがって出力は「論文が推定したパラメータの再現」ではなく、
<strong>論文の手法を公開されている基準範囲に適用した結果</strong>です。</p>

<div class="warn">
<strong>ご利用にあたって.</strong>
出力されるのは実在しない架空の数値です。実際の患者データではありません。
診断・治療・臨床判断には使用できません。
</div>

<div class="actions">
  <a class="btnlink" href="index.php">データを生成する</a>
  <a class="btnlink" href="feedback.php">ご意見・ご感想を送る</a>
</div>

<?php sl_footer('about'); ?>
</div>
</body>
</html>
