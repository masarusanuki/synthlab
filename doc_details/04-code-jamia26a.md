---
title: コード解説 — code/jamia26a.cpp
order: 4
---

# コード解説: `code/jamia26a.cpp`

1,257 行の C++17 単一ファイル。ファイル名から JAMIA (Journal of the American
Medical Informatics Association) 投稿予定の第3世代実装と読める。
論文 3 (MEDINFO 2025) の Python 実装を C++ に移し、
**サンプリングを近似から厳密に置き換えた**ものになっている。

```
g++ -std=c++17 -O2 jamia26a.cpp -o jamia26a -lconfig -lcsv
```

外部依存は 3 つ: **libconfig** (設定ファイル)、**libcsv** (CSV パーサ)、
**Eigen3** (線形代数、ヘッダオンリー)。

```bash
sudo apt install libeigen3-dev libconfig++-dev libcsv-dev
g++ -std=c++17 -O2 jamia26a.cpp -o jamia26a -lconfig -lcsv
```

同梱の `jamia26a.cfg` で実行すると、3 つの共分散行列それぞれについて
5,000 標本と診断ファイルが出る。診断の抜粋:

```
dim 3          symerr 0
burnin 1000    sderr 1.1102230246251565e-16
samples 5000   inverr 2.2204460492503131e-16
thin 5         usedjitter 0
acorlagonez,0.039667704717053699,-0.0029282270746244514,0.039197790665803652
```

`acorlagonez` が 0.04 前後なので、この 3 次元・`thin = 5` の設定では
標本間の自己相関はほぼ無視できる。**この診断量を見て `thin` を決める**という
運用が前提になっていることが、実際に動かすとよく分かる
(→ [08-devdiary.md](08-devdiary.md) 8.7 節で、これを見落として不具合を出した)。

---

## 0. このコードが論文 3 から進めた点

| | 論文 3 (MEDINFO 2025) | `jamia26a.cpp` |
|---|---|---|
| サンプリング | 無相関正規に逆関数法 → $L$ を掛ける | **切断多変量正規分布からの Gibbs** |
| 切断の扱い | 実質的に未対応 (前処理で $\pm5\sigma$ 除外) | **切断区間を分布の定義に組み込む** |
| 相関の使い方 | 共分散 $\Sigma$ の Cholesky 因子 $L$ | **精度行列 $Q = \Sigma^{-1}$** |
| 数値検証 | — | 対称性・SD 整合・逆行列誤差を明示的に検査 |
| 裾の扱い | — | $\alpha > 8$ で対数スケール計算に切替 |
| 出力 | 時系列 | 断面サンプル + 精度行列 + 診断統計 |
| 実装 | Python 3.12.7 | C++17 + Eigen |

**要点**: 論文 3 の「厳密には切断つき多変量正規分布になっていない」という
弱点 (→ [03](03-medinfo2025-timeseries.md) 補足) を、Gibbs サンプラで正面から解決している。

---

## 1. データ構造

```cpp
struct Numconf { double symtol, sdtol, invtol, jitter; int maxjittrial; bool checksd; };
struct Sampconf { std::string method; int burnin, samples, thin; bool randomscan; };
struct Outconf { std::string dir; bool writey, writez, writeprec, writediag; };
struct Confdata { /* 上記 3 つ + seed, ファイル名, disttype, boundscale */ };
struct Compdata { int index; double mean, sd, low, high, shift; };  // 検査項目 1 つ
struct Matdiag { double symerr, sderr, inverr, usedjit; };          // 行列の診断
struct Statdata { /* 走行中に更新する統計量 */ };
```

`Compdata` が lnorm3 の 1 項目に対応する。

| フィールド | 数式 | 意味 |
|---|---|---|
| `mean` | $\mu_i$ | 対数スケールの平均 |
| `sd` | $\sigma_i$ | 対数スケールの標準偏差 |
| `low`, `high` | $a_i, b_i$ | 切断区間 |
| `shift` | $d_i$ | 絶対値方向のシフト |

