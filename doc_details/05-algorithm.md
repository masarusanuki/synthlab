---
title: アルゴリズム数式解説
order: 5
---

# アルゴリズム数式解説

論文 1〜3 と `code/jamia26a.cpp` を貫く数理を、独立に読めるように導出する。
記号は次で固定する。

| 記号 | 意味 |
|---|---|
| $n$ | 検査項目数 |
| $x_i, y_i$ | 検査値 (検査値スケール) |
| $z_i$ | 対数スケールの値 $z_i = \log(x_i - d_i)$ |
| $\mu_i, \sigma_i$ | $z_i$ の平均・標準偏差 |
| $d_i$ | 絶対値方向のシフト |
| $L_i, U_i, M_i$ | 基準範囲下限・上限・中央値 |
| $\Sigma$ | $z$ の分散共分散行列 ($n\times n$) |
| $Q = \Sigma^{-1}$ | 精度行列 |
| $\Phi, \phi$ | 標準正規の CDF, PDF |
| $z_{0.975} = 1.959964$ | 標準正規の 97.5% 点 |

---

## 1. lnorm3 の基本性質

### 1.1 定義と正規分布への還元

3パラメータ対数正規分布は

$$
f(x; \mu, \sigma, d) =
\frac{1}{\sqrt{2\pi\sigma^2}\,(x-d)}
\exp\!\left\{-\frac{\bigl(\log(x-d)-\mu\bigr)^2}{2\sigma^2}\right\},
\qquad x > d
\tag{5.1}
$$

$Z = \log(X-d)$ と置くと $X = d + e^Z$ で、$dz/dx = 1/(x-d)$ だから

$$
f_X(x) = f_Z(\log(x-d)) \cdot \frac{1}{x-d}
$$

これは (5.1) と一致する。したがって

$$
\boxed{\;X \sim \mathrm{lnorm3}(\mu,\sigma,d)
\iff Z = \log(X-d) \sim \mathcal{N}(\mu, \sigma^2)\;}
\tag{5.2}
$$

**$(5.2)$ が本研究群のすべての土台である。** $d$ は台の左端を決めるだけで、
$Z$ の分布には一切影響しない。「$d$ は分布形状に影響を与えない」
(論文 2 Ⅱ節 4.) の正確な意味はこれである。

### 1.2 モーメント

$\mathbb{E}[e^{Z}] = e^{\mu + \sigma^2/2}$ (対数正規分布のモーメント母関数) より

$$
\mathbb{E}[X] = e^{\mu + \sigma^2/2} + d
\tag{5.3}
$$

これが論文 2 の**式 5** である。分散はシフトに依らず

$$
\operatorname{Var}[X] = \bigl(e^{\sigma^2}-1\bigr)e^{2\mu+\sigma^2}
\tag{5.4}
$$

分位点関数は $(5.2)$ から直ちに

$$
Q_X(p) = d + \exp\bigl(\mu + \sigma\,\Phi^{-1}(p)\bigr)
\tag{5.5}
$$

とくに中央値は $\Phi^{-1}(0.5)=0$ なので

$$
\mathrm{Median}[X] = d + e^{\mu}
\tag{5.6}
$$

$(5.3)$ と $(5.6)$ を比べると $\mathbb{E}[X] - \mathrm{Median}[X] = e^\mu(e^{\sigma^2/2}-1) > 0$、
つまり **lnorm3 は常に右に歪む**。
論文 1 が「式 2 は表現能力が過剰」と言う所以で、
現実の検査値は右裾が長いものが圧倒的に多いから、これで十分だという主張になる。

### 1.3 偏差値

論文 2 の主目的は偏差値である。$(5.2)$ より

$$
T(x) = 50 + 10\cdot\frac{\log(x-d)-\mu}{\sigma}
\tag{5.7}
$$

**数値積分が不要**な点が本質である。検査値スケールでは分布が非対称なので
$50 + 10(x - \mathbb{E}[X])/\sqrt{\operatorname{Var}[X]}$ は意味を持たないが、
対数スケールに移せば正規分布なので、$(5.7)$ が正しい偏差値になる。
累積確率も

$$
F_X(x) = \Phi\!\left(\frac{\log(x-d)-\mu}{\sigma}\right)
\tag{5.8}
$$

で、$\Phi$ はどの計算機言語でも標準提供されている
(論文 2「正規分布についての積分は各種計算機言語でも標準的に提供されている」)。

これに対し修正 Box-Cox の式 2 では $p \neq 1$ のとき積分が閉じないので、
利用の現場で毎回数値積分が必要になる。これが論文 1・2 の
「修正 Box-Cox 変換のパラメータ数と構造には課題がある」の中身である。

---

