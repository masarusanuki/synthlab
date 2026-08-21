<?php
// md.php -- 数式を保った Markdown → HTML 変換
//
// 数式・コードを先にプレースホルダへ退避してから Markdown を処理し、
// 最後に戻す。これにより数式中の _ や * が強調として解釈されるのを防ぐ。
// 数式は htmlspecialchars して戻す。MathJax は DOM の textContent を読むので、
// &lt; は復号されて正しい LaTeX として処理される。

declare(strict_types=1);

final class Md
{
    /** @var string[] 退避した断片 */
    private array $store = [];
    private int $seq = 0;

    /** 退避してプレースホルダを返す */
    private function keep(string $html): string
    {
        $k = "\x01MD" . (++$this->seq) . "\x02";
        $this->store[$k] = $html;
        return $k;
    }

    /**
     * Markdown を HTML に変換する。
     * @return array{html:string,title:string,toc:array<int,array{level:int,id:string,text:string}>}
     */
    public function render(string $src): array
    {
        $src = str_replace("\r\n", "\n", $src);

        // ---- フロントマター ----
        $title = '';
        if (preg_match('/\A---\n(.*?)\n---\n/s', $src, $m)) {
            foreach (explode("\n", $m[1]) as $ln) {
                if (preg_match('/^title:\s*(.+)$/', trim($ln), $mm)) {
                    $title = trim($mm[1]);
                }
            }
            $src = substr($src, strlen($m[0]));
        }

        // ---- 退避: フェンスコードブロック ----
        $src = preg_replace_callback('/^```([a-zA-Z0-9_+-]*)\n(.*?)^```[ \t]*$/ms',
            function ($m) {
                return "\n" . $this->keep(
                    '<pre><code>' . htmlspecialchars($m[2], ENT_QUOTES) . '</code></pre>'
                ) . "\n";
            }, $src);

        // ---- 退避: ディスプレイ数式 $$ ... $$ ----
        $src = preg_replace_callback('/\$\$(.+?)\$\$/s', function ($m) {
            return $this->keep('<div class="math-d">$$'
                . htmlspecialchars($m[1], ENT_NOQUOTES) . '$$</div>');
        }, $src);

        // ---- 退避: インラインコード ----
        $src = preg_replace_callback('/`([^`\n]+)`/', function ($m) {
            return $this->keep('<code>' . htmlspecialchars($m[1], ENT_QUOTES) . '</code>');
        }, $src);

        // ---- 退避: インライン数式 $ ... $ ----
        // 直前が \ でなく、$ の直後が空白でないものだけを数式とみなす
        $src = preg_replace_callback('/(?<!\\\\)\$(?!\s)((?:[^\$\n]|\\\\\$)+?)(?<!\s)\$/',
            function ($m) {
                return $this->keep('<span class="math-i">$'
                    . htmlspecialchars($m[1], ENT_NOQUOTES) . '$</span>');
            }, $src);

        // ---- ブロック処理 ----
        $lines = explode("\n", $src);
        $out = [];
        $toc = [];
        $n = count($lines);
        $i = 0;
        $para = [];

        $flush = function () use (&$para, &$out) {
            if ($para) {
                $out[] = '<p>' . $this->inline(implode("\n", $para)) . '</p>';
                $para = [];
            }
        };

        while ($i < $n) {
            $ln = $lines[$i];
            $t = rtrim($ln);

            // 退避済み断片が単独で置かれている行
            if (preg_match('/^\x01MD\d+\x02$/', trim($t))) {
                $flush();
                $out[] = trim($t);
                $i++;
                continue;
            }

            // 空行
            if (trim($t) === '') {
                $flush();
                $i++;
                continue;
            }

            // 見出し
            if (preg_match('/^(#{1,6})\s+(.*)$/', $t, $m)) {
                $flush();
                $lv = strlen($m[1]);
                $txt = $this->inline(trim($m[2]));
                $id = 'h' . (count($toc) + 1);
                $toc[] = ['level' => $lv, 'id' => $id, 'text' => strip_tags($txt)];
                $out[] = "<h{$lv} id=\"{$id}\">{$txt}</h{$lv}>";
                $i++;
                continue;
            }

            // 水平線
            if (preg_match('/^\s*(-{3,}|\*{3,}|_{3,})\s*$/', $t)) {
                $flush();
                $out[] = '<hr>';
                $i++;
                continue;
            }

            // テーブル (次行が区切り行)
            if (str_contains($t, '|') && $i + 1 < $n
                && preg_match('/^\s*\|?[\s:|-]+\|[\s:|-]*$/', $lines[$i + 1])
                && str_contains($lines[$i + 1], '-')) {
                $flush();
                [$html, $i] = $this->table($lines, $i);
                $out[] = $html;
                continue;
            }

            // 引用
            if (preg_match('/^>\s?(.*)$/', $t)) {
                $flush();
                $buf = [];
                while ($i < $n && preg_match('/^>\s?(.*)$/', rtrim($lines[$i]), $m)) {
                    $buf[] = $m[1];
                    $i++;
                }
                $inner = $this->render_inner(implode("\n", $buf));
                $out[] = '<blockquote>' . $inner . '</blockquote>';
                continue;
            }

            // リスト
            if (preg_match('/^\s*([-*+]|\d+\.)\s+/', $t)) {
                $flush();
                [$html, $i] = $this->lists($lines, $i);
                $out[] = $html;
                continue;
            }

            // 段落
            $para[] = $t;
            $i++;
        }
        $flush();

        $html = implode("\n", $out);

        // ---- 退避を戻す (入れ子があるので繰り返す) ----
        for ($k = 0; $k < 6; $k++) {
            $before = $html;
            $html = strtr($html, $this->store);
            if ($html === $before) {
                break;
            }
        }

        // ---- 目次の見出し文字列も退避を戻して素のテキストにする ----
        // $toc は本文より前に組み立てるためプレースホルダを含む。
        // 戻したうえでタグを外し実体参照を復号する (描画側で1回だけエスケープする)。
        foreach ($toc as &$t) {
            $s = $t['text'];
            for ($k = 0; $k < 6; $k++) {
                $before = $s;
                $s = strtr($s, $this->store);
                if ($s === $before) {
                    break;
                }
            }
            $t['text'] = trim(html_entity_decode(strip_tags($s), ENT_QUOTES, 'UTF-8'));
        }
        unset($t);

        return ['html' => $html, 'title' => $title, 'toc' => $toc];
    }