`Statdata` は Welford 法のためのフィールドを持つ。

```cpp
std::vector<double> meany, m2y;    // y の平均と2次モーメント (Welford)
std::vector<double> sumz, sumz2;   // z の1次・2次和
std::vector<double> crossz, prevz; // ラグ1自己相関のための積和と直前値
```

## 2. 設定ファイル (`jamia26a.cfg`)

libconfig 形式。同梱の設定は次のとおり。

```
seed = 0;                      # 0 なら time(0) を使う
componentfile = "component.csv";
covnum = 3;
covfiles = ( "cov1.csv", "cov2.csv", "cov3.csv" );
outfiles = ( "sample1.csv", "sample2.csv", "sample3.csv" );

sampler = { method = "gibbs"; burnin = 1000; samples = 5000; thin = 5;
            randomscan = true; };
distribution = { type = "shiftedlognormal"; boundscale = "normal"; };
numerical = { symtol = 1.0e-10; sdtol = 1.0e-8; invtol = 1.0e-8;
              jitter = 0.0; maxjittertrial = 8; checksd = true; };
outputs = { dir = "out"; writey = true; writez = false;
            writeprecision = true; writediag = true; };
```

**設計上の要点**: 1 つの `component.csv` (周辺分布) に対して
**複数の共分散行列**を与えて、それぞれ独立にサンプリングする。
同じ周辺分布のもとで相関構造だけを変えた複数のコホートを作れる。
`cov1/2/3.csv` は対角が共通 (1.0, 2.25, 0.64 = $\sigma^2$)、
非対角だけが異なる 3 通りになっている。

`component.csv`:

```
index,mean,sd,lower,upper,d
0,0.0,1.0,-2.0,2.0,0.0
1,1.0,1.5,-1.0,3.0,10.0
2,0.5,0.8,0.0,2.0,-3.0
```

`boundscale` が重要な設定である。

- `"normal"`: `lower`/`upper` は**対数スケール** ($z$ の区間) として解釈
- `"output"`: **検査値スケール** ($y$ の区間) として解釈し、
  内部で $a_i = \log(L_i - d_i)$, $b_i = \log(U_i - d_i)$ に変換

`readcomp()` の該当箇所:

```cpp
if (boundscale == "output") {
  if (std::isfinite(c.low)) {
    if (!(c.low > c.shift)) { err = "output lower must be larger than d"; return false; }
    nl = std::log(c.low - c.shift);
  }
  ...
}
```

$y > d$ が lnorm3 の定義域なので、$L_i \le d_i$ は弾かれる。妥当な検査である。

## 3. 入力の検証 — ここが実装の 3 割

このコードの特徴は**検証コードの比率が高い**ことである。

### 3.1 設定の整合性 (`readconf`)

```cpp
if (conf.covnum <= 0)                                   → "covnum must be positive"
if (covfiles.size() != covnum)                          → "covnum and covfiles length mismatch"
if (outfiles.size() != 0 && outfiles.size() != covnum)  → "... outfiles length mismatch"
if (conf.samp.method != "gibbs")                        → "only gibbs method is implemented"
if (burnin < 0 || samples <= 0 || thin <= 0)            → "invalid sampler counts"
if (boundscale != "normal" && != "output")              → "boundscale must be normal or output"
if (disttype != "shiftedlognormal")                     → "distribution.type must be ..."
```

### 3.2 成分の検証 (`readcomp`)

- ヘッダに `index, mean, sd, lower, upper` が必須。`d` は `d` または `shift`
- $\sigma_i > 0$
- $a_i < b_i$
- `index` でソートしたあと **0 から連番**であることを確認
  (行の順序に依存しない設計)

### 3.3 行列の検証 (`readmat` → `checkdiag` → `makeprec`)

**(a) 正方性**: 行数 = 各行の列数

