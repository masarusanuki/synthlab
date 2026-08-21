#!/usr/bin/env python3
"""3つの独立な実装を突き合わせて検証する。

  A) Python 参照実装      python/synthlab_ref.py
  B) C++ 実装             backend/synthgen        (SynthLab のバックエンド)
  C) 論文の実装           code/jamia26a           (要ビルド)
  D) 棄却法による真の分布  numpy (本スクリプト内)

D は「切断多変量正規分布の真の姿」を得るための基準である。
遅いが正しいので、A/B/C の正しさを外部から判定できる。

使い方:
    python3 validate.py
    python3 validate.py --n 20000
"""

from __future__ import annotations

import argparse
import os
import subprocess
import sys

import numpy as np
from scipy import stats

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import synthlab_ref as ref                                          # noqa: E402

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
CPP_BACKEND = os.path.join(ROOT, 'backend', 'synthgen')
CPP_PAPER = os.path.join(ROOT, 'code', 'jamia26a')

# code/component.csv と code/cov1.csv と同じ設定 (論文実装と比較するため)
MU = np.array([0.0, 1.0, 0.5])
SD = np.array([1.0, 1.5, 0.8])
DSHIFT = np.array([0.0, 10.0, -3.0])
LO = np.array([-2.0, -1.0, 0.0])
HI = np.array([2.0, 3.0, 2.0])
SIGMA = np.array([[1.00, 0.20, 0.10],
                  [0.20, 2.25, 0.30],
                  [0.10, 0.30, 0.64]])


def rejection_reference(n: int, rng) -> np.ndarray:
    """棄却法で切断多変量正規分布の「真の」標本を作る。"""
    keep, got = [], 0
    while got < n:
        x = rng.multivariate_normal(MU, SIGMA, size=max(n, 100000))
        m = np.all((x >= LO) & (x <= HI), axis=1)
        keep.append(x[m])
        got += int(m.sum())
    return np.vstack(keep)[:n]


def run_cpp_backend(n: int, seed: int) -> np.ndarray | None:
    """C++ バックエンドを同じ設定で走らせて対数スケールの標本を返す。"""
    if not os.access(CPP_BACKEND, os.X_OK):
        return None
    R = SIGMA / np.outer(SD, SD)
    spec = [f'seed {seed}', f'npat {n}', 'mode cross', 'relax 25', 'randomscan 1']
    for j, nm in enumerate(['y0', 'y1', 'y2']):
        spec.append(f'item {nm} u {MU[j]} {SD[j]} {DSHIFT[j]} {LO[j]} {HI[j]} 8')
    for i in range(3):
        spec.append('corr ' + ' '.join(f'{R[i, j]:.15g}' for j in range(3)))
    p = subprocess.run([CPP_BACKEND], input='\n'.join(spec) + '\n',
                       capture_output=True, text=True, timeout=120)
    if p.returncode != 0:
        print('  C++ バックエンドが失敗:', p.stderr.strip()[:200], file=sys.stderr)
        return None
    rows = []
    for ln in p.stdout.splitlines()[1:]:
        f = ln.split(',')
        if len(f) >= 4:
            rows.append([float(f[1]), float(f[2]), float(f[3])])
    return np.log(np.array(rows) - DSHIFT)