    /** 引用の中身など、ブロック構造を持つ断片を再帰処理する */
    private function render_inner(string $s): string
    {
        $lines = explode("\n", $s);
        $out = [];
        $para = [];
        $n = count($lines);
        $i = 0;
        $flush = function () use (&$para, &$out) {
            if ($para) {
                $out[] = '<p>' . $this->inline(implode("\n", $para)) . '</p>';
                $para = [];
            }
        };
        while ($i < $n) {
            $t = rtrim($lines[$i]);
            if (preg_match('/^\x01MD\d+\x02$/', trim($t))) {
                $flush();
                $out[] = trim($t);
                $i++;
                continue;
            }
            if (trim($t) === '') {
                $flush();
                $i++;
                continue;
            }
            if (preg_match('/^\s*([-*+]|\d+\.)\s+/', $t)) {
                $flush();
                [$h, $i] = $this->lists($lines, $i);
                $out[] = $h;
                continue;
            }
            $para[] = $t;
            $i++;
        }
        $flush();
        return implode("\n", $out);
    }

    /** テーブルを処理する */
    private function table(array $lines, int $i): array
    {
        $split = function (string $s): array {
            $s = trim($s);
            $s = preg_replace('/^\|/', '', $s);
            $s = preg_replace('/\|$/', '', $s);
            return array_map('trim', explode('|', (string)$s));
        };
        $head = $split($lines[$i]);
        $align = [];
        foreach ($split($lines[$i + 1]) as $c) {
            $l = str_starts_with($c, ':');
            $r = str_ends_with($c, ':');
            $align[] = ($l && $r) ? 'center' : ($r ? 'right' : ($l ? 'left' : ''));
        }
        $i += 2;
        $rows = [];
        $n = count($lines);
        while ($i < $n && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) {
            $rows[] = $split($lines[$i]);
            $i++;
        }
        $h = '<div class="scroll"><table><thead><tr>';
        foreach ($head as $k => $c) {
            $a = $align[$k] ?? '';
            $h .= '<th' . ($a ? ' style="text-align:' . $a . '"' : '') . '>'
                . $this->inline($c) . '</th>';
        }
        $h .= '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $h .= '<tr>';
            foreach ($head as $k => $_) {
                $a = $align[$k] ?? '';
                $h .= '<td' . ($a ? ' style="text-align:' . $a . '"' : '') . '>'
                    . $this->inline($r[$k] ?? '') . '</td>';
            }
            $h .= '</tr>';
        }
        return [$h . '</tbody></table></div>', $i];
    }

    /** リストを処理する (インデントによる入れ子に対応) */
    private function lists(array $lines, int $i): array
    {
        $n = count($lines);
        $items = [];
        $ordered = null;
        while ($i < $n) {
            $ln = rtrim($lines[$i]);
            if (trim($ln) === '') {
                // 次の行がリストの続きなら空行を飛ばす
                if ($i + 1 < $n && preg_match('/^\s*([-*+]|\d+\.)\s+/', rtrim($lines[$i + 1]))) {
                    $i++;
                    continue;
                }
                break;
            }
            if (preg_match('/^(\s*)([-*+]|\d+\.)\s+(.*)$/', $ln, $m)) {
                $ord = !in_array($m[2], ['-', '*', '+'], true);
                if ($ordered === null) {
                    $ordered = $ord;
                }
                $items[] = ['indent' => strlen($m[1]), 'text' => [$m[3]]];
                $i++;
                continue;
            }
            // 継続行 (インデントされた本文)
            if ($items && preg_match('/^\s+\S/', $ln)) {
                $items[count($items) - 1]['text'][] = trim($ln);
                $i++;
                continue;
            }
            break;
        }
        $tag = $ordered ? 'ol' : 'ul';
        $base = $items ? min(array_column($items, 'indent')) : 0;
        $h = "<{$tag}>";
        $open = 0;
        foreach ($items as $it) {
            $depth = (int)floor(max(0, $it['indent'] - $base) / 2);
            while ($open < $depth) {
                $h .= "<{$tag}>";
                $open++;
            }
            while ($open > $depth) {
                $h .= "</{$tag}>";
                $open--;
            }
            $h .= '<li>' . $this->inline(implode(' ', $it['text'])) . '</li>';
        }
        while ($open-- > 0) {
            $h .= "</{$tag}>";
        }
        return [$h . "</{$tag}>", $i];
    }

    /** インライン要素を処理する */
    private function inline(string $s): string
    {
        // プレースホルダを一時的に守る
        $s = htmlspecialchars($s, ENT_QUOTES);
        // htmlspecialchars でプレースホルダは壊れない (制御文字と数字のみ)

        // リンク [text](url)
        $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
            $url = $m[2];
            $ext = preg_match('#^https?://#i', $url);
            if (!$ext) {
                // 同じ文書群への相対リンクは doc.php?id=... に書き換える
                if (preg_match('/^([0-9]{2}-[A-Za-z0-9._-]+)\.md(#.*)?$/', $url, $mm)) {
                    $url = 'doc.php?id=' . rawurlencode($mm[1]) . ($mm[2] ?? '');
                }
            }
            return '<a href="' . $url . '"' . ($ext ? ' target="_blank" rel="noopener"' : '')
                 . '>' . $m[1] . '</a>';
        }, $s);

        $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', (string)$s);
        $s = preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/', '<em>$1</em>', (string)$s);
        return (string)$s;
    }
}