**(b) 対称性** — `getsymerr()` は最大絶対非対称量

$$
e_{\text{sym}} = \max_{i,j} \bigl| \Sigma_{ij} - \Sigma_{ji} \bigr|
$$

これが `symtol` ($10^{-10}$) を超えたら**エラーで停止**。
通れば `symmetrize()` で $\Sigma \leftarrow (\Sigma + \Sigma^{\mathsf T})/2$ と丸める。

**(c) 対角と SD の整合** — `checkdiag()`

$$
e_{\text{sd}} = \max_i
\frac{\bigl| \Sigma_{ii} - \sigma_i^2 \bigr|}
     {\max\bigl(1, |\Sigma_{ii}|, |\sigma_i^2|\bigr)}
$$

相対誤差にしているのは項目ごとに $\sigma$ の桁が違うため。
分母に `1.0` を入れて $\sigma \to 0$ でのゼロ割を防いでいる。
`checksd = true` なら `sdtol` ($10^{-8}$) 超で停止。

> `component.csv` の `sd` と `cov*.csv` の対角が二重に $\sigma$ を持つので、
> 矛盾したら黙って片方を採るのではなく落とす、という判断。妥当である。

**(d) 精度行列の構成** — `makeprec()`

$$
\Sigma = LL^{\mathsf T} \;(\text{Cholesky}), \qquad
Q = \Sigma^{-1} = L^{-\mathsf T}L^{-1}
$$

Eigen の `LLT` を使い、`llt.solve(I)` で逆行列を得て `symmetrize()` する。

Cholesky が失敗した場合の**ジッタ戦略**:

```cpp
double jit = num.jitter;
while (!ok && jit > 0.0 && trial < num.maxjittrial) {
  work = sig;
  for (i) work(i,i) += jit;      // 対角に jit を加える
  llt.compute(work);
  ok = (llt.info() == Eigen::Success);
  if (ok) { diag.usedjit = jit; break; }
  jit *= 10.0;                    // 10 倍して再試行
  trial++;
}
```

$\Sigma + \tau I$ の最小固有値は $\lambda_{\min}(\Sigma) + \tau$ なので、
$\tau$ を上げれば必ず正定値になる。ただし既定は `jitter = 0.0` なので、
**既定では 1 度も試さずエラーにする**。安全側の既定値になっている。

**(e) 逆行列の検算**

$$
e_{\text{inv}} = \max_{i,j} \bigl| (\Sigma Q)_{ij} - \delta_{ij} \bigr|
$$

`invtol` ($10^{-8}$) 超で停止。
さらに `main()` では**ジッタ適用後にもう一度 `checkdiag`** を呼んでいる:

```cpp
if (!makeprec(sig, q, conf.num, diag, err)) { ... }
if (!checkdiag(sig, comp, conf.num, diag.sderr, err)) {
  std::cerr << "diagonal error after jitter: " << err << "\n";
```

ジッタが対角を膨らませて $\sigma$ との整合を壊していないかの再確認である。丁寧。

## 4. 数値の要 — 切断正規分布のサンプリング

ここがコードの数学的な核心である。

### 4.1 素朴な逆関数法とその限界

区間 $[\alpha, \beta]$ (標準化済み) に切断された標準正規分布からのサンプリングは

$$
z = \Phi^{-1}\bigl( \Phi(\alpha) + u\,[\Phi(\beta) - \Phi(\alpha)] \bigr),
\qquad u \sim \mathcal{U}(0,1)
$$

で得られる。実装は

```cpp
double pa = std::isfinite(alpha) ? normcdf(alpha) : 0.0;
double pb = std::isfinite(beta)  ? normcdf(beta)  : 1.0;
double p = pa + u * (pb - pa);
z = norminv(p);
```

