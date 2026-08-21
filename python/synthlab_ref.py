#!/usr/bin/env python3
"""SynthLab 参照実装 (Python / numpy)

論文の手法を numpy で素直に書き直した参照実装。
C++ 版 (backend/synthgen.cpp) と同じ結果を出すことを目的とし、
読みやすさを優先している。速度が必要な場面では C++ 版を使う。

元になった研究
  [P1] Shiro M., Kagawa R. (2022) Validity of Lognormal Distribution in
       Analyzing Laboratory Test Values. Stud Health Technol Inform 290:1066-1067.
  [P2] 城 真範 (2024) 血液検査値に偏差値をつける. 生物試料分析 47(4):113-121.
  [P3] Furukawa T., Kagawa R., Sanuki M., Shiro M. (2025) A Method of Synthesis
       of Time Series Data of Blood Test Values. MEDINFO 2025:1854-1855.
  [C]  code/jamia26a.cpp  切断多変量正規分布に対する Gibbs サンプラ

依存: numpy (scipy は任意。あれば分位点計算に使う)

使い方の例:
    python3 synthlab_ref.py --demo
    python3 synthlab_ref.py --cross 1000 --seed 42 > out.csv
"""

from __future__ import annotations

import argparse
import math
import sys
from dataclasses import dataclass, field

import numpy as np

try:
    from scipy.stats import norm as _norm
    _HAVE_SCIPY = True
except ImportError:                                     # scipy が無くても動く
    _HAVE_SCIPY = False

Z975 = 1.959963985                                      # 標準正規の 97.5% 点


# ---------------------------------------------------------------------------
# 1. lnorm3 — 3パラメータ対数正規分布
# ---------------------------------------------------------------------------

@dataclass
class Lnorm3:
    """3パラメータ対数正規分布 lnorm3(mu, sigma, d)。

    X = d + exp(Z),  Z ~ N(mu, sigma^2)   ... [P2] 式3

    d は台の左端を決めるだけで分布の形には影響しない。
    したがって d を引いて対数をとれば、あとは正規分布の話になる。
    これが本手法のすべての土台である。
    """

    mu: float
    sigma: float
    d: float

    def mean(self) -> float:
        """期待値 E[X] = exp(mu + sigma^2/2) + d   ([P2] 式5)"""
        return math.exp(self.mu + self.sigma ** 2 / 2.0) + self.d

    def median(self) -> float:
        return math.exp(self.mu) + self.d

    def var(self) -> float:
        s2 = self.sigma ** 2
        return (math.exp(s2) - 1.0) * math.exp(2 * self.mu + s2)

    def quantile(self, p: float) -> float:
        """Q(p) = d + exp(mu + sigma * Phi^{-1}(p))"""
        return self.d + math.exp(self.mu + self.sigma * _ppf(p))

    def pdf(self, x: np.ndarray) -> np.ndarray:
        t = np.asarray(x, dtype=float) - self.d
        out = np.zeros_like(t)
        ok = t > 0
        z = (np.log(t[ok]) - self.mu) / self.sigma
        out[ok] = np.exp(-0.5 * z * z) / (math.sqrt(2 * math.pi) * self.sigma * t[ok])
        return out

    def deviation_score(self, x: float) -> float:
        """偏差値 T = 50 + 10 (log(x-d) - mu) / sigma。数値積分は不要。"""
        return 50.0 + 10.0 * (math.log(x - self.d) - self.mu) / self.sigma


def _ppf(p: float | np.ndarray):
    """標準正規の分位点。scipy があればそれを使う。"""
    if _HAVE_SCIPY:
        return _norm.ppf(p)
    # Acklam 近似 (C++ 版と同じ係数)
    return _acklam_ppf(np.asarray(p, dtype=float))