/** 公開済み文書 (content/*.md.php) のディレクトリ */
function docs_dir(): string
{
    return __DIR__ . '/../content';
}

/** 公開済み文書を読み込んで本文を返す */
function doc_load(string $file): string
{
    if (!defined('SYNTHLAB_DEV')) {
        define('SYNTHLAB_DEV', 1);
    }
    $s = require $file;
    return is_string($s) ? $s : '';
}

/**
 * 文書一覧を返す。
 * @return array<int,array{id:string,file:string,title:string,order:int}>
 */
function doc_list(): array
{
    $out = [];
    foreach (glob(docs_dir() . '/*.md.php') ?: [] as $f) {
        $id = preg_replace('/\.md$/', '', basename($f, '.php'));
        $body = doc_load($f);
        $title = $id;
        $order = 999;
        if (preg_match('/\A---\n(.*?)\n---/s', $body, $m)) {
            if (preg_match('/^title:\s*(.+)$/m', $m[1], $mm)) {
                $title = trim($mm[1]);
            }
            if (preg_match('/^order:\s*(\d+)/m', $m[1], $mm)) {
                $order = (int)$mm[1];
            }
        }
        $out[] = ['id' => $id, 'file' => $f, 'title' => $title, 'order' => $order];
    }
    usort($out, fn($a, $b) => [$a['order'], $a['id']] <=> [$b['order'], $b['id']]);
    return $out;
}

/**
 * id から実ファイルパスを安全に解決する。
 * ディレクトリトラバーサルを防ぐため、許可文字を絞り realpath で検証する。
 */
function doc_path(string $id): ?string
{
    if (!preg_match('/\A[A-Za-z0-9._-]{1,80}\z/', $id) || str_contains($id, '..')) {
        return null;
    }
    $base = realpath(docs_dir());
    $p = realpath(docs_dir() . '/' . $id . '.md.php');
    if ($p === false || $base === false || !str_starts_with($p, $base . '/')) {
        return null;
    }
    return is_file($p) ? $p : null;
}