**問題**: $\alpha$ が大きい (遠い右裾) と $\Phi(\alpha) \to 1$ で、
$\Phi(\beta) - \Phi(\alpha)$ が**倍精度の丸めで 0 になる**。
たとえば $\alpha = 9$ なら $1 - \Phi(9) \approx 1.1\times10^{-19}$ で、
$\Phi(9)$ は倍精度では厳密に $1.0$ である。区間幅が 0 になりサンプリングが壊れる。

### 4.2 対数スケールでの右裾処理

`sampletruncstd()` は場合分けで対処する。

```cpp
if (isinf(alpha) && alpha<0 && isinf(beta) && beta>0)  → z = norminv(u);     // 切断なし
if (beta < -8.0)   → 対称性で右裾問題に変換: sampletruncstd(-beta,-alpha) の符号反転
if (alpha > 8.0)   → 対数スケールで計算 (下記)
else               → 素朴な逆関数法
```

閾値 $\pm 8$ は $1-\Phi(8) \approx 6.2\times10^{-16}$ が倍精度の
相対精度 $2.2\times10^{-16}$ の数倍という水準で、妥当な切り替え点である。

$\alpha > 8$ の経路は、右裾確率 $S(x) = 1 - \Phi(x)$ の**対数**で全部計算する。
切断分布の右裾確率は

$$
S_{\text{trunc}}(z) = \frac{S(z) - S(\beta)}{S(\alpha) - S(\beta)}
$$

なので、$u \sim \mathcal{U}(0,1)$ に対して $S_{\text{trunc}}(z) = u$ を解けばよい。
$\log$ をとると

$$
\log S(z) = \log\Bigl( S(\beta) + u\,\bigl[S(\alpha) - S(\beta)\bigr] \Bigr)
$$

これを桁落ちなしに評価するために、`logsum` と `logdiff` を使う。

```cpp
double la = lognormsf(alpha);                    // log S(α)
double lb = isfinite(beta) ? lognormsf(beta) : -inf;   // log S(β)
double ld = logdiff(la, lb);                     // log(S(α) - S(β))
double lq = logsum(lb, std::log(u) + ld);        // log(S(β) + u(S(α)-S(β)))
z = invlogsf(lq);                                // S^{-1}(exp(lq))
```

補助関数はいずれも標準的な log-sum-exp のテクニックである。

$$
\mathrm{logsum}(a,b) = \max(a,b) + \log_1p\!\left(e^{\min(a,b)-\max(a,b)}\right)
$$
$$
\mathrm{logdiff}(a,b) = a + \log_1p\!\left(-e^{b-a}\right) \quad (a > b)
$$

`std::log1p` を使うので $b \to a$ でも精度が落ちない。

### 4.3 $\log S(x)$ の漸近展開

`lognormsf()`:

```cpp
if (x < 8.0) return std::log(normsf(x));
// x >= 8: 漸近展開
double xx = x*x;
double corr = 1.0 - 1.0/xx + 3.0/(xx*xx);
return -0.5*xx - std::log(x) - 0.5*std::log(2π) + std::log(corr);
```

これはミルズ比の漸近展開に対応する。$x \to \infty$ で

$$
S(x) = \frac{\phi(x)}{x}\left(1 - \frac{1}{x^2} + \frac{3}{x^4} - \frac{15}{x^6} + \cdots\right)
$$

なので、対数をとると

$$
\log S(x) \approx -\frac{x^2}{2} - \log x - \frac12\log(2\pi)
+ \log\!\left(1 - \frac{1}{x^2} + \frac{3}{x^4}\right)
$$

コードはこの 3 項までを取っている。$x = 8$ での打ち切り誤差は
$15/x^6 = 15/262144 \approx 5.7\times10^{-5}$ の**対数補正項の中**なので、
$\log S$ の絶対誤差としては $\sim 5.7\times10^{-5}$。
`corr <= 0.0` のときは `1.0` に落とすガードがある ($x < 1.7$ 程度でしか起きないので
実際には到達しない)。

### 4.4 $\log S$ からの逆関数 — `invlogsf()`