def _acklam_ppf(p: np.ndarray) -> np.ndarray:
    a = [-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02,
         1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00]
    b = [-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02,
         6.680131188771972e+01, -1.328068155288572e+01]
    c = [-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00,
         -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00]
    d = [7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00,
         3.754408661907416e+00]
    p = np.clip(np.asarray(p, dtype=float), 1e-16, 1 - 1e-16)
    out = np.empty_like(p)
    lo, hi = p < 0.02425, p > 1 - 0.02425
    mid = ~(lo | hi)

    q = np.sqrt(-2 * np.log(p[lo]))
    out[lo] = (((((c[0] * q + c[1]) * q + c[2]) * q + c[3]) * q + c[4]) * q + c[5]) / \
              ((((d[0] * q + d[1]) * q + d[2]) * q + d[3]) * q + 1)
    q = np.sqrt(-2 * np.log(1 - p[hi]))
    out[hi] = -(((((c[0] * q + c[1]) * q + c[2]) * q + c[3]) * q + c[4]) * q + c[5]) / \
               ((((d[0] * q + d[1]) * q + d[2]) * q + d[3]) * q + 1)
    q = p[mid] - 0.5
    r = q * q
    out[mid] = (((((a[0] * r + a[1]) * r + a[2]) * r + a[3]) * r + a[4]) * r + a[5]) * q / \
               (((((b[0] * r + b[1]) * r + b[2]) * r + b[3]) * r + b[4]) * r + 1)
    return out


def _cdf(x):
    if _HAVE_SCIPY:
        return _norm.cdf(x)
    from scipy.special import erfc  # pragma: no cover
    return 0.5 * erfc(-np.asarray(x) / math.sqrt(2.0))


# ---------------------------------------------------------------------------
# 2. 三つの値からのパラメータ復元 (閉じた解)
# ---------------------------------------------------------------------------

def lnorm3_from_three(L: float, U: float, M: float, z: float = Z975) -> Lnorm3:
    """基準範囲の下限 L・上限 U と中央値 M から (mu, sigma, d) を復元する。

    [P2] は「平均値と基準範囲だけが示されていれば分布が完全に推定できる」と
    述べているが閉じた解は書かれていない。導出:

        M - d = e^mu,  L - d = e^{mu - z sigma},  U - d = e^{mu + z sigma}

    後ろ2つを掛けると e^{2mu} で、1つ目の平方と等しいので

        (L-d)(U-d) = (M-d)^2

    展開すると d^2 が消えて d の1次方程式になる:

        d = (L U - M^2) / (L + U - 2M)
        mu = log(M - d)
        sigma = log((U-d)/(M-d)) / z

    d の符号には意味がある。M = sqrt(L U) のとき d = 0 で、
    これは通常の2パラメータ対数正規分布に一致する。

    退化する場合 (L+U = 2M、または d >= L となる左歪みの場合) は
    d を十分下に固定し、公表値である L, U を厳密に再現するよう mu, sigma を決める。
    """
    if not (L < M < U):
        raise ValueError(f"L < M < U が必要: L={L}, M={M}, U={U}")
    den = L + U - 2.0 * M
    degenerate = abs(den) < 1e-12 * max(1.0, abs(L) + abs(U))
    d = M - 20.0 * (U - L) if degenerate else (L * U - M * M) / den
    if not degenerate and d >= L:
        d = L - 20.0 * (U - L)
        degenerate = True
    if degenerate:
        mu = (math.log(U - d) + math.log(L - d)) / 2.0
        sigma = (math.log(U - d) - math.log(L - d)) / (2.0 * z)
    else:
        mu = math.log(M - d)
        sigma = math.log((U - d) / (M - d)) / z
    if not (sigma > 0) or not math.isfinite(mu):
        raise ValueError("パラメータを復元できませんでした")
    return Lnorm3(mu=mu, sigma=sigma, d=d)


# ---------------------------------------------------------------------------
# 3. 相関行列 — 正定値化 (Higham の最近相関行列)
# ---------------------------------------------------------------------------

def nearest_correlation(R: np.ndarray, eps: float = 0.02, iters: int = 300) -> np.ndarray:
    """Higham (2002) の交互射影法で最も近い相関行列を返す。

    手で並べた相関はしばしば正定値にならない。とくに赤血球系は
    log Ht = log RBC + log MCV + const のように対数スケールで線形なので、
    全ペアを独立に指定すると矛盾する ([P2] 式4 の対数版)。

    eps は固有値の下限。ここを 1e-8 のように小さくすると行列がほぼ特異になり、
    Gibbs の条件付き標準偏差 (>= sqrt(eps) * sigma) が潰れて連鎖が動かなくなる。
    0.02 なら条件付き SD の下限が 0.14 sigma 程度確保され、
    相関の劣化もごく小さい。
    """
    n = R.shape[0]
    Y = np.array(R, dtype=float)
    dS = np.zeros_like(Y)
    for _ in range(iters):
        Rk = Y - dS
        w, V = np.linalg.eigh(Rk)              # 対称行列なので eigh
        X = (V * np.maximum(w, eps)) @ V.T     # 固有値をクリップして半正定値へ
        dS = X - Rk
        Y = X.copy()
        np.fill_diagonal(Y, 1.0)               # 対角を 1 に戻す
        if np.all(np.linalg.eigvalsh(Y) > eps * 0.5):
            break
    return Y


