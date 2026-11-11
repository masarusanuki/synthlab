#!/usr/bin/env python3
"""SynthLab CLI — 擬似血液検査値ジェネレータ (Python 版)

Web 版と同じ手法・同じ入力ファイルを使うコマンドライン版。

================================  入力について  ================================

このツールは **実データを入力しない (できない)**。
入力は次の2種類だけである。

  (A) 生成条件 — コマンドライン引数
      人数、検査項目、性別、受診回数、乱数種など。何をどれだけ作るか。

  (B) パラメータ源 — JSON ファイル 2 つ (--catalog / --correlation)
      data/catalog.json      各検査項目の基準範囲 (下限・上限・中央値)
      data/correlation.json  検査項目間の相関

  実際の患者データは要らないし、渡す口もない。
  (B) の基準範囲 3 つの値から分布を閉じた形で復元し、そこから乱数を引く。
  基準範囲を差し替えれば、別の施設・別の国の分布を作れる。

================================================================================

元になった研究
  [P1] Shiro M., Kagawa R. (2022) Stud Health Technol Inform 290:1066-1067.
  [P2] 城 真範 (2024) 生物試料分析 47(4):113-121.
  [P3] Furukawa T., Kagawa R., Sanuki M., Shiro M. (2025) MEDINFO 2025:1854-1855.

依存: numpy のみ (scipy があれば分位点計算に使う)

使い方:
    python3 synthlab.py --list-items            # 使える検査項目を見る
    python3 synthlab.py --explain               # 入力と手法の説明
    python3 synthlab.py                         # 既定 (1人 x 12回受診)
    python3 synthlab.py --n 500 --mode cross -o out.csv
    python3 synthlab.py --items Hb,AST,TG --deviation
"""

from __future__ import annotations

import argparse
import json
import math
import os
import sys

import numpy as np

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
DEF_CATALOG = os.path.join(ROOT, 'data', 'catalog.json')
DEF_CORR = os.path.join(ROOT, 'data', 'correlation.json')

try:
    from scipy.stats import norm as _sp_norm
    _HAVE_SCIPY = True
except ImportError:
    _HAVE_SCIPY = False


# ---------------------------------------------------------------------------
# 正規分布のユーティリティ
# ---------------------------------------------------------------------------

_A = [-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02,
      1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00]
_B = [-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02,
      6.680131188771972e+01, -1.328068155288572e+01]
_C = [-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00,
      -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00]
_D = [7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00,
      3.754408661907416e+00]


def norm_ppf(p):
    """標準正規の分位点。scipy があればそれを使い、無ければ Acklam 近似。"""
    if _HAVE_SCIPY:
        return _sp_norm.ppf(p)
    p = np.clip(np.asarray(p, dtype=float), 1e-16, 1 - 1e-16)
    out = np.empty_like(p)
    lo, hi = p < 0.02425, p > 1 - 0.02425
    mid = ~(lo | hi)
    q = np.sqrt(-2 * np.log(p[lo]))
    out[lo] = ((((((_C[0] * q + _C[1]) * q + _C[2]) * q + _C[3]) * q + _C[4]) * q + _C[5])
               / ((((_D[0] * q + _D[1]) * q + _D[2]) * q + _D[3]) * q + 1))
    q = np.sqrt(-2 * np.log(1 - p[hi]))
    out[hi] = -((((((_C[0] * q + _C[1]) * q + _C[2]) * q + _C[3]) * q + _C[4]) * q + _C[5])
                / ((((_D[0] * q + _D[1]) * q + _D[2]) * q + _D[3]) * q + 1))
    q = p[mid] - 0.5
    r = q * q
    out[mid] = (((((_A[0] * r + _A[1]) * r + _A[2]) * r + _A[3]) * r + _A[4]) * r + _A[5]) * q \
        / (((((_B[0] * r + _B[1]) * r + _B[2]) * r + _B[3]) * r + _B[4]) * r + 1)
    return out


def norm_cdf(x):
    if _HAVE_SCIPY:
        return _sp_norm.cdf(x)
    return 0.5 * (1.0 + np.vectorize(math.erf)(np.asarray(x, float) / math.sqrt(2.0)))


def _cdf1(x: float) -> float:
    """スカラ版 Phi。Gibbs の内側ループで使うので numpy/scipy を経由しない。"""
    return 0.5 * math.erfc(-x / math.sqrt(2.0))