## 2. 三つの値からのパラメータ復元 (閉じた解)

論文 2 は
「平均値と基準範囲だけが示されていれば lnorm3 を仮定することで分布が完全に推定できる」
と述べる。この解を明示的に導出する。SynthLab のパラメータはすべてこれで作っている。

### 2.1 導出

与えられるのは $L = Q(0.025)$, $U = Q(0.975)$, $M = Q(0.5)$。
$(5.5)$ より、$z^\ast \equiv z_{0.975} = 1.959964$ として

$$
\begin{aligned}
M - d &= e^{\mu} \\
L - d &= e^{\mu - z^\ast\sigma} \\
U - d &= e^{\mu + z^\ast\sigma}
\end{aligned}
\tag{5.9}
$$

第2式と第3式を掛けると $e^{2\mu}$ になるので、第1式の平方と等しい:

$$
(L-d)(U-d) = e^{2\mu} = (M-d)^2
\tag{5.10}
$$

これは**幾何平均の関係**であり、$d$ についての 1 次方程式である。展開すると

$$
LU - (L+U)d + d^2 = M^2 - 2Md + d^2
$$

$d^2$ が消えて

$$
\boxed{\;
d = \frac{LU - M^2}{L + U - 2M}
\;}
\tag{5.11}
$$

$d$ が決まれば残りは $(5.9)$ から直ちに

$$
\boxed{\;
\mu = \log(M-d), \qquad
\sigma = \frac{1}{z^\ast}\log\frac{U-d}{M-d}
\;}
\tag{5.12}
$$

$\sigma$ は $\log\frac{M-d}{L-d}$ からでも同じ値になる ($(5.10)$ ゆえ)。

### 2.2 妥当性の条件と符号の意味

**(a) 定義域**: lnorm3 の台は $x > d$ なので $d < L$ が必要。

**(b) 分母の符号**: $L + U - 2M$ は
「基準範囲の算術中点 $\frac{L+U}{2}$ と中央値 $M$ のずれ」の 2 倍。
右に歪んだ分布では $M < \frac{L+U}{2}$ なので**正**。

**(c) 分子の符号**: $LU - M^2$ は $M$ と幾何平均 $\sqrt{LU}$ の比較。

- $M = \sqrt{LU}$ $\Rightarrow$ $d = 0$ (**通常の 2 パラメータ対数正規分布**)
- $M < \sqrt{LU}$ $\Rightarrow$ $d > 0$ (対数正規より歪みが強い)
- $M > \sqrt{LU}$ $\Rightarrow$ $d < 0$ (対数正規より歪みが弱い)

$d$ は「2 パラメータ対数正規分布からのずれ」を吸収するパラメータとして働く。
きれいな解釈が付く。

**(d) 退化する場合**: $L + U = 2M$ (完全対称) では $(5.11)$ が発散する。
これは正しい: 対称な分布を lnorm3 で表すには $d \to -\infty$ の極限が必要で、
そのとき lnorm3 は正規分布に収束する。実際 $d \to -\infty$ で

$$
\log(x - d) = \log(-d) + \log\left(1 - \frac{x}{d}\right)
\approx \log(-d) - \frac{x}{d}
$$

となり $z$ が $x$ の 1 次式になるので、$Z$ が正規なら $X$ も正規になる。

**SynthLab の扱い**: $|L+U-2M|$ が極小のとき、あるいは $(5.11)$ が $d \ge L$ を
与えるとき (左に歪んだ場合)、$d = M - 20(U-L)$ のように十分遠くに $d$ を固定し、
**公表値である $L, U$ を厳密に再現するように** $\mu, \sigma$ を決める:

$$
\mu = \frac{\log(U-d)+\log(L-d)}{2}, \qquad
\sigma = \frac{\log(U-d)-\log(L-d)}{2z^\ast}
\tag{5.13}
$$

これなら $Q(0.025) = L$, $Q(0.975) = U$ が厳密に成り立ち、
中央値だけがごくわずかにずれる (実測で Alb の 0.14% が最大)。
基準範囲のほうが公表された一次情報なので、そちらを優先するのが妥当である。

### 2.3 検算

TG (男性) で $L=40$, $U=234$, $M=100$ とすると

$$
d = \frac{40\cdot234 - 100^2}{40+234-200} = \frac{9360-10000}{74} = -8.6486
$$
$$
\mu = \log(100+8.6486) = 4.6881, \qquad
\sigma = \frac{1}{1.95996}\log\frac{234+8.6486}{108.6486} = 0.4100
$$

SynthLab のバックエンドで 20,000 サンプル生成した実測値は

$$
\hat Q(0.025) = 40.6, \quad \hat Q(0.5) = 100.6, \quad \hat Q(0.975) = 232.4
$$