def read_paper_output() -> np.ndarray | None:
    """論文実装の出力 (code/out/sample1.csv) を読む。"""
    path = os.path.join(ROOT, 'code', 'out', 'sample1.csv')
    if not os.path.exists(path):
        return None
    rows = []
    with open(path) as f:
        f.readline()
        for ln in f:
            p = ln.strip().split(',')
            if len(p) >= 4:
                rows.append([float(p[1]), float(p[2]), float(p[3])])
    return np.log(np.array(rows) - DSHIFT)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('--n', type=int, default=5000, help='各実装の標本数')
    ap.add_argument('--nref', type=int, default=200000, help='棄却法の標本数')
    ap.add_argument('--seed', type=int, default=20260821)
    args = ap.parse_args()
    rng = np.random.default_rng(args.seed)

    print('=== 検証設定 (code/component.csv + code/cov1.csv と同一) ===')
    print(f'  mu    = {MU}')
    print(f'  sd    = {SD}')
    print(f'  d     = {DSHIFT}')
    print(f'  切断  = {list(zip(LO, HI))}')
    print()

    print('D) 棄却法で真の分布を作る ...', end=' ', flush=True)
    Z = rejection_reference(args.nref, rng)
    print(f'{len(Z)} 標本')

    print('A) Python 参照実装 ...', end=' ', flush=True)
    ZA = ref.sample_truncated_mvn(args.n, MU, SIGMA, LO, HI,
                                  np.random.default_rng(args.seed + 1), relax=25)
    print(f'{len(ZA)} 標本')

    print('B) C++ バックエンド ...', end=' ', flush=True)
    ZB = run_cpp_backend(args.n, args.seed)
    print(f'{len(ZB)} 標本' if ZB is not None else 'なし (未ビルド)')

    print('C) 論文実装の出力 ...', end=' ', flush=True)
    ZC = read_paper_output()
    print(f'{len(ZC)} 標本' if ZC is not None else
          'なし (code/ で ./jamia26a jamia26a.cfg を実行してください)')
    print()

    impls = [('Python', ZA)]
    if ZB is not None:
        impls.append(('C++', ZB))
    if ZC is not None:
        impls.append(('論文', ZC))

    names = ['z0', 'z1', 'z2']
    print('=== 周辺分布: 平均 / SD ===')
    hdr = f"{'項目':<5}{'棄却法':>10}" + ''.join(f'{n:>10}' for n, _ in impls)
    print(hdr)
    for i, n in enumerate(names):
        print(f'{n:<5}{Z[:, i].mean():>10.4f}' +
              ''.join(f'{d[:, i].mean():>10.4f}' for _, d in impls))
    print()
    for i, n in enumerate(names):
        print(f'{n:<5}{Z[:, i].std():>10.4f}' +
              ''.join(f'{d[:, i].std():>10.4f}' for _, d in impls))

    print('\n=== KS 検定 (真の分布との比較。p > 0.01 で有意差なし) ===')
    print(f"{'項目':<5}" + ''.join(f'{n + " D":>10}{"p":>8}' for n, _ in impls))
    allok = True
    for i, n in enumerate(names):
        line = f'{n:<5}'
        for _, d in impls:
            k = stats.ks_2samp(d[:, i], Z[:, i])
            line += f'{k.statistic:>10.4f}{k.pvalue:>8.3f}'
            if k.pvalue <= 0.01:
                allok = False
        print(line)

    print('\n=== 相関 (対数スケール) ===')
    Rspec = SIGMA / np.outer(SD, SD)
    Rt = np.corrcoef(Z.T)
    print(f"{'ペア':<8}{'指定(切断前)':>13}{'真(切断後)':>12}" +
          ''.join(f'{n:>10}' for n, _ in impls))
    for i, j, lbl in [(0, 1, 'z0-z1'), (0, 2, 'z0-z2'), (1, 2, 'z1-z2')]:
        line = f'{lbl:<8}{Rspec[i, j]:>13.4f}{Rt[i, j]:>12.4f}'
        for _, d in impls:
            line += f'{np.corrcoef(d.T)[i, j]:>10.4f}'
        print(line)
    print('  ※ 指定値より小さくなるのは切断そのものの効果。'
          '「真(切断後)」が正解なので、それに近ければ正しい。')

    print('\n=== 個体間ラグ1自己相関 (独立なら 0 付近) ===')
    print(f"{'項目':<5}" + ''.join(f'{n:>10}' for n, _ in impls))
    for i, n in enumerate(names):
        line = f'{n:<5}'
        for _, d in impls:
            line += f'{np.corrcoef(d[:-1, i], d[1:, i])[0, 1]:>10.4f}'
        print(line)

    print('\n=== 切断区間の遵守 ===')
    for i, n in enumerate(names):
        bad = sum(int(np.any(d[:, i] < LO[i] - 1e-9) or np.any(d[:, i] > HI[i] + 1e-9))
                  for _, d in impls)
        print(f'  {n}: 指定 [{LO[i]}, {HI[i]}]  違反のある実装 {bad} 件')
        if bad:
            allok = False

    print('\n=== パラメータ復元の検算 ===')
    err = 0.0
    for it in ref.DEMO_ITEMS:
        err = max(err, abs(it.dist.quantile(0.025) - it.L) / it.L,
                  abs(it.dist.quantile(0.975) - it.U) / it.U)
    print(f'  基準範囲の最大相対誤差: {err:.2e}  '
          f'{"OK" if err < 1e-6 else "NG"}')
    if err >= 1e-6:
        allok = False

    print('\n' + ('すべての検証に通りました。' if allok else '通らなかった検証があります。'))
    return 0 if allok else 1


if __name__ == '__main__':
    sys.exit(main())