def _ppf1(p: float) -> float:
    """スカラ版 Phi^{-1} (Acklam 近似)。同上。"""
    if p <= 1e-16:
        p = 1e-16
    elif p >= 1 - 1e-16:
        p = 1 - 1e-16
    if p < 0.02425:
        q = math.sqrt(-2 * math.log(p))
        return ((((((_C[0] * q + _C[1]) * q + _C[2]) * q + _C[3]) * q + _C[4]) * q + _C[5])
                / ((((_D[0] * q + _D[1]) * q + _D[2]) * q + _D[3]) * q + 1))
    if p > 1 - 0.02425:
        q = math.sqrt(-2 * math.log(1 - p))
        return -((((((_C[0] * q + _C[1]) * q + _C[2]) * q + _C[3]) * q + _C[4]) * q + _C[5])
                 / ((((_D[0] * q + _D[1]) * q + _D[2]) * q + _D[3]) * q + 1))
    q = p - 0.5
    r = q * q
    return ((((((_A[0] * r + _A[1]) * r + _A[2]) * r + _A[3]) * r + _A[4]) * r + _A[5]) * q
            / (((((_B[0] * r + _B[1]) * r + _B[2]) * r + _B[3]) * r + _B[4]) * r + 1))


# ---------------------------------------------------------------------------
# lnorm3
# ---------------------------------------------------------------------------

def lnorm3_from_three(L: float, U: float, M: float, z: float) -> tuple[float, float, float]:
    """基準範囲の下限 L・上限 U と中央値 M から (mu, sigma, d) を復元する。

        (L-d)(U-d) = (M-d)^2  から d の1次方程式になり
        d = (L U - M^2) / (L + U - 2M)
        mu = log(M - d),  sigma = log((U-d)/(M-d)) / z
    """
    if not (L < M < U):
        raise ValueError(f'L < M < U が必要: L={L}, M={M}, U={U}')
    den = L + U - 2.0 * M
    degenerate = abs(den) < 1e-12 * max(1.0, abs(L) + abs(U))
    d = M - 20.0 * (U - L) if degenerate else (L * U - M * M) / den
    if not degenerate and d >= L:
        d = L - 20.0 * (U - L)
        degenerate = True
    if degenerate:
        # 退化時は公表値である L, U を厳密に再現する
        mu = (math.log(U - d) + math.log(L - d)) / 2.0
        sigma = (math.log(U - d) - math.log(L - d)) / (2.0 * z)
    else:
        mu = math.log(M - d)
        sigma = math.log((U - d) / (M - d)) / z
    return mu, sigma, d


def deviation_score(x: float, mu: float, sigma: float, d: float):
    """偏差値 T = 50 + 10 (log(x-d) - mu)/sigma (論文2 の主目的)"""
    t = x - d
    if t <= 0 or sigma <= 0:
        return None
    return 50.0 + 10.0 * (math.log(t) - mu) / sigma


# ---------------------------------------------------------------------------
# 相関行列
# ---------------------------------------------------------------------------

def nearest_correlation(R: np.ndarray, eps: float, iters: int = 300) -> np.ndarray:
    """Higham (2002) の交互射影で最も近い相関行列を返す。

    eps は固有値の下限。小さすぎると行列がほぼ特異になり、
    Gibbs の条件付き標準偏差が潰れて連鎖が動かなくなる。
    """
    Y = np.array(R, dtype=float)
    dS = np.zeros_like(Y)
    for _ in range(iters):
        Rk = Y - dS
        w, V = np.linalg.eigh(Rk)
        X = (V * np.maximum(w, eps)) @ V.T
        dS = X - Rk
        Y = X.copy()
        np.fill_diagonal(Y, 1.0)
        if np.min(np.linalg.eigvalsh(Y)) > eps * 0.5:
            break
    return Y


# ---------------------------------------------------------------------------
# サンプリング
# ---------------------------------------------------------------------------

def sample_trunc1(mean, sd, lo, hi, u):
    """1次元の切断正規を逆関数法で引く (棄却なし)。u は一様乱数。"""
    a = (lo - mean) / sd
    b = (hi - mean) / sd
    pa = 0.0 if a == -math.inf else _cdf1(a)
    pb = 1.0 if b == math.inf else _cdf1(b)
    p = pa + u * (pb - pa)
    if p < 1e-16:
        p = 1e-16
    elif p > 1 - 1e-16:
        p = 1 - 1e-16
    v = mean + sd * _ppf1(p)
    return lo if v < lo else (hi if v > hi else v)