目標 $40 / 100 / 234$ を再現している。

---

## 2.5 男女の扱い

論文は検査値を**男女別に**扱う。生物試料論文は

> 24 種（男女別を考慮すると 48 種）の血液検査項目を選択した。
> 20 歳以上の男性 518 名、女性 512 名で……

とし、Fig. 2 は「lnorm3 によって近似された分布の例（**男女別**の TG および TC）」である。
medinfo22 も "distributions using lnorm3 (red line) **by gender** for TG and TC" と書く。

したがってパラメータは性別ごとに別の組になる。性別を $g \in \{M, F\}$ として、

$$
X_i^{(g)} = d_i^{(g)} + \exp\!\left(Z_i^{(g)}\right),
\qquad Z_i^{(g)} \sim \mathcal{N}\!\left(\mu_i^{(g)},\, \left(\sigma_i^{(g)}\right)^2\right)
$$

であり、$\left(\mu_i^{(g)}, \sigma_i^{(g)}, d_i^{(g)}\right)$ は
**その性別の基準範囲** $\left(L_i^{(g)}, M_i^{(g)}, U_i^{(g)}\right)$ から
2 節の閉じた解で復元する。同じ項目でも値はまったく違う。

| 項目 | 性別 | 基準範囲 | $\mu$ | $\sigma$ | $d$ |
|---|---|---|---|---|---|
| Hb | M | 13.7–16.8 | 2.0711 | 0.0991 | 7.1667 |
| Hb | F | 11.6–14.8 | 2.5455 | 0.0639 | 0.3500 |
| RBC | M | 4.35–5.55 | 1.2740 | 0.0852 | 1.3250 |
| RBC | F | 3.86–4.92 | 1.2503 | 0.0772 | 0.8588 |
| CRE | M | 0.65–1.07 | −0.3285 | 0.1468 | 0.1100 |
| CRE | F | 0.46–0.79 | −0.6311 | 0.1558 | 0.0680 |

（`python3 cli/synthlab.py --items Hb,RBC,CRE --sex M --show-params` の実際の出力）

男女混合のコホートを作るときは、**性別ごとに独立に生成して連結する**。
混合した集団の周辺分布は 2 つの lnorm3 の混合になり、
それ自体はもはや lnorm3 ではない。Hb のように男女差が大きい項目では
混合分布が二峰性に近づくので、集団全体に 1 つの分布を当てはめる扱いはしない。

### 実装が論文に届いていない点

論文は**相関構造も性別ごとに**推定している。生物試料論文 Fig. 3 は
JIC（男性）、TBA（女性）、TBA（男性）の 3 つのグラフを並べ、
$\alpha$ だけを TBA 女性データで選んで（$\alpha = 0.04$）他にも共通に使った、と書く。
つまり **$\alpha$ は共通だが、推定されたグラフ（相関行列）は性別ごとに別**である。

本実装は $\mu, \sigma, d$ は男女別だが、
**相関行列 $R$ は男女で共用**している（`data/correlation.json` が 1 つだけ）。
性別ごとの相関行列が手元にないためで、
手法上の制約ではなく入力データの制約である。
`sl_sex_groups()` / `--sex` は性別ごとに生成系を分けているので、
性別ごとの $R$ を与えれば分けられる構造にはなっている。

## 3. 同時分布と Graphical LASSO

### 3.1 多変量への拡張

各項目を $(5.2)$ で対数スケールに移す。

$$
z_i = \log(x_i - d_i), \qquad i = 1,\dots,n
$$

**仮定**: $\mathbf z = (z_1,\dots,z_n)^{\mathsf T}$ が多変量正規分布に従う。

$$
\mathbf z \sim \mathcal{N}(\boldsymbol\mu, \Sigma)
\tag{5.14}
$$

これは各周辺が正規であることより真に強い仮定である
(周辺が正規でも同時分布が正規である必要はない) が、
論文 2 は MCV の恒等関係 (式 4) による検証でこの仮定の妥当性を確認している。

対応する $\mathbf x$ の分布は**多変量 3 パラメータ対数正規分布**で、
$x_i = d_i + e^{z_i}$、ヤコビアンは $\prod_i (x_i - d_i)^{-1}$ だから

$$
f_{\mathbf X}(\mathbf x) =
\frac{1}{(2\pi)^{n/2}|\Sigma|^{1/2}}
\left(\prod_{i=1}^n \frac{1}{x_i - d_i}\right)
\exp\!\left\{-\tfrac12 (\mathbf z - \boldsymbol\mu)^{\mathsf T}\Sigma^{-1}(\mathbf z - \boldsymbol\mu)\right\}
\tag{5.15}
$$