$\log S(z) = \log q$ を満たす $z$ を**二分法**で求める。

```cpp
double lo = 0.0, hi = 8.0;
while (lognormsf(hi) > logq && hi < 1.0e6) hi *= 2.0;   // 上限を確保
for (int i = 0; i < 120; i++) {                          // 120 回の二分
  double mid = 0.5*(lo+hi);
  if (lognormsf(mid) > logq) lo = mid; else hi = mid;
}
```

$\log S$ は単調減少なので二分法が使える。
120 回の反復は区間幅を $2^{-120}$ 倍にするので、倍精度では完全に収束している
(倍精度の相対精度に達するのは 60 回程度)。余裕を取った実装。

### 4.5 `norminv()` — Acklam 近似

有理関数近似。中央部 ($0.02425 < p < 0.97575$) は 6/5 次の有理式、
裾は $q = \sqrt{-2\log p}$ を変数にした 6/4 次の有理式。
相対精度は $\sim 1.15\times10^{-9}$。

**注意点**: 逆関数法の精度は最終的にここで決まる。
$1.15\times10^{-9}$ は乱数生成としては十分だが、
**倍精度の限界ではない**。厳密さを求めるなら Newton 補正を 1 回入れる
($z \leftarrow z - (\Phi(z)-p)/\phi(z)$) 余地がある。

## 5. Gibbs サンプラ — `runsamp()`

### 5.1 なぜ精度行列なのか

切断多変量正規分布

$$
p(\mathbf z) \propto
\exp\!\left\{-\tfrac12 (\mathbf z - \boldsymbol\mu)^{\mathsf T} Q (\mathbf z - \boldsymbol\mu)\right\}
\cdot \mathbf 1[\mathbf z \in A], \qquad A = \prod_i [a_i, b_i]
$$

の**1変数条件付き分布**は、1次元の切断正規分布になる。
指数部を $z_i$ について整理すると

$$
-\tfrac12 Q_{ii}\,(z_i - \mu_i)^2
- (z_i - \mu_i)\sum_{j \neq i} Q_{ij}(z_j - \mu_j) + \text{const}
$$

これを平方完成すれば

$$
\boxed{\;
z_i \mid \mathbf z_{-i} \;\sim\;
\mathcal{TN}\!\left(
m_i,\; \frac{1}{Q_{ii}},\; a_i,\; b_i
\right),
\qquad
m_i = \mu_i - \frac{1}{Q_{ii}}\sum_{j \neq i} Q_{ij}(z_j - \mu_j)
\;}
$$

**条件付き分散が $1/Q_{ii}$ という一定値になる**のがポイントで、
これは $\Sigma$ ではなく $Q = \Sigma^{-1}$ を持っているときだけ得られる。
$\Sigma$ から毎回条件付き分布を作ると $(n-1)\times(n-1)$ の逆行列が要る。
だから `makeprec()` で $Q$ を作っている。

### 5.2 残差ベクトルの差分更新

条件付き平均の計算には $\sum_j Q_{ij}(z_j-\mu_j)$ が必要である。
毎回計算すると 1 成分あたり $O(n)$、1 掃引で $O(n^2)$。
コードは残差ベクトル

$$
\mathbf r = Q(\mathbf z - \boldsymbol\mu)
$$

を保持して差分更新する。$r_i = Q_{ii}(z_i-\mu_i) + \sum_{j\neq i}Q_{ij}(z_j-\mu_j)$ なので

$$
m_i = \mu_i - \frac{1}{Q_{ii}}\left(r_i - Q_{ii}(z_i - \mu_i)\right)
= z_i - \frac{r_i}{Q_{ii}}
$$

という**きれいな形**になる。コードはまさにこう書いている。

```cpp
double sd = 1.0 / std::sqrt(qii);
double m = z(i) - r(i) / qii;          // ← 上式そのまま
sampletrunc(m, sd, low[i], high[i], rng, neu);
double delta = neu - old;
z(i) = neu;
r += delta * q.col(i);                 // r の差分更新: O(n)
```