def gibbs_sweep(z, Q, mu, lo, hi, r, order, rng, qd=None):
    """切断多変量正規に対する Gibbs を1掃引 (z, r を破壊的に更新)

    精度行列 Q を持つと条件付き分散が 1/Q_ii の定数になり、
    残差 r = Q(z-mu) を持つと条件付き平均が z_i - r_i/Q_ii になる。
    """
    us = rng.uniform(size=len(order))
    for n, i in enumerate(order):
        sdc = qd[i]
        new = sample_trunc1(z[i] - r[i] * sdc * sdc, sdc, lo[i], hi[i], us[n])
        delta = new - z[i]
        z[i] = new
        r += delta * Q[:, i]


def sample_cross(n, mu, Sigma, lo, hi, rng, relax=20):
    """断面データ。患者ごとに独立に引く。

    単一の Gibbs 連鎖の連続掃引を別患者として使ってはいけない
    (相関の強い高次元では隣接標本がほぼ同じ値になる)。
    """
    k = len(mu)
    L = np.linalg.cholesky(Sigma)
    Q = np.linalg.inv(Sigma)
    Q = (Q + Q.T) / 2.0
    qd = 1.0 / np.sqrt(np.diag(Q))      # 条件付き SD は 1/sqrt(Q_ii) で一定
    out = np.empty((n, k))
    order = np.arange(k)
    finite = np.isfinite(lo).any() or np.isfinite(hi).any()
    for p in range(n):
        z = mu + L @ rng.standard_normal(k)
        # 無相関正規に L を掛けた点が切断区間の内側に落ちていれば、
        # それは棄却法が受理した標本そのものなので**厳密に**使える。
        # 切断が ±5σ のときは受理率がほぼ 1 なので、ここでほぼ毎回抜ける。
        if not finite or np.all((z >= lo) & (z <= hi)):
            out[p] = z
            continue
        z = np.clip(z, lo, hi)
        r = Q @ (z - mu)
        for _ in range(relax):
            rng.shuffle(order)
            gibbs_sweep(z, Q, mu, lo, hi, r, order, rng, qd)
        out[p] = z
    return out


def sample_series(n, pts, mu, Sigma, lo, hi, rng, sfrac, noise):
    """時系列。個人の水準の周りだけを動かす (論文3)

        z[p,t] = mu + eta[p] + eps[p,t],  eta~N(0,S), eps~N(0, sfrac^2 S)
    """
    k = len(mu)
    L = np.linalg.cholesky(Sigma)
    Ll = L * sfrac
    sd = np.sqrt(np.diag(Sigma))
    out = np.empty((n, pts, k))
    for p in range(n):
        z0 = np.clip(mu + L @ rng.standard_normal(k), lo, hi)
        for t in range(pts):
            z = z0 + Ll @ rng.standard_normal(k)
            if noise > 0:
                z = z + noise * sd * rng.standard_normal(k)
            out[p, t] = np.clip(z, lo, hi)
    return out


# ---------------------------------------------------------------------------
# カタログの読み込み
# ---------------------------------------------------------------------------

def load_inputs(catalog_path, corr_path):
    with open(catalog_path, encoding='utf-8') as f:
        cat = json.load(f)
    with open(corr_path, encoding='utf-8') as f:
        corr = json.load(f)
    return cat, corr


def three_values(item, sex):
    ref = item['reference']
    if sex in ref:
        v = ref[sex]
        return v['lower'], v['upper'], v['median']
    if 'A' in ref:
        v = ref['A']
        return v['lower'], v['upper'], v['median']
    m, f = ref['M'], ref['F']
    return ((m['lower'] + f['lower']) / 2, (m['upper'] + f['upper']) / 2,
            (m['median'] + f['median']) / 2)