### 3.2 相関の変換

対数スケールの相関 $\rho_{ij} = \Sigma_{ij}/(\sigma_i\sigma_j)$ と、
検査値スケールの相関 $\rho^{y}_{ij}$ は**一致しない**。
対数正規分布の積のモーメントから

$$
\operatorname{Cov}(e^{z_i}, e^{z_j})
= e^{\mu_i+\mu_j+\frac{\sigma_i^2+\sigma_j^2}{2}}\bigl(e^{\Sigma_{ij}}-1\bigr)
$$

なので

$$
\rho^{y}_{ij} =
\frac{e^{\rho_{ij}\sigma_i\sigma_j}-1}
     {\sqrt{(e^{\sigma_i^2}-1)(e^{\sigma_j^2}-1)}}
\tag{5.16}
$$

$\sigma \to 0$ の極限で $\rho^y \to \rho$ になるが、
$\sigma$ が大きい項目 (TG は $\sigma \approx 0.41$) では差が出る。
**相関を議論するときはどのスケールの相関かを明示する必要がある。**
論文 2 が分散共分散行列を作るのは対数スケール ($d$ を引いて対数をとってから) なので、
$\Sigma$ と GLASSO の結果はすべて対数スケールの量である。

### 3.3 Graphical LASSO

多変量正規分布の対数尤度は、標本共分散 $S$ と精度行列 $\Theta = \Sigma^{-1}$ で

$$
\ell(\Theta) = \frac{N}{2}\bigl(\log\det\Theta - \operatorname{tr}(S\Theta)\bigr) + \text{const}
$$

これに $\ell_1$ 罰則を足して最大化する。

$$
\hat\Theta = \arg\max_{\Theta \succ 0}
\Bigl\{ \log\det\Theta - \operatorname{tr}(S\Theta) - \alpha\lVert\Theta\rVert_1 \Bigr\}
\tag{5.17}
$$

$\lVert\Theta\rVert_1 = \sum_{i\neq j}|\Theta_{ij}|$ (対角を除く流儀もある)。

**なぜ精度行列を疎にするのか.** 多変量正規分布では

$$
\Theta_{ij} = 0 \iff z_i \perp z_j \mid \mathbf z_{\setminus\{i,j\}}
\tag{5.18}
$$

つまり精度行列のゼロは**条件付き独立**を意味する。
共分散のゼロ (無相関) ではなく条件付き独立を切るので、
「A と B が相関しているのは C を介してだけ」という間接効果を除去でき、
ネットワークとして読める図になる。論文 2 が Fig. 4 で可視化しているのはこれである。

**$\alpha$ の役割.** $\alpha$ を上げるとゼロ成分が増えて疎になる。
論文 2 は TBA 女性データで $\alpha$ とエッジ数の関係を調べ (Fig. 3)、
**エッジ数が 50 本程度になる $\alpha = 0.04$** を選び、他のデータにも共通に使った。
モデル選択基準 (BIC など) ではなく「人間が読める本数」で決めている。
可視化が目的なので合理的である。

**正定値性の保証.** $(5.17)$ は $\log\det\Theta$ 項が
$\Theta \succ 0$ の内点で $-\infty$ に発散するので、解は必ず正定値になる。
これが後段の Cholesky 分解を確実に成功させる
(→ [03](03-medinfo2025-timeseries.md) 2.3 節)。

**$\ell_1$ 罰則の副作用.** $\ell_1$ は非ゼロ成分も 0 に向けて縮小するので、
生き残ったエッジの**強さは過小推定される**。
可視化と数値生成の両方に使う場合は、位相 (どのエッジが残るか) を GLASSO で決め、
強さは選ばれた成分について再推定する (relaxed lasso) ほうが素直である。

---

## 4. 切断つき同時分布からのサンプリング

ここが `code/jamia26a.cpp` の核心である。

### 4.1 なぜ切断が必要か

論文 3 の前処理は「期待値から $5\sigma$ を超える値を削除」である。
削除した以上、モデルは**切断された分布**でなければ整合しない。
切断を無視して切断前の分布からサンプリングすると、
$\pm5\sigma$ の外に値が出てしまう。

切断多変量正規分布を

$$
p(\mathbf z) \propto
\exp\!\left\{-\tfrac12(\mathbf z-\boldsymbol\mu)^{\mathsf T}Q(\mathbf z-\boldsymbol\mu)\right\}
\cdot \mathbf 1[\mathbf z \in A],
\qquad A = \prod_{i=1}^n [a_i, b_i]
\tag{5.19}
$$

と書く。検査値スケールの区間 $[L_i, U_i]$ は $a_i = \log(L_i-d_i)$,
$b_i = \log(U_i-d_i)$ に写る (単調変換なので区間は区間に写る)。

