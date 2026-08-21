---
title: SynthLab 技術解説 — 総目次
order: 0
---

# SynthLab 技術解説

`paper/` に収録された4本の論文と、`code/jamia26a.cpp` の実装を数式レベルで解説する。
あわせて、この解説を書く過程で構成した擬似検査値生成ツール (SynthLab) の
アルゴリズムと、Synthea との設計思想の違いを整理する。

## この文書群の構成

| 番号 | 文書 | 内容 |
|---|---|---|
| 01 | [MEDINFO 2022: 対数正規分布の妥当性](01-medinfo2022-lognormal.md) | 12種の統計分布を横断比較し lnorm3 を選定した研究 |
| 02 | [生物試料分析 2024: 血液検査値に偏差値をつける](02-seibutsu-shiryo-2024.md) | 研究1〜3。分布選定、同時分布 (GLASSO)、公開データからの再構成 |
| 03 | [MEDINFO 2025: 時系列合成法](03-medinfo2025-timeseries.md) | Cholesky 分解 + 逆関数法による高速時系列生成 |
| 04 | [コード解説: jamia26a.cpp](04-code-jamia26a.md) | 切断多変量正規分布に対する Gibbs サンプラの実装 |
| 05 | [アルゴリズム数式解説](05-algorithm.md) | 論文とコードを貫く数理の統一的な導出 |
| 06 | [Synthea との違い](06-synthea-comparison.md) | 設計思想・生成機序・評価軸の比較 (AI による解釈) |
| 07 | [SynthLab 実装仕様](07-webapp-spec.md) | 公開ツールの構成、パラメータ復元、疾患モデル |
| 08 | [開発日記](08-devdiary.md) | 実装中に判明した事実と判断の記録 |

## 対象論文

1. **Shiro M., Kagawa R.** (2022) *Validity of Lognormal Distribution in Analyzing
   Laboratory Test Values.* Stud Health Technol Inform 290:1066–1067.
   doi:10.3233/SHTI220270 — `paper/medinfo22.pdf`

2. **城 真範** (2024) *血液検査値に偏差値をつける.* 生物試料分析 47(4):113–121.
   〈教育講演・第34回年次学術集会より〉 — `paper/生物試料.pdf`

3. **Furukawa T., Kagawa R., Sanuki M., Shiro M.** (2025) *A Method of Synthesis of
   Time Series Data of Blood Test Values.* Stud Health Technol Inform (MEDINFO 2025)
   1854–1855. doi:10.3233/SHTI251248 — `paper/medinfo25-paper.pdf`

4. 同 3 の投稿原稿 (詳細版) — `paper/medinfo2025.docx`
   ※ 刊行版は 2 ページに圧縮されているため、手法の記述は docx 版のほうが詳しい。
   本解説では両者を突き合わせて読んでいる。

## 全体を貫く一本の筋

4本の論文と1本のコードは、次の一直線上にある。

$$
\underbrace{\text{lnorm3 が最適}}_{\text{論文 1}}
\;\longrightarrow\;
\underbrace{\log(x-d) \text{ が正規} \Rightarrow \text{分散共分散行列 } \Sigma \text{ が使える}}_{\text{論文 2}}
\;\longrightarrow\;
\underbrace{\Sigma = LL^{\mathsf T} \text{ で相関つきサンプリング}}_{\text{論文 3}}
\;\longrightarrow\;
\underbrace{\text{切断つき同時分布から厳密に Gibbs}}_{\text{コード}}
$$

要点は「**3パラメータ対数正規分布の $(\mu,\sigma)$ が正規分布の $(\mu,\sigma)$ に
完全に対応する**」という一点である。これが成り立つために、

- 偏差値 (正規分布の累積分布関数) が計算できる (論文 1・2 の動機)
- Graphical LASSO が適用できる (正規分布を前提とする手法なので / 論文 2)
- Cholesky 分解による相関つきサンプリングができる (論文 3)
- 切断多変量正規分布の条件付き分布が閉じた形で書ける (コード)

という4つの恩恵がすべて同時に得られる。市原らの修正 Box-Cox 変換
(4パラメータ) ではこの対応が崩れるため、これらが使えない。
そこが論文 1 の主張の核心である。
