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
$groups = synthlab_groups();
$defaults = array_flip(synthlab_default_items());

// 前回入力の復元 (GET で戻ってきた場合)
$v = function (string $k, $d) {
    return isset($_GET[$k]) ? $_GET[$k] : $d;
};
$selItems = isset($_GET['items']) && is_array($_GET['items'])
    ? array_flip($_GET['items']) : $defaults;
$mode = $v('mode', 'series') === 'cross' ? 'cross' : 'series';
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

<div class="note" style="margin-top:0">
  <strong>3パラメータ対数正規分布</strong>にもとづき、実データを使わずに擬似的な検査値を作ります。
  項目間の相関も反映します。<strong>出力は架空の数値で、臨床判断には使えません。</strong>
  <a href="about.php">仕組みの解説 →</a>
</div>

<form method="post" action="generate.php" id="f">
<div class="panel">
  <h2 style="margin-top:4px">1. 対象</h2>

  <p style="font-size:12.5px;color:var(--muted);margin:0 0 8px">
    元の研究は<strong>個人の検査値の解釈</strong>が主眼なので、既定は1人です。
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
      <strong>注意.</strong> N人分の出力は利便のために足した機能で、
      <strong>集団としての疫学的な妥当性（有病率・年齢構成など）は考慮していません</strong>。
      機械学習の動作確認やソフトウェア試験を想定しています。
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
  </div>

</div>

<div class="panel">
  <h2 style="margin-top:4px">2. 出力の種類</h2>
  <div class="grid">
    <div>
      <label class="f" for="mode">データ形式</label>
      <select id="mode" name="mode" onchange="syncmode()">
        <option value="series"<?= $mode==='series'?' selected':'' ?>>時系列データ (n 回受診)</option>
        <option value="cross"<?= $mode==='cross'?' selected':'' ?>>断面データ (1回のみ)</option>
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
  <div class="grid items" style="margin-top:6px">
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
  <p style="font-size:11.5px;color:var(--muted);margin:6px 0 0">
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