### 4.2 棄却法が破綻する理由

$A$ 全体の受理確率は、次元が上がると指数的に小さくなる。各次元の受理確率を $q$ とすると

$$
p_{\text{acc}} \approx q^n,
\qquad \mathbb{E}[\text{試行回数}] = q^{-n}
\tag{5.20}
$$

$q=0.8$, $n=57$ で $q^{-n} \approx 4.2\times 10^5$。
10,000 サンプルなら 42 億回。**次元の呪い**そのものである。
論文 3 が「多次元データに棄却法を使うと計算量が爆発する」と言うのはこれ。

### 4.3 条件付き分布 — Gibbs の導出

$(5.19)$ の指数部を $z_i$ について整理する。$\mathbf v = \mathbf z - \boldsymbol\mu$ として

$$
\mathbf v^{\mathsf T}Q\mathbf v
= Q_{ii}v_i^2 + 2v_i\sum_{j\neq i}Q_{ij}v_j + \sum_{j,k\neq i}Q_{jk}v_jv_k
$$

$z_i$ 以外を固定すると第3項は定数なので

$$
-\tfrac12\mathbf v^{\mathsf T}Q\mathbf v
= -\frac{Q_{ii}}{2}\left(v_i + \frac{1}{Q_{ii}}\sum_{j\neq i}Q_{ij}v_j\right)^2 + \text{const}
$$

と平方完成できる。よって

$$
\boxed{\;
z_i \mid \mathbf z_{-i} \sim
\mathcal{TN}\!\left(m_i,\ \tau_i^2,\ a_i,\ b_i\right),
\quad
\tau_i^2 = \frac{1}{Q_{ii}},
\quad
m_i = \mu_i - \frac{1}{Q_{ii}}\sum_{j\neq i}Q_{ij}(z_j-\mu_j)
\;}
\tag{5.21}
$$

$\mathcal{TN}$ は 1 次元の切断正規分布。**重要な帰結が 2 つある。**

1. **条件付き分散が $1/Q_{ii}$ で定数**。$\mathbf z_{-i}$ に依らない。
   これは $Q$ を持っているから得られる。$\Sigma$ しかないと
   $\Sigma_{ii} - \Sigma_{i,-i}\Sigma_{-i,-i}^{-1}\Sigma_{-i,i}$ を毎回計算することになる
2. **切断が 1 次元に落ちる**。$A$ が矩形なら $z_i$ の条件付き台は $[a_i,b_i]$ そのまま。
   1 次元の切断正規なら逆関数法で**棄却なしに厳密に**引ける

$(5.20)$ の $q^{-n}$ が $(5.21)$ で $n$ 回の 1 次元サンプリングに変わる。
これが**指数から線形への改善**である。

### 4.4 残差ベクトルによる高速化

$\mathbf r = Q(\mathbf z - \boldsymbol\mu)$ を保持する。
$r_i = Q_{ii}(z_i-\mu_i) + \sum_{j\neq i}Q_{ij}(z_j-\mu_j)$ なので、$(5.21)$ は

$$
m_i = \mu_i - \frac{r_i - Q_{ii}(z_i-\mu_i)}{Q_{ii}}
= \mu_i - \frac{r_i}{Q_{ii}} + (z_i - \mu_i)
= z_i - \frac{r_i}{Q_{ii}}
\tag{5.22}
$$

$\boldsymbol\mu$ が消えて**現在値と残差だけ**で書ける。
$z_i$ を $\delta$ だけ動かしたときの $\mathbf r$ の更新は

$$
\mathbf r \leftarrow \mathbf r + \delta\, Q_{:,i}
\tag{5.23}
$$

1 成分あたり $O(n)$、1 掃引 $O(n^2)$。

### 4.5 1 次元切断正規のサンプリングと数値問題

$[\alpha,\beta]$ (標準化: $\alpha = (a_i-m_i)/\tau_i$) に対して

$$
z = \Phi^{-1}\bigl(\Phi(\alpha) + u[\Phi(\beta)-\Phi(\alpha)]\bigr)
\tag{5.24}
$$

**破綻するのは裾**。$\alpha \gg 0$ では $\Phi(\alpha)\to1$ で、
$\Phi(\beta)-\Phi(\alpha)$ が倍精度で 0 になる。
$\alpha=9$ なら $1-\Phi(9)\approx1.1\times10^{-19}$ で $\Phi(9)$ は厳密に $1.0$。

**対処**: 右裾確率 $S(x)=1-\Phi(x)$ の**対数**で計算する。
切断分布の右裾確率は

$$
S_{\text{tr}}(z) = \frac{S(z)-S(\beta)}{S(\alpha)-S(\beta)}
$$