def build(cat, corr, keys, sex, trunc):
    """選択した項目について (mu, sigma, d, lo, hi) と相関行列を作る"""
    z975 = cat.get('_z975', 1.959963985)
    by = {it['key']: it for it in cat['items']}
    mu, sd, dd, lo, hi, meta = [], [], [], [], [], []
    for k in keys:
        it = by[k]
        L, U, M = three_values(it, sex)
        m, s, d = lnorm3_from_three(float(L), float(U), float(M), z975)
        if trunc == 'sd5':
            a, b = m - 5 * s, m + 5 * s
        elif trunc == 'ri':
            a, b = math.log(L - d), math.log(U - d)
        else:
            a, b = -np.inf, np.inf
        if d < 0:                      # 検査値は負にならない
            a = max(a, math.log(-d))
        mu.append(m); sd.append(s); dd.append(d); lo.append(a); hi.append(b)
        meta.append(dict(key=k, label=it['label'], unit=it['unit'],
                         dec=int(it['decimals']), L=float(L), U=float(U), M=float(M),
                         mu=m, sigma=s, d=d))
    mu = np.array(mu); sd = np.array(sd)
    lo = np.array(lo); hi = np.array(hi); dd = np.array(dd)

    pos = {k: i for i, k in enumerate(keys)}
    R = np.eye(len(keys))
    # edges は男女共通の基本エッジ。edges_M / edges_F があれば、その性別では
    # 同じペアを上書きする。赤血球系の相関は論文2 式4 の恒等関係から
    # 男女別の基準範囲だけを入力に導出でき、男女で実際に異なる。
    # (導出: tools/derive-sex-corr.php)
    edge_sets = [corr.get('edges') or []]
    if sex in ('M', 'F'):
        edge_sets.append(corr.get('edges_' + sex) or [])
    for es in edge_sets:
        for e in es:
            if e['a'] in pos and e['b'] in pos:
                R[pos[e['a']], pos[e['b']]] = R[pos[e['b']], pos[e['a']]] = float(e['r'])
    floor = float(corr.get('eigen_floor', 0.02))
    adjusted = False
    if np.min(np.linalg.eigvalsh(R)) < floor:
        R = nearest_correlation(R, floor)
        adjusted = True
    Sigma = R * np.outer(sd, sd)
    return mu, Sigma, lo, hi, dd, meta, adjusted


# ---------------------------------------------------------------------------
# 出力
# ---------------------------------------------------------------------------

def to_values(z, dd, meta):
    y = np.exp(z) + dd
    for j, m in enumerate(meta):
        y[..., j] = np.round(y[..., j], m['dec'])
    return y


def fmt(v, dec):
    return f'{v:.{dec}f}'


def write_csv(out, args, meta, rows, header_comment):
    for line in header_comment:
        print('# ' + line, file=out)
    cols = ['patient_id', 'sex'] + (['visit', 'week'] if args.mode == 'series' else []) \
        + [m['key'] for m in meta]
    print(','.join(cols), file=out)
    for r in rows:
        print(','.join(r), file=out)


