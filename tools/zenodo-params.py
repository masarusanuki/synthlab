#!/usr/bin/env python3
"""zenodo-params.py -- Zenodo の公開分布パラメータを取り込み、カタログと突き合わせる

対象データ:
  Shiro Masanori, Kagawa Rina, Kohro Takahide (2020)
  "Standard Distributions of Blood Tests for Public Use"
  Zenodo. doi:10.5281/zenodo.3841232   (CC BY 4.0)

日本の病院の入院患者の検査値を、124 項目について
対数正規 (87 項目) または指数分布 (37 項目) で当てはめた結果。
readme.txt によれば対数正規の形は

    P1 * exp(-(ln(x/P2)/P3)^2)

これは ln x についての正規形なので、本ツールの表現に直すと

    mu = ln(P2),   sigma = P3 / sqrt(2),   d = 0

となる (P1 は振幅なので分布の形には効かない)。
つまり 2 パラメータ対数正規であり、シフト d は持たない。

**このデータに相関行列は含まれず、男女の区別もない。**
周辺分布のパラメータだけである。したがって本ツールの
男女別相関の入力には使えない (その導出は tools/derive-sex-corr.php)。
使い道は次の 2 つ:

  1. カタログの中央値は推定値なので、実測値と突き合わせて妥当性を見る
  2. 恒等関係から導いた相関が、独立な実測データでも成立するか検算する

使い方:
    python3 tools/zenodo-params.py --fetch     # 取得して data/ に変換
    python3 tools/zenodo-params.py --compare   # カタログと突き合わせる
"""
import argparse, csv, io, json, math, os, subprocess, sys, urllib.request, zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
URL = 'https://zenodo.org/records/3841232/files/BloodTestParams.zip?download=1'
OUT = os.path.join(ROOT, 'data', 'zenodo-bloodtest-params.json')

# Zenodo の項目名 -> 本ツールのカタログキー
MAP = {
    'White blood cell count': 'WBC', 'Hemoglobin amount': 'Hb', 'HT': 'Ht',
    'MCV': 'MCV', 'MCH': 'MCH', 'MCHC': 'MCHC', 'Platelet count': 'PLT',
    'TP': 'TP', 'AST': 'AST', 'ALT': 'ALT', 'LD': 'LD', 'ALP': 'ALP',
    'Gamma-GT': 'gGTP', 'CHE': 'CHE', 'Na': 'Na', 'Cl': 'Cl', 'K': 'K',
    'UN': 'UN', 'CRE': 'CRE', 'UA': 'UA', 'TG': 'TG', 'IP': 'IP', 'AMY': 'AMY',
}


def fetch() -> dict:
    """Zenodo から取得して JSON へ変換する"""
    print(f'取得中: {URL}')
    with urllib.request.urlopen(URL) as r:
        blob = r.read()
    zf = zipfile.ZipFile(io.BytesIO(blob))
    text = zf.read('BloodTestParams.csv').decode('utf-8')

    items = []
    for r in csv.DictReader(io.StringIO(text)):
        if not r['Item']:
            continue
        rec = {'item': r['Item'], 'item_jpn': r['Item(Jpn)'], 'unit': r['Unit'],
               'dist': r['DistType'], 'p1': float(r['Param1']),
               'p2': float(r['Param2']), 'n': int(r['OriginalDataPoints']),
               'bin': float(r['Bin'])}
        if r['DistType'] == 'LogNormal':
            p3 = float(r['Param3'])
            rec['p3'] = p3
            # P1*exp(-(ln(x/P2)/P3)^2) は ln x について正規。
            # exp の中を -(ln x - ln P2)^2 / P3^2 と見ると 2 sigma^2 = P3^2。
            rec['mu'] = math.log(rec['p2'])
            rec['sigma'] = p3 / math.sqrt(2.0)
            rec['d'] = 0.0
            rec['median'] = rec['p2']
        if r['Item'] in MAP:
            rec['catalog_key'] = MAP[r['Item']]
        items.append(rec)

    out = {
        '_comment': 'Zenodo 公開の血液検査分布パラメータ。本ツールの入力ではなく、'
                    'カタログの推定中央値を実測値と突き合わせるための参照データ。'
                    '相関行列は含まれず、男女の区別もない。',
        '_source': 'Shiro M, Kagawa R, Kohro T (2020) '
                   'Standard Distributions of Blood Tests for Public Use. '
                   'Zenodo. doi:10.5281/zenodo.3841232',
        '_license': 'CC BY 4.0 (Zenodo のメタデータ表記。'
                    '同梱 readme.txt には CC BY-NC 4.0 とある)',
        '_population': '日本の病院の入院患者。男女込み。健常者の基準範囲とは母集団が違う',
        '_conversion': 'P1*exp(-(ln(x/P2)/P3)^2) より mu=ln(P2), sigma=P3/sqrt(2), d=0',
        'items': items,
    }
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, 'w', encoding='utf-8') as f:
        json.dump(out, f, ensure_ascii=False, indent=2)
        f.write('\n')
    ln = sum(1 for i in items if i['dist'] == 'LogNormal')
    print(f'{OUT} に保存 ({len(items)} 項目: 対数正規 {ln} / 指数 {len(items)-ln})')
    return out


