# 元になった論文

本リポジトリの実装は、以下の3本の論文に書かれた手法を再構成したものです。

**PDF 本体はこのリポジトリには含めていません。** 下記の DOI / リンクから入手してください。
解説は [`doc_details/`](../doc_details/) にあり、こちらはすべて本リポジトリの著者が
自分の言葉で書いたものです。

---

## 論文1 — lnorm3 の妥当性

> Masanori Shiro, Rina Kagawa.
> **Validity of Lognormal Distribution in Analyzing Laboratory Test Values.**
> MEDINFO 2021: One World, One Health – Global Partnership for Digital Innovation
> (P. Otero et al., Eds.), Studies in Health Technology and Informatics,
> IOS Press, 2022, pp. 1066–1067.

- DOI: [10.3233/SHTI220270](https://doi.org/10.3233/SHTI220270)
- **Open Access (CC BY-NC 4.0)** — 上記 DOI から無料で入手できます
- 解説: [`doc_details/01-medinfo2022-lognormal.md`](../doc_details/01-medinfo2022-lognormal.md)

11 種類の分布を比較し、3パラメータ対数正規分布 (lnorm3) が検査値分布を
最もよく表すことを示した。基準範囲の両端と中央値の**3つの公表値だけ**から
分布を推定できることも示している。

---

## 論文2 — 血液検査値に偏差値をつける

> 城 真範.
> **血液検査値に偏差値をつける** (教育講演・第34回年次学術集会より).
> 生物試料分析, Vol. 47, No. 4, 2024, pp. 113–121.

- 発行: 生物試料分析科学会
- **再配布の許諾表示がないため、PDF は同梱していません。**
  学会または J-STAGE 等からご参照ください。
- 解説: [`doc_details/02-seibutsu-shiryo-2024.md`](../doc_details/02-seibutsu-shiryo-2024.md)

本手法群の中で最も内容の厚い論文。lnorm3 の性質、偏差値
$T = 50 + 10(\log(x-d)-\mu)/\sigma$、Graphical LASSO による同時分布の構成、
MCV の恒等関係による検証までを扱う。本リポジトリの中心的な根拠。

---

## 論文3 — 時系列データの合成

> Tetsu Furukawa, Rina Kagawa, Masaru Sanuki, Masanori Shiro.
> **A Method of Synthesis of Time Series Data of Blood Test Values.**
> MEDINFO 2025 — Healthcare Smart × Medicine Deep
> (M.S. Househ et al., Eds.), Studies in Health Technology and Informatics,
> IOS Press, 2025, pp. 1854–1855.

- DOI: [10.3233/SHTI251248](https://doi.org/10.3233/SHTI251248)
- **Open Access (CC BY-NC 4.0)** — 上記 DOI から無料で入手できます
- 解説: [`doc_details/03-medinfo2025-timeseries.md`](../doc_details/03-medinfo2025-timeseries.md)

個人内変動と集団変動を分離し、時系列を生成する方法。
$z_{p,t} = \mu + \eta_p + \varepsilon_{p,t}$、$\eta \sim N(0,\Sigma)$、
$\varepsilon \sim N(0, f^2\Sigma)$ の形で、1人の患者を繰り返し観測した系列を作る。

---

## 参考実装について

論文群の著者による C++ 実装 (`code/jamia26a.cpp` ほか) は、
**ライセンスが明示されておらず未公表の可能性があるため、本リポジトリには含めていません。**
読解メモ [`doc_details/04-code-jamia26a.md`](../doc_details/04-code-jamia26a.md) は
本リポジトリの著者が自分の言葉で書いたもので、こちらは残してあります。

本リポジトリ自身の生成器は [`backend/synthgen.cpp`](../backend/synthgen.cpp)、
[`cli/synthlab.py`](../cli/synthlab.py)、[`cli/java/SynthLab.java`](../cli/java/SynthLab.java)
で、いずれも論文の記述から独立に書き起こしたものです。

## なぜ PDF を置かないか

MEDINFO の2本は CC BY-NC 4.0 なので再配布自体は可能ですが、
いずれも DOI から無料で原本が取得でき、同梱する利点がありません。
生物試料分析の1本は再配布の許諾が確認できていません。
いずれにせよ**出版社版が常に一次情報**なので、リンクで案内する方針にしています。