def build_covariance(sigmas: np.ndarray, R: np.ndarray) -> np.ndarray:
    """相関行列と周辺 SD から分散共分散行列を作る。"""
    return R * np.outer(sigmas, sigmas)


# ---------------------------------------------------------------------------
# 4. 切断多変量正規分布のサンプリング
# ---------------------------------------------------------------------------

def sample_truncnorm_1d(mean: np.ndarray, sd: np.ndarray,
                        lo: np.ndarray, hi: np.ndarray,
                        rng: np.random.Generator) -> np.ndarray:
    """1次元の切断正規分布から逆関数法で引く (棄却なし)。

    z = Phi^{-1}( Phi(alpha) + u [Phi(beta) - Phi(alpha)] )

    裾では Phi(alpha) -> 1 で桁落ちするため、C++ 版は対数スケールに
    切り替えている。ここでは scipy があれば十分な精度が出るので
    素直な式を使い、区間へのクリップで保険をかける。
    """
    alpha = (lo - mean) / sd
    beta = (hi - mean) / sd
    pa = np.where(np.isneginf(alpha), 0.0, _cdf(alpha))
    pb = np.where(np.isposinf(beta), 1.0, _cdf(beta))
    u = rng.uniform(size=mean.shape)
    p = np.clip(pa + u * (pb - pa), 1e-16, 1 - 1e-16)
    z = mean + sd * _ppf(p)
    return np.clip(z, lo, hi)


def gibbs_sweep(z: np.ndarray, Q: np.ndarray, mu: np.ndarray,
                lo: np.ndarray, hi: np.ndarray,
                r: np.ndarray, order: np.ndarray,
                rng: np.random.Generator) -> None:
    """切断多変量正規分布に対する Gibbs を1掃引 (z と r を破壊的に更新)。

    精度行列 Q = Sigma^{-1} を持っていると、条件付き分布が

        z_i | z_-i ~ TN( mu_i - (1/Q_ii) sum_{j!=i} Q_ij (z_j - mu_j),
                         1/Q_ii, a_i, b_i )

    という1次元の切断正規分布になる。条件付き分散が 1/Q_ii の定数になるのが要点。

    残差 r = Q(z - mu) を持っておくと条件付き平均が

        m_i = z_i - r_i / Q_ii

    という簡潔な形になり、z_i を delta 動かしたときの r の更新は
    r += delta * Q[:, i] で済む (1成分 O(n))。
    """
    for i in order:
        qii = Q[i, i]
        sd = 1.0 / math.sqrt(qii)
        m = z[i] - r[i] / qii
        new = float(sample_truncnorm_1d(np.array([m]), np.array([sd]),
                                        np.array([lo[i]]), np.array([hi[i]]), rng)[0])
        delta = new - z[i]
        z[i] = new
        r += delta * Q[:, i]


def sample_truncated_mvn(n_samples: int, mu: np.ndarray, Sigma: np.ndarray,
                         lo: np.ndarray, hi: np.ndarray,
                         rng: np.random.Generator, relax: int = 20) -> np.ndarray:
    """切断多変量正規分布から「独立な」標本を n_samples 個引く。

    単一の Gibbs 連鎖の連続掃引を別個体として使ってはいけない。
    相関の強い高次元では隣接標本がほぼ同じ値になる。

    そこで個体ごとに
      1. Cholesky で無相関正規から z = mu + L w を作る
         (切断がなければこれは厳密な標本で、個体間は完全に独立)
      2. 切断区間へ射影
      3. Gibbs を relax 回まわして切断つき分布へ緩和
    とする。1 が独立性を、3 が切断の厳密性を担う。
    """
    n = len(mu)
    L = np.linalg.cholesky(Sigma)
    Q = np.linalg.inv(Sigma)
    Q = (Q + Q.T) / 2.0
    out = np.empty((n_samples, n))
    order = np.arange(n)
    for k in range(n_samples):
        w = rng.standard_normal(n)
        z = mu + L @ w
        z = np.clip(z, lo, hi)
        r = Q @ (z - mu)
        for _ in range(relax):
            rng.shuffle(order)                 # ランダム走査 (詳細釣り合いを満たす)
            gibbs_sweep(z, Q, mu, lo, hi, r, order, rng)
        out[k] = z
    return out