$S_{\text{tr}}(z) = u$ を解くと $S(z) = S(\beta) + u[S(\alpha)-S(\beta)]$、対数で

$$
\log S(z) = \operatorname{logsum}\Bigl(\log S(\beta),\;
\log u + \operatorname{logdiff}\bigl(\log S(\alpha), \log S(\beta)\bigr)\Bigr)
\tag{5.25}
$$

$$
\operatorname{logsum}(a,b) = \max + \log_1p\!\left(e^{\min-\max}\right),
\qquad
\operatorname{logdiff}(a,b) = a + \log_1p\!\left(-e^{b-a}\right)
$$

$\log S$ 自体は $x \ge 8$ でミルズ比の漸近展開を使う。

$$
S(x) = \frac{\phi(x)}{x}\left(1-\frac{1}{x^2}+\frac{3}{x^4}-\frac{15}{x^6}+\cdots\right)
$$
$$
\log S(x) \approx -\frac{x^2}{2}-\log x-\frac12\log(2\pi)
+\log\!\left(1-\frac{1}{x^2}+\frac{3}{x^4}\right)
\tag{5.26}
$$

最後に $\log S(z)=\log q$ を単調性を使った二分法で解く。
左裾 ($\beta < -8$) は対称性 $z \mapsto -z$ で右裾に帰着させる。

この一連の処理により、$\alpha$ が $10$ でも $100$ でも
サンプリングが数値的に成立する。切断正規サンプラの実用上の要点である。

### 4.6 収束と間引き

Gibbs は 1 本の連鎖なので、隣接サンプルは相関する。
バーンイン $B$ 掃引を捨て、$T$ 掃引ごとに 1 サンプル採る。
妥当性の指標がラグ 1 自己相関

$$
\hat\rho_1(z_i) = \frac{\sum_{t=2}^{N}z_{i,t}z_{i,t-1}-(N-1)\bar z_i^2}
                       {\sum_{t=1}^{N}z_{i,t}^2-N\bar z_i^2}
\tag{5.27}
$$

で、$\hat\rho_1 \approx 0$ なら間引きが十分。
`jamia26a.cpp` はこれを診断ファイルに出す。

**ランダム走査**: 毎掃引で更新順をランダム置換すると遷移核が可逆になり、
詳細釣り合いを満たす。決定的走査より理論的な扱いが素直である。

---

## 5. 個人内変動と時系列 (論文 3)

### 5.1 二段階の分散分解

論文 3 の核心は次の観察である。

> 個人に着目したとき、血液検査値は対数正規分布のごく一部の範囲でしか変化しない。

これは分散を**個人間 (between) と個人内 (within)** に分けるという主張である。

$$
z_{p,t,i} = \underbrace{\mu_i + \eta_{p,i}}_{\text{個人 } p \text{ の水準}}
\;+\; \underbrace{\varepsilon_{p,t,i}}_{\text{個人内変動}}
\tag{5.28}
$$

$$
\boldsymbol\eta_p \sim \mathcal{N}(\mathbf 0, \Sigma),
\qquad
\boldsymbol\varepsilon_{p,t} \sim \mathcal{N}(\mathbf 0, f^2\Sigma),
\qquad f \approx 0.1 \text{ or } 0.2
$$

$f$ が「$\sigma$ の 10% あるいは 20%」に対応する。
これを**集団全体からの独立サンプル**でやってしまうと $f=1$ 相当になり、
同一人物の値が毎回分布全体を飛び回る非現実的な系列になる。それを避けている。

**注意**: $(5.28)$ の周辺分布は $\operatorname{Var}[z_{p,t,i}]=(1+f^2)\sigma_i^2$ に
なるので、集団の周辺分布は指定した $\sigma$ より $\sqrt{1+f^2}$ 倍広がる
($f=0.1$ で 0.5% 増)。厳密に合わせるなら
$\boldsymbol\eta \sim \mathcal{N}(\mathbf 0, (1-f^2)\Sigma)$ とすればよい。
実用上は無視できる差である。

### 5.2 Cholesky による相関の導入

$\Sigma = LL^{\mathsf T}$ ($L$ は下三角) とすると、
$\mathbf w \sim \mathcal{N}(\mathbf 0, I)$ に対して

$$
\operatorname{Cov}[L\mathbf w] = L\,\operatorname{Cov}[\mathbf w]\,L^{\mathsf T}
= LL^{\mathsf T} = \Sigma
\tag{5.29}
$$

なので $\mathbf z = \boldsymbol\mu + L\mathbf w$ が所望の相関を持つ。
個人内変動は $\Sigma_{\text{local}} = f^2\Sigma = (fL)(fL)^{\mathsf T}$ なので、
**同じ $L$ を $f$ 倍して使い回せる**。論文 3 が
「初期値に適用したのと同じ下三角行列を適用」と言うのはこれである。