$z_i$ が $\delta$ 動けば $\mathbf r$ は $\delta \cdot Q_{:,i}$ だけ動く。
1 成分の更新が $O(n)$、1 掃引が $O(n^2)$ で、$\mathbf r$ を持たない場合と
同じオーダーだが**定数倍が小さい** (行列ベクトル積が列アクセスの axpy になる)。

### 5.3 ランダム走査

```cpp
if (conf.samp.randomscan) std::shuffle(ord.begin(), ord.end(), rng);
```

毎掃引で更新順をシャッフルする。
決定的な順序 (systematic scan) の Gibbs は可逆でない遷移核になるが、
**ランダム走査 (random scan / random permutation scan) は詳細釣り合いを満たす**ため、
理論的な扱いが素直になる。既定で `true`。妥当な選択である。

### 5.4 バーンインと間引き

```cpp
int total = conf.samp.burnin + conf.samp.samples * conf.samp.thin;
for (int sweep = 0; sweep < total; sweep++) {
  ... 1 掃引 ...
  if (sweep >= burnin && ((sweep - burnin) % thin == 0)) { 出力; updatestat(); saved++; }
}
```

同梱設定では burnin = 1000、samples = 5000、thin = 5 なので
計 26,000 掃引して 5,000 サンプルを保存する。

### 5.5 出力への変換

```cpp
if (z(i) > std::log(std::numeric_limits<double>::max())) {
  err = "exp overflow in output"; return false;
}
double y = std::exp(z(i)) + comp[i].shift;
```

$y = e^z + d$ の変換前に**オーバーフロー検査**を入れている
($\log(\text{DBL\_MAX}) \approx 709.78$)。切断区間が無限に開いていると
$z$ が大きくなりうるので必要な検査である。

### 5.6 乱数の種

```cpp
unsigned long long jobseed = conf.baseseed + 1000003ULL * (idx + 1);
unsigned int seed = (unsigned int)(jobseed ^ (jobseed >> 32));
std::mt19937 rng(seed);
```

共分散行列ごと (`idx`) に異なる種を作る。1000003 は素数で、
インデックスの近さが種の近さにならないようにしている。
`jobseed ^ (jobseed >> 32)` で上位ビットを 32bit に折り込んでから mt19937 に渡す。

> **注**: `std::mt19937` は 32bit エンジンで、単一の 32bit 値で初期化すると
> 内部状態 (19937 bit) のごく一部の空間しか使わない。
> 実用上の問題は出にくいが、`std::seed_seq` や `mt19937_64` を使うほうが素直である。
> SynthLab の再実装では `std::mt19937_64` を使った。

## 6. 統計量と診断出力

### 6.1 Welford 法 — `updatestat()`

```cpp
stat.count++;
double y = std::exp(z(i)) + comp[i].shift;
double delta = y - stat.meany[i];
stat.meany[i] += delta / stat.count;
double delta2 = y - stat.meany[i];
stat.m2y[i] += delta * delta2;
```

これは Welford のオンライン分散アルゴリズムで、

$$
\bar y_n = \bar y_{n-1} + \frac{y_n - \bar y_{n-1}}{n},
\qquad
M_{2,n} = M_{2,n-1} + (y_n - \bar y_{n-1})(y_n - \bar y_n)
$$

$\operatorname{Var} = M_2/(n-1)$。$\sum y^2 - n\bar y^2$ 方式と違って
**桁落ちしない**。検査値は $\mu$ が大きく $\sigma$ が小さい項目
(例: Na は 141 ± 2) があるので、この選択は実質的に意味がある。

### 6.2 ラグ1自己相関

$z$ については素朴な積和で計算する。

```cpp
stat.sumz[i]  += z(i);
stat.sumz2[i] += z(i)*z(i);
if (stat.count > 1) stat.crossz[i] += stat.prevz[i] * z(i);
stat.prevz[i] = z(i);
```