# ---------------------------------------------------------------------------
# 5. 時系列 ([P3])
# ---------------------------------------------------------------------------

def sample_series(n_patients: int, n_points: int, mu: np.ndarray, Sigma: np.ndarray,
                  lo: np.ndarray, hi: np.ndarray, rng: np.random.Generator,
                  sfrac: float = 0.10, noise: float = 0.01) -> np.ndarray:
    """個人内変動を抑えた時系列を作る ([P3])。

        z[p,t] = mu + eta[p] + eps[p,t]
        eta ~ N(0, Sigma),  eps ~ N(0, sfrac^2 Sigma)

    個人に着目すると検査値は分布のごく一部しか動かない、という観察に基づく。
    集団分布から毎回独立に引くと同じ人の値が分布全体を飛び回ってしまう。

    返り値の形は (n_patients, n_points, n_items)。

    注意: eps は t について独立なので自己相関がない。
    論文自身が課題として挙げている点で、定常 AR(1) にすれば
    周辺分布を変えずに自己相関を入れられる:
        eps[t] = rho eps[t-1] + sqrt(1-rho^2) * sfrac L w[t]
    実データから rho を決める根拠がないため、ここでは実装していない。
    """
    n = len(mu)
    L = np.linalg.cholesky(Sigma)
    Ll = L * sfrac
    out = np.empty((n_patients, n_points, n))
    for p in range(n_patients):
        z0 = np.clip(mu + L @ rng.standard_normal(n), lo, hi)
        for t in range(n_points):
            z = z0 + Ll @ rng.standard_normal(n)
            if noise > 0:
                z = z + noise * np.sqrt(np.diag(Sigma)) * rng.standard_normal(n)
            out[p, t] = np.clip(z, lo, hi)
    return out


# ---------------------------------------------------------------------------
# 6. 検査項目のまとめ
# ---------------------------------------------------------------------------

@dataclass
class Item:
    """1検査項目。基準範囲 (L, U) と中央値 M から lnorm3 を復元して持つ。"""
    name: str
    unit: str
    L: float
    U: float
    M: float
    dec: int = 2
    dist: Lnorm3 = field(init=False)

    def __post_init__(self) -> None:
        self.dist = lnorm3_from_three(self.L, self.U, self.M)


def to_values(z: np.ndarray, items: list[Item]) -> np.ndarray:
    """対数スケール z を検査値 y = exp(z) + d に戻して丸める。"""
    d = np.array([it.dist.d for it in items])
    y = np.exp(z) + d
    for j, it in enumerate(items):
        y[..., j] = np.round(y[..., j], it.dec)
    return y


def truncation_window(items: list[Item], k: float = 5.0):
    """±k sigma の切断区間 (対数スケール)。

    検査値は負にならないので、d < 0 の項目には y >= 0 すなわち
    z >= log(-d) も課す。CRP のように 0 近傍に張り付く項目で必要。
    """
    lo = np.array([it.dist.mu - k * it.dist.sigma for it in items])
    hi = np.array([it.dist.mu + k * it.dist.sigma for it in items])
    for j, it in enumerate(items):
        if it.dist.d < 0:
            lo[j] = max(lo[j], math.log(-it.dist.d))
    return lo, hi


# ---------------------------------------------------------------------------
# 7. デモ
# ---------------------------------------------------------------------------

DEMO_ITEMS = [
    Item('Hb',    'g/dL',  13.7, 16.8, 15.1, 1),
    Item('Ht',    '%',     40.7, 50.1, 45.2, 1),
    Item('AST',   'U/L',   13.0, 30.0, 20.0, 0),
    Item('ALT',   'U/L',   10.0, 42.0, 19.0, 0),
    Item('CRE',   'mg/dL', 0.65, 1.07, 0.83, 2),
    Item('eGFR',  'mL/min/1.73m^2', 55.0, 110.0, 78.0, 1),
    Item('Glu',   'mg/dL', 73.0, 109.0, 89.0, 0),
    Item('HbA1c', '%',     4.9,  6.0,  5.4,  1),
    Item('TG',    'mg/dL', 40.0, 234.0, 100.0, 0),
    Item('HDL',   'mg/dL', 38.0, 90.0, 57.0, 0),
]

