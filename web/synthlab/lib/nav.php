<?php
// nav.php -- 公開ページ共通のヘッダとフッタ
//
// リンク一覧を1か所で定義し、全ページで同じものを表示する。
// ページを追加するときは SL_PAGES に1行足すだけでよい。

declare(strict_types=1);

/** 公開ページの一覧 (キー => [ファイル, 表示名, 補足]) */
function sl_pages(): array
{
    return [
        'index'    => ['index.php',    'データを生成',   '擬似検査値を作る'],
        'about'    => ['about.php',    '手法の解説',     '仕組みの説明'],
        'cli'      => ['cli.php',      'コマンドライン版', '端末から使う'],
        'feedback' => ['feedback.php', 'ご意見・ご感想', '使用感をお寄せください'],
    ];
}

/**
 * 共通ヘッダを出力する。
 *
 * @param string $current sl_pages() のキー。現在のページを強調表示する
 * @param string $title   ページ見出し (ブランド名の後ろに出る)
 * @param string $sub     副題
 * @param string $extra   ナビの右端に足す追加リンクの HTML (任意)
 */
function sl_header(string $current, string $title, string $sub, string $extra = ''): void
{
    $pages = sl_pages();
    ?>
<header class="top"><div class="wrap topbar">
  <div>
    <h1><a class="brandlink" href="index.php">SynthLab</a><?php
      if ($title !== '') { echo ' — ' . htmlspecialchars($title); } ?></h1>
    <p><?= htmlspecialchars($sub) ?></p>
  </div>
  <nav class="topnav">
    <?= $extra ?>
    <?php foreach ($pages as $k => [$file, $label, $hint]):
        $cur = ($k === $current); ?>
      <a class="navlink<?= $cur ? ' cur' : '' ?>" href="<?= htmlspecialchars($file) ?>"
         title="<?= htmlspecialchars($hint) ?>"<?= $cur ? ' aria-current="page"' : '' ?>
        ><?= htmlspecialchars($label) ?></a>
    <?php endforeach; ?>
  </nav>
</div></header>
<?php }

/** 共通フッタを出力する */
function sl_footer(string $current): void
{
    $pages = sl_pages();
    ?>
<footer>
  <div style="margin-bottom:8px">
    <?php $first = true;
    foreach ($pages as $k => [$file, $label, $hint]):
        if (!$first) { echo ' · '; }
        $first = false;
        if ($k === $current): ?><strong><?= htmlspecialchars($label) ?></strong><?php
        else: ?><a href="<?= htmlspecialchars($file) ?>"><?= htmlspecialchars($label) ?></a><?php
        endif;
    endforeach; ?>
  </div>
  SynthLab · 出力は架空の数値であり、実在の患者データではありません。臨床判断には使用できません。
</footer>
<?php }