`writediag()` で

$$
\hat\rho_1 = \frac{\sum_{t=2}^{n} z_t z_{t-1} - (n-1)\bar z^2}
                  {\sum_{t=1}^{n} z_t^2 - n \bar z^2}
$$

として出力する。**これは MCMC の収束診断**である。
$\hat\rho_1$ が 0 に近ければサンプルはほぼ独立で、
`thin` が十分機能していることを意味する。1 に近ければ間引きが足りない。

> 論文 3 の課題「サンプルを単に並べるのではなく平均距離が保たれるよう並べ替える」
> (=自己相関の導入) と、この診断量は同じものを見ている。
> 断面サンプラでは $\hat\rho_1 \approx 0$ が望ましく、
> 時系列生成では $\hat\rho_1 > 0$ が望ましい、という向きの違いがある。

### 6.3 診断ファイル (`*.diag.txt`)

```
index 0
covfile cov1.csv
outfile out/sample1.csv
dim 3
userseed 0
baseseed 1755000000
jobseed 1755001000003
burnin 1000
samples 5000
thin 5
symerr 0
sderr 0
inverr 2.2204460492503131e-16
usedjitter 0
meany,...
vary,...
acorlagonez,...
```

**再現性に必要な情報がすべて入っている**。`userseed = 0` (時刻由来) の場合でも
`baseseed` と `jobseed` が記録されるので、後から同じ結果を再現できる。
よい設計である。

## 7. 出力ファイル

| ファイル | 内容 |
|---|---|
| `out/sampleK.csv` | $y = e^z + d$ (検査値スケール)。`sample,y0,y1,...` |
| `out/sampleK.csv.z.csv` | $z$ (対数スケール)。`writez = true` のとき |
| `out/sampleK.csv.precision.csv` | 精度行列 $Q$。17 桁 |
| `out/sampleK.csv.diag.txt` | 上記の診断 |

すべて `std::setprecision(17)` で書く。倍精度の往復に必要な桁数
($17$ 桁で double が一意に復元できる) を確保している。

## 8. 全体の評価

**優れている点**

1. **数学的な正しさ**: 精度行列を使った条件付き分布は教科書どおりで正しい。
   残差ベクトルの差分更新も定石
2. **裾の数値処理**: $\alpha > 8$ で対数スケールに切り替える設計は、
   切断正規分布のサンプラとして実用上ほぼ必須の配慮。ここを手当てしている実装は多くない
3. **入力検証**: 対称性・SD 整合・逆行列の 3 つを許容誤差つきで検査し、
   診断ファイルに残す。$\sigma$ の二重定義を黙って解決しない判断は正しい
4. **Welford 法**: 検査値の $\mu/\sigma$ 比を考えると実質的な効果がある
5. **再現性**: 種の導出過程を診断に残す

**気づいた点**

1. `std::mt19937` を 32bit 値 1 つで初期化している (5.6 節)。
   `mt19937_64` か `seed_seq` が素直
2. `norminv` の精度が $1.15\times10^{-9}$ で、倍精度の限界ではない (4.5 節)。
   Newton 補正 1 回で $10^{-16}$ 台にできる
3. `makedir()` が `std::system("mkdir -p ...")` を使っている。
   出力先が設定ファイル由来なので、信頼できない設定を食わせるとシェル注入になる。
   `std::filesystem::create_directories` に置き換えるのが安全
   (SynthLab のバックエンドではディレクトリ作成自体を持たせず、
   標準出力に書くだけにした)
4. **時系列生成の経路がない**。論文 3 の時系列機能はこのコードには入っておらず、
   断面サンプラに特化している。`acorlagonez` を計算しているのは、
   時系列への発展を意識した痕跡かもしれない
5. Gibbs は 1 本の連鎖を間引いて使う設計なので、
   **サンプル間の独立性は `thin` の妥当性に依存**する。
   `acorlagonez` を見て `thin` を決める運用が前提になっている

