<?php
// index.php -- 擬似検査値生成ツール (公開・パスワードなし)
declare(strict_types=1);

// Apache 側の実行メモリ制限により PCRE の JIT 確保が失敗し警告が出る環境のため、
// JIT を明示的に無効化する (機能は変わらず、エラーログの汚れを防ぐ)。
@ini_set('pcre.jit', '0');
require_once __DIR__ . '/lib/engine.php';
require_once __DIR__ . '/lib/nav.php';
require_once __DIR__ . '/lib/usagelog.php';
ul_view('index');

$cat = synthlab_catalog();
$dis = synthlab_diseases();
$groups = synthlab_groups();
$defaults = array_flip(synthlab_default_items());

// 前回入力の復元 (GET で戻ってきた場合)
$v = function (string $k, $d) {
    return isset($_GET[$k]) ? $_GET[$k] : $d;
};
$selItems = isset($_GET['items']) && is_array($_GET['items'])
    ? array_flip($_GET['items']) : $defaults;
$mode = $v('mode', 'cross') === 'series' ? 'series' : 'cross';
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SynthLab — 擬似血液検査値ジェネレータ</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php sl_header('index', '擬似血液検査値ジェネレータ',
  '3パラメータ対数正規分布と切断多変量正規サンプリングによる合成検査データの生成'); ?>

<div class="wrap">

<div class="note">
  <strong>これは何か.</strong>
  静脈血の検体検査値が「3パラメータ対数正規分布 <span class="mono">lnorm3(μ, σ, d)</span>」で
  よく表せるという研究結果にもとづき、実データを一切使わずに擬似的な検査値を生成します。
  項目間の相関は対数スケールの分散共分散行列で与え、
  断面データは切断多変量正規分布からの Gibbs サンプリング、
  時系列データは Cholesky 分解と逆関数法で生成します。
  <br>
  出典: Shiro &amp; Kagawa, MEDINFO 2022 / 城, 生物試料分析 47(4) 2024 /
  Furukawa, Kagawa, Sanuki &amp; Shiro, MEDINFO 2025.<br>
  仕組みの説明は <a href="about.php">手法の解説</a> をご覧ください。
</div>

<div class="warn">
  <strong>ご利用にあたって.</strong>
  出力されるのは実在しない架空の数値です。実際の患者データではありません。
  診断・治療・臨床判断には使用できません。教育、ソフトウェア開発、動作確認、
  統計手法の検証などを想定した用途に限ってご利用ください。
  疾患プリセットの効果量は論文には含まれておらず、一般的な臨床知見に基づく参考値です。
</div>