$\mathbf w$ の各成分は独立標準正規なので、
$w_i = \Phi^{-1}(u_i)$ の**逆関数法**で棄却なしに引ける ($O(n)$)。
これが論文 3 の「棄却法ではなく逆関数法」の内容である。

### 5.3 論文 3 の方式の厳密性について

論文 3 は「独立な多次元正規に逆関数法 → $L$ を掛ける」順序である。
このとき**切断は厳密には満たされない**。$\mathbf w$ の各成分を区間に収めても、
$L\mathbf w$ の各成分が区間に収まる保証はない。

$$
\text{(逆関数法で各成分を切断)} \;\circ\; L
\;\neq\;
L \;\circ\; \text{(切断)}
$$

つまり論文 3 の手法は「切断つき多変量正規分布からのサンプリング」ではなく、
「相関つき多変量正規分布からのサンプリング (切断は前処理でデータ側に施した)」である。
高速性のためにその厳密性を手放している。

**厳密にやるなら $(5.21)$ の Gibbs**。
条件付き分布が 1 次元切断正規になるので、逆関数法を各条件付き分布に適用すれば、
相関と切断を**同時に厳密に**満たせる。これが `jamia26a.cpp` の方式で、
論文 3 の自然な発展になっている。

### 5.4 自己相関の欠如

$(5.28)$ で $\boldsymbol\varepsilon_{p,t}$ が $t$ について独立なので

$$
\operatorname{Cor}(z_{p,t,i},\, z_{p,t+1,i}) = \frac{1}{1+f^2} \cdot 1
\quad\text{(個人水準 } \eta_p \text{ 経由のみ)}
$$

個人内変動成分自体のラグ 1 自己相関は **0** である。
論文 3 が課題として挙げる
「サンプルを単に並べるのではなく、平均距離が保たれるよう並べ替える」
はこれを指す。

**改善案**: 個人内変動を AR(1) にすればよい。

$$
\boldsymbol\varepsilon_{p,t} = \rho\,\boldsymbol\varepsilon_{p,t-1}
+ \sqrt{1-\rho^2}\; fL\mathbf w_t
\tag{5.30}
$$

$\boldsymbol\varepsilon_{p,0} \sim \mathcal{N}(\mathbf 0, f^2\Sigma)$ から始めれば、
$\operatorname{Var}[\boldsymbol\varepsilon_{p,t}] = f^2\Sigma$ が**全 $t$ で保存**される
(定常 AR(1))。周辺分布を一切変えずにラグ 1 自己相関 $\rho$ を導入できる。

さらに項目ごとに時定数が違う (HbA1c は赤血球寿命 ~120 日を反映するので
週単位では動けない、というのが論文 3 の指摘) ので、
$\rho$ を項目別に $\rho_i = \exp(-\Delta t/\tau_i)$ とすれば、
検査間隔 $\Delta t$ と項目固有の時定数 $\tau_i$ を反映できる。

> ただし論文 3 は「予備的解析では検査間隔と標準偏差に明確な相関は見られなかった」
> と報告しており、実データから $\tau_i$ を決める根拠はまだない。
> SynthLab では $(5.30)$ を実装せず、論文どおりの i.i.d. 連結 + ノイズを
> 既定にしている (根拠のない構造を勝手に足さない方針)。

---

## 6. 正定値性の確保

手で相関を並べると容易に正定値性が壊れる。
とくに赤血球系は定義上ほぼ恒等関係にあり、対数スケールでは**線形**になる:

$$
\log \mathrm{Ht} = \log \mathrm{RBC} + \log \mathrm{MCV} + c_1,
\qquad
\log \mathrm{Hb} = \log \mathrm{RBC} + \log \mathrm{MCH} + c_2
$$
$$
\log \mathrm{MCHC} = \log \mathrm{Hb} - \log \mathrm{Ht}
= \log\mathrm{MCH} - \log\mathrm{MCV}
\tag{5.31}
$$

$(5.31)$ は論文 2 の**式 4** の対数版である。
これらの間の相関は互いに拘束されており、全ペアを独立に指定すると矛盾する。

### 6.1 一様縮小 (shrinkage)

$$
R(\lambda) = (1-\lambda)R + \lambda I
\tag{5.32}
$$

$\lambda_{\min}(R(\lambda)) = (1-\lambda)\lambda_{\min}(R) + \lambda$ は
$\lambda$ について単調増加なので、必ず正定値にできる。
実装は簡単だが**すべての相関を一律に $(1-\lambda)$ 倍**してしまう。
SynthLab の 36 項目では $\lambda = 0.268$ が必要で、
矛盾していないエッジまで 27% 薄まる。