## 9. 相互検証 — 論文実装と SynthLab の再実装を突き合わせる

両方が動く環境になったので、**同一パラメータで生成して統計的に比較**した。

- 入力: 同梱の `component.csv` / `cov1.csv` (3 次元、切断あり)
- 論文実装: `jamia26a` (burnin 1000, thin 5, 5,000 標本)
- 再実装: `backend/synthgen` (患者ごとに独立、relax 25, 5,000 標本)
- さらに **numpy による棄却法**で「真の切断分布」を 200,000 標本作り、
  第三の独立な基準とした (受理率 50%)

### 周辺分布

対数スケールでの平均と SD、および真の切断分布に対する 2 標本 KS 検定。

| 項目 | 棄却法(真) 平均 | 再実装 | 論文実装 | 棄却法(真) SD | 再実装 | 論文実装 |
|---|---|---|---|---|---|---|
| $z_0$ | 0.0341 | 0.0336 | 0.0211 | 0.8741 | 0.8755 | 0.8663 |
| $z_1$ | 1.0641 | 1.0479 | 1.0691 | 1.0162 | 1.0056 | 1.0167 |
| $z_2$ | 0.7898 | 0.7995 | 0.7877 | 0.4955 | 0.5052 | 0.4974 |

| 項目 | 再実装 $D$ | $p$ | 論文実装 $D$ | $p$ |
|---|---|---|---|---|
| $z_0$ | 0.0068 | **0.977** | 0.0113 | **0.560** |
| $z_1$ | 0.0160 | **0.162** | 0.0090 | **0.819** |
| $z_2$ | 0.0133 | **0.351** | 0.0092 | **0.796** |

**どちらも真の切断分布と有意差なし。** 両実装を直接比較した KS 検定でも
$p = 0.50 / 0.23 / 0.28$ で差が出ない。

### 相関 — 切断が相関を減らす

| ペア | 切断前 (指定) | 切断後 (真) | 再実装 | 論文実装 |
|---|---|---|---|---|
| $z_0$–$z_1$ | 0.1333 | 0.0693 | 0.0667 | 0.0742 |
| $z_0$–$z_2$ | 0.1250 | 0.0632 | 0.0403 | 0.0607 |
| $z_1$–$z_2$ | 0.2500 | 0.1134 | 0.1098 | 0.1168 |

指定した相関より実測が小さいのは**切断そのものの効果**である。
矩形領域への切断は相関を弱める。棄却法による真の値が 0.0693 なので、
再実装の 0.0667 も論文実装の 0.0742 も正しい。
**「相関が指定値より低い」を実装のバグと誤診しないために、
この列 (切断後の真値) を持っておく意味がある。**

### 患者間ラグ1自己相関

| 項目 | 再実装 (独立引き) | 論文実装 (単一連鎖 thin=5) |
|---|---|---|
| $z_0$ | +0.0023 | +0.0397 |
| $z_1$ | +0.0031 | −0.0033 |
| $z_2$ | −0.0095 | +0.0389 |

再実装のほうが 0 に近い。断面データを「独立な患者の集まり」として使うなら、
患者ごとに独立に引く設計のほうが素直である
(論文実装は MCMC 標本を出す道具なので、これは優劣ではなく目的の違い)。

**SynthLab のバックエンドとの対応**

`backend/synthgen.cpp` はこのコードの数学的核心
(`sampletruncstd`, `lognormsf`, `logsum`, `logdiff`, `invlogsf`, `norminv`,
精度行列 Gibbs、残差差分更新、ランダム走査) を**そのまま踏襲**し、

- Eigen / libconfig / libcsv への依存を外して自前実装に置換
- 論文 3 の**時系列モード**を追加
- `mt19937_64` に変更
- `std::system` の呼び出しを排除

したものである (→ [07-webapp-spec.md](07-webapp-spec.md))。