<form method="post" action="generate.php" id="f">
<div class="panel">
  <h2 style="margin-top:4px">1. 対象</h2>

  <p style="font-size:13px;color:var(--muted);margin-top:0">
    元になった研究は<strong>個人の検査値をどう解釈するか</strong>が主眼です
    （論文2 の題は「血液検査値に<strong>偏差値</strong>をつける」）。
    そのため既定は「1人を詳しく見る」になっています。
  </p>

  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
    <label class="pickbox<?= empty($_GET['batch']) ? ' on' : '' ?>">
      <input type="radio" name="batch" value=""<?= empty($_GET['batch']) ? ' checked' : '' ?>
             onchange="syncbatch()">
      <strong>1人を詳しく見る</strong>
      <span>各項目の偏差値・パーセンタイル・分布内の位置を表示します</span>
    </label>
    <label class="pickbox<?= !empty($_GET['batch']) ? ' on' : '' ?>">
      <input type="radio" name="batch" value="1"<?= !empty($_GET['batch']) ? ' checked' : '' ?>
             onchange="syncbatch()">
      <strong>N人まとめて出力</strong>
      <span>集団のデータセットを作ります</span>
    </label>
  </div>

  <div class="batchonly">
    <div class="warn" style="margin-top:0">
      <strong>N人分の出力についての注意.</strong>
      元の研究が対象としたのは
      「疾患名をもたない成人<strong>の分布</strong>」および
      「<strong>個人</strong>の検査値の解釈」です。
      N人分をまとめて出力する機能は本ツールが利便のために足したものであり、
      <strong>集団としての疫学的な妥当性（有病率、年齢構成、施設ごとの偏りなど）は
      一切考慮していません</strong>。
      機械学習の動作確認やソフトウェア試験のような
      「もっともらしい形の表が必要な」用途を想定しています。
      集団の統計を論じる目的には使えません。
    </div>
    <div class="grid">
      <div>
        <label class="f" for="npat">患者数 (人)
          <span class="hint">1 〜 <?= SL_MAX_PATIENTS ?></span></label>
        <input type="number" id="npat" name="npat" min="1" max="<?= SL_MAX_PATIENTS ?>"
               value="<?= (int)$v('npat', 100) ?>">
      </div>
    </div>
  </div>

  <div class="grid" style="margin-top:14px">
    <div>
      <label class="f" for="sex">性別
        <span class="hint">混合を選ぶと、男女それぞれの基準範囲で半数ずつ生成し CSV に sex 列が入ります</span></label>
      <select id="sex" name="sex">
        <option value="A"<?= $v('sex','A')==='A'?' selected':'' ?>>男女混合 (50:50)</option>
        <option value="M"<?= $v('sex','A')==='M'?' selected':'' ?>>男性</option>
        <option value="F"<?= $v('sex','A')==='F'?' selected':'' ?>>女性</option>
      </select>
    </div>
    <div>
      <label class="f" for="disease">疾患名 (プリセット)
        <span class="hint">選択した疾患に応じて μ・σ・切断域を変えます</span></label>
      <select id="disease" name="disease">
        <?php foreach ($dis as $k => $d): ?>
        <option value="<?= htmlspecialchars($k) ?>"<?= $v('disease','none')===$k?' selected':'' ?>>
          <?= htmlspecialchars($d['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="f" for="label">コホート名 (自由記入・任意)
        <span class="hint">CSV のヘッダコメントに記録するだけで、分布は変わりません</span></label>
      <input type="text" id="label" name="label" maxlength="60"
             value="<?= htmlspecialchars((string)$v('label','')) ?>"
             placeholder="例: 2026年度 内科外来コホート">
    </div>
  </div>

  <details style="margin-top:14px">
    <summary>疾患プリセットの内容を見る</summary>
    <div class="scroll" style="margin-top:10px">
    <table>
      <thead><tr><th class="l">プリセット</th><th class="l">説明</th><th class="l">動かす項目 (σ単位のμシフト)</th></tr></thead>
      <tbody>
      <?php foreach ($dis as $k => $d): ?>
        <tr>
          <td class="l"><?= htmlspecialchars($d['label']) ?></td>
          <td class="l" style="white-space:normal;max-width:26em"><?= htmlspecialchars($d['desc']) ?></td>
          <td class="l mono" style="white-space:normal;max-width:26em"><?php
            if (!$d['effects']) { echo '—'; }
            $s = [];
            foreach ($d['effects'] as $ik => $e) {
                $t = $ik . ' ' . (isset($e['shift']) ? sprintf('%+.1f', $e['shift']) : '±0');
                if (isset($e['min'])) { $t .= ' ≥' . $e['min']; }
                if (isset($e['max'])) { $t .= ' ≤' . $e['max']; }
                $s[] = $t;
            }
            echo htmlspecialchars(implode(', ', $s));
          ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </details>
</div>

<div class="panel">
  <h2 style="margin-top:4px">2. 出力の種類</h2>
  <div class="grid">
    <div>
      <label class="f" for="mode">データ形式</label>
      <select id="mode" name="mode" onchange="syncmode()">
        <option value="cross"<?= $mode==='cross'?' selected':'' ?>>断面データ (1人1行)</option>
        <option value="series"<?= $mode==='series'?' selected':'' ?>>時系列データ (1人 n 回受診)</option>
      </select>
    </div>
    <div class="ser">
      <label class="f" for="points">1人あたりの受診回数
        <span class="hint">2 〜 <?= SL_MAX_POINTS ?></span></label>
      <input type="number" id="points" name="points" min="2" max="<?= SL_MAX_POINTS ?>"
             value="<?= (int)$v('points', 12) ?>">
    </div>
    <div class="ser">
      <label class="f" for="interval">受診間隔 (週)</label>
      <input type="number" id="interval" name="interval" min="0.5" max="520" step="any"
             value="<?= htmlspecialchars((string)$v('interval', 4)) ?>">
    </div>
    <div class="ser">
      <label class="f" for="sfrac">個人内変動の幅 (σ の %)
        <span class="hint">論文の設定は 10〜20%</span></label>
      <input type="number" id="sfrac" name="sfrac" min="0.1" max="100" step="any"
             value="<?= htmlspecialchars((string)$v('sfrac', 10)) ?>">
    </div>
    <div class="ser">
      <label class="f" for="noise">測定ノイズ (σ の %)</label>
      <input type="number" id="noise" name="noise" min="0" max="50" step="any"
             value="<?= htmlspecialchars((string)$v('noise', 1)) ?>">
    </div>
  </div>

  <div class="grid" style="margin-top:14px">
    <div>
      <label class="f" for="trunc">値域の切断
        <span class="hint">外れ値の扱い</span></label>
      <select id="trunc" name="trunc">
        <option value="sd5"<?= $v('trunc','sd5')==='sd5'?' selected':'' ?>>±5σ で切断 (論文の前処理に対応)</option>
        <option value="ri"<?= $v('trunc','sd5')==='ri'?' selected':'' ?>>基準範囲内に限定 (2.5–97.5%)</option>
        <option value="none"<?= $v('trunc','sd5')==='none'?' selected':'' ?>>切断しない</option>
      </select>
    </div>
    <div>
      <label class="f" for="corrscale">項目間相関の強さ (%)
        <span class="hint">0 で完全独立、100 で既定の相関構造</span></label>
      <input type="number" id="corrscale" name="corrscale" min="0" max="100" step="5"
             value="<?= htmlspecialchars((string)$v('corrscale', 100)) ?>">
    </div>
    <div>
      <label class="f" for="seed">乱数種 (任意)
        <span class="hint">同じ値なら同じ結果が再現します。空欄でランダム</span></label>
      <input type="text" id="seed" name="seed" inputmode="numeric" pattern="[0-9]*"
             value="<?= htmlspecialchars((string)$v('seed','')) ?>" placeholder="例: 20260821">
    </div>
    <div>
      <label class="f">追加出力</label>
      <label class="chk"><input type="checkbox" name="writez" value="1"
        <?= !empty($_GET['writez'])?' checked':'' ?>> 対数スケールの値 z も出力する</label>
    </div>
  </div>
</div>

<div class="panel">
  <h2 style="margin-top:4px">3. 検査項目</h2>
  <div class="tools">
    <a onclick="pick('all')">すべて選択</a>
    <a onclick="pick('none')">すべて解除</a>
    <a onclick="pick('default')">既定 (<?= count(synthlab_default_items()) ?>項目) に戻す</a>
  </div>
  <div class="grid" style="margin-top:8px">
    <?php foreach ($groups as $g): ?>
    <div class="itembox">
      <h4><?= htmlspecialchars($g) ?></h4>
      <?php foreach ($cat as $k => $it): if ($it['group'] !== $g) { continue; } ?>
        <label class="chk"><input type="checkbox" name="items[]" value="<?= htmlspecialchars($k) ?>"
          <?= isset($selItems[$k]) ? ' checked' : '' ?>>
          <?= htmlspecialchars($k) ?>
          <span style="color:var(--muted)"><?= htmlspecialchars($it['label']) ?></span></label>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <p style="font-size:12.5px;color:var(--muted);margin:12px 0 0">
    上限: 出力セル数 (行数 × 項目数) <?= number_format(SL_MAX_CELLS) ?> まで。
  </p>
</div>

<div class="actions">
  <button type="submit" name="act" value="preview">生成してプレビュー</button>
  <button type="submit" name="act" value="csv" class="sec">CSV を直接ダウンロード</button>
</div>
</form>

<?php sl_footer('index'); ?>
</div>

<script>
var DEF = <?= json_encode(synthlab_default_items(), JSON_UNESCAPED_UNICODE) ?>;
function boxes() { return document.querySelectorAll('input[name="items[]"]'); }
function pick(w) {
  var d = {};
  DEF.forEach(function (k) { d[k] = 1; });
  boxes().forEach(function (b) {
    b.checked = (w === 'all') ? true : (w === 'none') ? false : !!d[b.value];
  });
}
function syncbatch() {
  var on = document.querySelector('input[name="batch"]:checked').value === '1';
  document.querySelectorAll('.batchonly').forEach(function (e) {
    e.style.display = on ? '' : 'none';
    // 非表示の入力は検証・送信の対象から外す (無言の送信失敗を防ぐ)
    e.querySelectorAll('input, select').forEach(function (i) { i.disabled = !on; });
  });
  document.querySelectorAll('.pickbox').forEach(function (e) {
    var r = e.querySelector('input[type=radio]');
    e.classList.toggle('on', r.checked);
  });
}
function syncmode() {
  var s = document.getElementById('mode').value === 'series';
  document.querySelectorAll('.ser').forEach(function (e) {
    e.style.display = s ? '' : 'none';
    // 非表示の入力を検証対象から外す。invalid なまま隠れていると
    // ブラウザは送信を拒否するがエラーを表示できず無言で失敗する。
    e.querySelectorAll('input, select').forEach(function (i) {
      i.disabled = !s;
    });
  });
}
syncmode();
syncbatch();
</script>
</body>
</html>