# [P2] の議論で名前が挙がっている関係を骨格にした相関 (対数スケール)
DEMO_EDGES = [
    ('Hb', 'Ht', 0.95), ('AST', 'ALT', 0.80), ('CRE', 'eGFR', -0.85),
    ('Glu', 'HbA1c', 0.70), ('TG', 'HDL', -0.45), ('Glu', 'TG', 0.25),
    ('Hb', 'eGFR', 0.25), ('TG', 'ALT', 0.30),
]


def demo_correlation(items: list[Item], edges) -> np.ndarray:
    idx = {it.name: j for j, it in enumerate(items)}
    R = np.eye(len(items))
    for a, b, v in edges:
        if a in idx and b in idx:
            R[idx[a], idx[b]] = R[idx[b], idx[a]] = v
    if np.min(np.linalg.eigvalsh(R)) < 0.02:
        R = nearest_correlation(R)
    return R


def main() -> int:
    ap = argparse.ArgumentParser(description='SynthLab 参照実装 (Python)')
    ap.add_argument('--demo', action='store_true', help='パラメータ復元の確認を表示')
    ap.add_argument('--cross', type=int, metavar='N', help='断面データ N 件を CSV 出力')
    ap.add_argument('--series', nargs=2, type=int, metavar=('P', 'T'),
                    help='時系列 (P 人 x T 回) を CSV 出力')
    ap.add_argument('--seed', type=int, default=20260821)
    ap.add_argument('--sfrac', type=float, default=0.10)
    args = ap.parse_args()

    items = DEMO_ITEMS
    R = demo_correlation(items, DEMO_EDGES)
    mu = np.array([it.dist.mu for it in items])
    sd = np.array([it.dist.sigma for it in items])
    Sigma = build_covariance(sd, R)
    lo, hi = truncation_window(items)
    rng = np.random.default_rng(args.seed)

    if args.demo:
        print('項目   単位            基準範囲        中央値      mu      sigma        d'
              '     期待値   Q(.025)   Q(.975)')
        for it in items:
            dd = it.dist
            print(f'{it.name:<6} {it.unit:<14} {it.L:>6}-{it.U:<7} {it.M:>7} '
                  f'{dd.mu:>8.4f} {dd.sigma:>8.4f} {dd.d:>9.3f} '
                  f'{dd.mean():>9.3f} {dd.quantile(0.025):>9.3f} {dd.quantile(0.975):>9.3f}')
        print()
        err = max(abs(it.dist.quantile(0.025) - it.L) / it.L for it in items)
        print(f'基準範囲下限の最大相対誤差: {err:.2e}')
        err = max(abs(it.dist.quantile(0.975) - it.U) / it.U for it in items)
        print(f'基準範囲上限の最大相対誤差: {err:.2e}')
        print(f'相関行列の最小固有値: {np.min(np.linalg.eigvalsh(R)):.4f}')
        return 0

    if args.cross:
        z = sample_truncated_mvn(args.cross, mu, Sigma, lo, hi, rng)
        y = to_values(z, items)
        print('patient_id,' + ','.join(it.name for it in items))
        for k, row in enumerate(y, 1):
            print(f'SL{k:06d},' + ','.join(
                f'{v:.{it.dec}f}' for v, it in zip(row, items)))
        return 0

    if args.series:
        P, T = args.series
        z = sample_series(P, T, mu, Sigma, lo, hi, rng, sfrac=args.sfrac)
        y = to_values(z, items)
        print('patient_id,visit,week,' + ','.join(it.name for it in items))
        for p in range(P):
            for t in range(T):
                print(f'SL{p+1:06d},{t+1},{t*4:.2f},' + ','.join(
                    f'{v:.{it.dec}f}' for v, it in zip(y[p, t], items)))
        return 0

    ap.print_help()
    return 1


if __name__ == '__main__':
    sys.exit(main())