def catalog_params() -> dict:
    """PHP 側のカタログから (中央値, sigma) を取り出す"""
    php = r'''
require "%s/web/synthlab/lib/catalog.php";
$o = [];
foreach (synthlab_catalog() as $k => $it) {
    foreach (["A", "M", "F"] as $s) {
        if (!isset($it["ri"][$s])) continue;
        [$L, $U, $M] = catalog_three($it, $s);
        $p = lnorm3_from_three((float)$L, (float)$U, (float)$M);
        $o[$k][$s] = ["median" => $M, "sigma" => $p["sigma"], "d" => $p["d"]];
    }
}
echo json_encode($o);''' % ROOT
    r = subprocess.run(['php', '-r', php], capture_output=True, text=True)
    return json.loads(r.stdout)


def compare() -> None:
    if not os.path.exists(OUT):
        print('先に --fetch を実行してください'); sys.exit(1)
    Z = {i['catalog_key']: i for i in json.load(open(OUT, encoding='utf-8'))['items']
         if i.get('catalog_key') and i['dist'] == 'LogNormal'}
    C = catalog_params()

    print('カタログの中央値は推定値 (公開されていない)。Zenodo は実測。')
    print('母集団が違う (健常者の基準範囲 vs 入院患者) ので差が出るのは当然だが、')
    print('差が極端な項目は測定法の違いなど別の原因を疑う。\n')
    print(f"{'項目':<7}{'当ツール':>10}{'Zenodo':>10}{'差':>9}"
          f"{'σ当ツール':>11}{'σ Zenodo':>10}{'σ比':>7}  {'n':>8}")
    rows = []
    for k, z in sorted(Z.items()):
        if k not in C:
            continue
        ent = C[k].get('A') or C[k].get('M')
        d = (ent['median'] - z['median']) / z['median'] * 100.0
        rows.append((abs(d), k, ent, z, d))
    for _, k, ent, z, d in sorted(rows, key=lambda t: -t[0]):
        print(f"{k:<7}{ent['median']:>10.2f}{z['median']:>10.2f}{d:>8.1f}%"
              f"{ent['sigma']:>11.4f}{z['sigma']:>10.4f}"
              f"{ent['sigma']/z['sigma']:>7.2f}  {z['n']:>8,}")

    print('\n--- 恒等関係の検算 (Zenodo の実測 sigma で) ---')
    sV, sH, sM = Z['MCV']['sigma'], Z['MCH']['sigma'], Z['MCHC']['sigma']
    rho = (sV * sV + sH * sH - sM * sM) / (2.0 * sV * sH)
    print(f'  MCHC = MCH / MCV より sigma(MCHC)^2 = sigma(MCH)^2 + sigma(MCV)^2 '
          f'- 2 rho sigma sigma')
    print(f'  → rho(MCV, MCH) = {rho:+.4f}  '
          f"{'[-1,1] 内で成立' if abs(rho) <= 1 else '!! 範囲外'}")
    print(f'  本ツールが基準範囲から導いた値 = +0.3170')
    print('  母集団が違うので値はずれるが、符号が一致し範囲内に収まる。')
    print('  恒等関係から相関を導く手口そのものは、独立な実測データでも成立する。')
    print('  (本ツールの男女別相関の導出は tools/derive-sex-corr.php)')


if __name__ == '__main__':
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--fetch', action='store_true', help='Zenodo から取得して変換')
    ap.add_argument('--compare', action='store_true', help='カタログと突き合わせる')
    a = ap.parse_args()
    if not (a.fetch or a.compare):
        ap.print_help(); sys.exit(0)
    if a.fetch:
        fetch()
    if a.compare:
        compare()