def main() -> int:
    ap = argparse.ArgumentParser(
        description='SynthLab CLI — 擬似血液検査値ジェネレータ',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
入力について:
  実データは入力しない (できない)。入力は次の2つだけ。
    (A) 生成条件 … このコマンドライン引数
    (B) パラメータ源 … --catalog / --correlation の JSON ファイル
  詳しくは --explain
""")
    g = ap.add_argument_group('(A) 生成条件')
    g.add_argument('--n', type=int, default=1, metavar='N',
                   help='患者数 (既定 1)。研究は個人に着目しているので既定は1人')
    g.add_argument('--mode', choices=['series', 'cross'], default='series',
                   help='series=時系列 (既定) / cross=断面')
    g.add_argument('--points', type=int, default=12, help='受診回数 (series, 既定 12)')
    g.add_argument('--interval', type=float, default=4.0, help='受診間隔 (週, 既定 4)')
    g.add_argument('--sfrac', type=float, default=0.10,
                   help='個人内変動の幅。sigma に対する比 (既定 0.10)')
    g.add_argument('--noise', type=float, default=0.01, help='測定ノイズ (既定 0.01)')
    g.add_argument('--sex', choices=['M', 'F', 'A'], default='M',
                   help='M=男性 F=女性 A=男女混合 50:50 (既定 M)')
    g.add_argument('--items', default='',
                   help='検査項目をカンマ区切りで。空ならカタログの既定一式')
    g.add_argument('--all-items', action='store_true', help='カタログの全項目を使う')
    g.add_argument('--trunc', choices=['sd5', 'ri', 'none'], default='sd5',
                   help='値域の切断。sd5=±5σ (既定) / ri=基準範囲内 / none=なし')
    g.add_argument('--seed', type=int, default=20260821, help='乱数種 (既定 20260821)')
    g.add_argument('--relax', type=int, default=20, help='断面モードの緩和掃引数')

    g2 = ap.add_argument_group('(B) パラメータ源')
    g2.add_argument('--catalog', default=DEF_CATALOG, help='検査項目カタログ JSON')
    g2.add_argument('--correlation', default=DEF_CORR, help='相関定義 JSON')

    g3 = ap.add_argument_group('出力')
    g3.add_argument('-o', '--out', default='-', help='出力先 (既定 - = 標準出力)')
    g3.add_argument('--format', choices=['csv', 'json'], default='csv')
    g3.add_argument('--deviation', action='store_true',
                    help='偏差値とパーセンタイルの個人票を標準エラーへ出す (1人のとき)')
    g3.add_argument('--no-comment', action='store_true', help='CSV の # コメント行を出さない')

    g4 = ap.add_argument_group('情報')
    g4.add_argument('--list-items', action='store_true', help='使える検査項目を一覧表示')
    g4.add_argument('--explain', action='store_true', help='入力と手法の説明を表示')
    g4.add_argument('--show-params', action='store_true',
                    help='復元した (mu, sigma, d) を標準エラーへ出す')

    args = ap.parse_args()
    cat, corr = load_inputs(args.catalog, args.correlation)

    if args.explain:
        print(__doc__)
        print(f'カタログ      : {args.catalog}')
        print(f'  項目数      : {len(cat["items"])}')
        print(f'  既定の項目  : {len(cat["defaults"])}')
        print(f'  出所        : {cat["_source"]}')
        print(f'相関定義      : {args.correlation}')
        print(f'  エッジ数    : {len(corr["edges"])}')
        print(f'  固有値の床  : {corr["eigen_floor"]}')
        print(f'  出所        : {corr["_source"]}')
        return 0

    by = {it['key']: it for it in cat['items']}
    if args.list_items:
        print(f'{"key":<8}{"単位":<16}{"桁":>3}  {"グループ":<10}{"基準範囲":<26}説明')
        for it in cat['items']:
            ref = it['reference']
            r = ' / '.join(f'{s} {v["lower"]}-{v["upper"]}' for s, v in ref.items())
            print(f'{it["key"]:<8}{it["unit"]:<16}{it["decimals"]:>3}  '
                  f'{it["group"]:<10}{r:<26}{it["label"]}')
        print(f'\n全 {len(cat["items"])} 項目。既定は {len(cat["defaults"])} 項目: '
              + ','.join(cat['defaults']))
        return 0

    # 項目の決定
    if args.all_items:
        keys = [it['key'] for it in cat['items']]
    elif args.items.strip():
        keys = [k.strip() for k in args.items.split(',') if k.strip()]
        bad = [k for k in keys if k not in by]
        if bad:
            print(f'不明な検査項目: {", ".join(bad)}  (--list-items で一覧)', file=sys.stderr)
            return 1
    else:
        keys = list(cat['defaults'])
    if not keys:
        print('検査項目が空です', file=sys.stderr)
        return 1
    if args.n < 1:
        print('--n は 1 以上', file=sys.stderr)
        return 1

    groups = [('M', args.n)] if args.sex == 'M' else \
             [('F', args.n)] if args.sex == 'F' else \
             [('M', (args.n + 1) // 2), ('F', args.n // 2)]
    groups = [g for g in groups if g[1] > 0]

    pts = args.points if args.mode == 'series' else 1
    out = sys.stdout if args.out == '-' else open(args.out, 'w', encoding='utf-8')
    rows = []
    jrows = []
    pid = 0
    meta0 = None
    adjusted_any = False

    for gi, (sex, cnt) in enumerate(groups):
        mu, Sigma, lo, hi, dd, meta, adjusted = build(cat, corr, keys, sex, args.trunc)
        adjusted_any = adjusted_any or adjusted
        if meta0 is None:
            meta0 = meta
        rng = np.random.default_rng(args.seed + 7919 * gi)
        if args.mode == 'cross':
            z = sample_cross(cnt, mu, Sigma, lo, hi, rng, args.relax)
            y = to_values(z, dd, meta)
            for i in range(cnt):
                pid += 1
                vals = [fmt(y[i, j], m['dec']) for j, m in enumerate(meta)]
                rows.append([f'SL{pid:06d}', sex] + vals)
                jrows.append(dict(patient_id=f'SL{pid:06d}', sex=sex,
                                  **{m['key']: float(y[i, j]) for j, m in enumerate(meta)}))
        else:
            z = sample_series(cnt, pts, mu, Sigma, lo, hi, rng, args.sfrac, args.noise)
            y = to_values(z, dd, meta)
            for i in range(cnt):
                pid += 1
                for t in range(pts):
                    vals = [fmt(y[i, t, j], m['dec']) for j, m in enumerate(meta)]
                    rows.append([f'SL{pid:06d}', sex, str(t + 1),
                                 f'{t * args.interval:.2f}'] + vals)
                    jrows.append(dict(patient_id=f'SL{pid:06d}', sex=sex, visit=t + 1,
                                      week=t * args.interval,
                                      **{m['key']: float(y[i, t, j])
                                         for j, m in enumerate(meta)}))
        if args.show_params or args.deviation:
            last_meta = meta
            last_y = y

    comment = []
    if not args.no_comment:
        comment = [
            'SynthLab synthetic laboratory values -- NOT real patient data',
            '架空の数値です。実在の患者データではなく、臨床判断には使用できません。',
            '生成器: lnorm3 (3パラメータ対数正規) + 切断多変量正規サンプリング',
            f'入力(A) 条件: mode={args.mode} n={args.n} points={pts} sex={args.sex} '
            f'trunc={args.trunc} seed={args.seed}'
            + (f' sfrac={args.sfrac} noise={args.noise} interval={args.interval}'
               if args.mode == 'series' else ''),
            f'入力(B) カタログ: {os.path.basename(args.catalog)} / '
            f'相関: {os.path.basename(args.correlation)}'
            + ('  (相関行列は Higham 射影で正定値化した)' if adjusted_any else ''),
            '同じ seed と同じ入力ファイルなら同じデータが再現されます。',
            'item,unit,mu,sigma,d,ref_lower,ref_upper',
        ] + [f'{m["key"]},{m["unit"]},{m["mu"]:.8f},{m["sigma"]:.8f},{m["d"]:.8f},'
             f'{m["L"]},{m["U"]}' for m in meta0]

    if args.format == 'json':
        json.dump({'meta': {'mode': args.mode, 'n': args.n, 'points': pts,
                            'sex': args.sex, 'trunc': args.trunc, 'seed': args.seed,
                            'items': [dict(key=m['key'], unit=m['unit'], mu=m['mu'],
                                           sigma=m['sigma'], d=m['d'],
                                           ref_lower=m['L'], ref_upper=m['U'])
                                      for m in meta0]},
                   'rows': jrows}, out, ensure_ascii=False, indent=1)
        print(file=out)
    else:
        write_csv(out, args, meta0, rows, comment)

    if args.show_params:
        print(f'\n{"項目":<8}{"mu":>10}{"sigma":>10}{"d":>12}{"基準範囲":>20}', file=sys.stderr)
        for m in meta0:
            print(f'{m["key"]:<8}{m["mu"]:>10.4f}{m["sigma"]:>10.4f}{m["d"]:>12.4f}'
                  f'{m["L"]:>10}-{m["U"]:<9}', file=sys.stderr)

    if args.deviation:
        # 個人票 (1人目について)
        print(f'\n個人票 SL000001 ({groups[0][0]})', file=sys.stderr)
        print(f'{"項目":<8}{"値":>10}{"基準範囲":>18}{"偏差値":>9}{"百分位":>9}  判定',
              file=sys.stderr)
        for j, m in enumerate(meta0):
            v = float(np.mean(last_y[0, :, j] if args.mode == 'series' else last_y[0, j]))
            T = deviation_score(v, m['mu'], m['sigma'], m['d'])
            pc = float(norm_cdf((math.log(v - m['d']) - m['mu']) / m['sigma'])) \
                if v > m['d'] else float('nan')
            inri = m['L'] <= v <= m['U']
            pcs = '>99.99%' if pc > 0.9999 else ('<0.01%' if pc < 0.0001 else f'{pc*100:.1f}%')
            print(f'{m["key"]:<8}{fmt(v, m["dec"]):>10}'
                  f'{f"{m[chr(76)]}-{m[chr(85)]}":>18}{T:>9.1f}{pcs:>9}  '
                  f'{"基準範囲内" if inri else ("高値" if v > m["U"] else "低値")}',
                  file=sys.stderr)
        print('\n偏差値 T = 50 + 10 (log(x-d) - mu)/sigma。'
              '基準範囲の下限が約30.4、中央値が50、上限が約69.6。', file=sys.stderr)

    if out is not sys.stdout:
        out.close()
    return 0


if __name__ == '__main__':
    sys.exit(main())