### 6.2 最近相関行列 (Higham 2002)

Frobenius ノルムで最も近い相関行列を求める。交互射影法:

$$
R_{k+1} = P_U\bigl(P_S(R_k - \Delta S_k)\bigr),
\qquad
\Delta S_{k+1} = P_S(R_k-\Delta S_k) - (R_k - \Delta S_k)
\tag{5.33}
$$

- $P_S$: 固有値分解 $A = V\Lambda V^{\mathsf T}$ し、
  $\Lambda \leftarrow \max(\Lambda, \epsilon)$ にクリップして戻す (半正定値への射影)
- $P_U$: 対角成分を 1 に戻す (単位対角集合への射影)

これは Dykstra の交互射影で、2 つの凸集合の共通部分への射影に収束する。
**必要最小限の修正しか行わない**のが利点。

**SynthLab の実測**:

| 項目数 | 一様縮小 | Higham (最大変化) |
|---|---|---|
| 13 | $\lambda = 0.066$ | 0.024 |
| 25 | $\lambda = 0.105$ | 0.046 |
| 36 | $\lambda = 0.268$ | 0.110 |

36 項目のとき、Higham の変化は
RBC–Hb ($0.90 \to 0.79$)、Hb–MCH ($0.35 \to 0.28$)、Hb–Ht ($0.95 \to 0.89$) に
集中した。**$(5.31)$ で予想される矛盾箇所そのもの**である。
指定していないペア (0 のところ) はほぼ 0 のまま保たれる。

一様縮小では 36 項目で全エッジが 27% 薄まるので、
Higham のほうが明確に優れている。SynthLab は Higham を採用し、
収束しない場合の保険として一様縮小を残している。

### 6.3 ジッタ (jamia26a.cpp の方式)

$$
\Sigma \leftarrow \Sigma + \tau I, \qquad \tau: \tau_0, 10\tau_0, 100\tau_0, \dots
\tag{5.34}
$$

$(5.32)$ の縮小と本質的に同じ (対角だけ膨らませる) が、
**周辺分散 $\sigma_i^2$ が変わる**。だから `jamia26a.cpp` は
ジッタ適用後に `checkdiag` をもう一度呼んで
$\Sigma_{ii}$ と $\sigma_i^2$ の整合を再確認している。丁寧な実装である。
既定は $\tau_0 = 0$ で、既定では 1 度も試さずエラーにする安全側の設定。

---

## 7. 全体のパイプライン

$$
\begin{array}{rcl}
\text{(A) 公開値 } (L_i, U_i, M_i)
&\xrightarrow{(5.11),(5.12)}& (\mu_i, \sigma_i, d_i) \\[4pt]
\text{または実データ}
&\xrightarrow{\text{Nelder-Mead}}& (\mu_i, \sigma_i, d_i) \\[8pt]
z_i = \log(x_i-d_i)
&\xrightarrow{\ \hat\Sigma\ }&
\Sigma \xrightarrow{(5.17)\ \text{GLASSO}} \Sigma_{\text{sparse}} \\[8pt]
\text{切断区間 } [L_i,U_i] \text{ or } \pm5\sigma
&\xrightarrow{\log(\cdot-d_i)}& [a_i, b_i] \\[8pt]
\Sigma \xrightarrow{\text{Cholesky}} L
&\Rightarrow& Q = L^{-\mathsf T}L^{-1} \\[8pt]
\textbf{断面: } (5.21)\text{-}(5.26)\ \text{Gibbs}
&\Rightarrow& \mathbf z \Rightarrow y_i = e^{z_i}+d_i \\[4pt]
\textbf{時系列: } (5.28),(5.29)\ \text{Cholesky+逆関数法}
&\Rightarrow& \mathbf z_{p,t} \Rightarrow y_{p,t,i} = e^{z_{p,t,i}}+d_i
\end{array}
$$

**計算量**

| 処理 | オーダー |
|---|---|
| Cholesky 分解 | $O(n^3)$ (1 回) |
| 精度行列 $Q$ | $O(n^3)$ (1 回) |
| Higham 射影 | $O(n^3)$ × 反復回数 (1 回、キャッシュ可) |
| Gibbs 1 掃引 | $O(n^2)$ |
| 断面 $N$ サンプル | $O(n^3 + (B + NT)n^2)$ |
| 時系列 $P$ 人 × $T$ 点 | $O(n^3 + PTn^2)$ |

**実測** (SynthLab, C++, 単一コア): $n=30$, 10,000 点で **0.22 秒**。
論文 3 の「標準的な PC で 1 秒以内」を再現している。
