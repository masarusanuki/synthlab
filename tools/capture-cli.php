<?php
// capture-cli.php -- CLI を実際に実行して、その出力を Web 掲載用に保存する
//
// ポスターやスクリーンショットに載せる出力は、手で書いたものではなく
// 実行結果そのものであるべきなので、ここで実行して記録する。
// 生成物: data/cli-examples.json  (cli.php が読む)
//
// 使い方: php tools/capture-cli.php

declare(strict_types=1);

$root = '/home/sanuki/synthlab';
$py   = 'python3 ' . $root . '/cli/synthlab.py';
$java = 'java -cp ' . $root . '/cli/java SynthLab';

/** コマンドを実行して stdout/stderr と所要時間を返す */
function run(string $cmd, int $headLines = 0): array
{
    $t0 = microtime(true);
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = proc_open(['bash', '-lc', $cmd], $desc, $pipes, '/home/sanuki/synthlab');
    if (!is_resource($p)) {
        return ['ok' => false, 'out' => '', 'err' => '起動できませんでした', 'ms' => 0];
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($p);
    $ms = (microtime(true) - $t0) * 1000;

    $trunc = false;
    if ($headLines > 0) {
        $lines = preg_split('/\n/', rtrim((string)$out, "\n"));
        if (count($lines) > $headLines) {
            $total = count($lines);
            $lines = array_slice($lines, 0, $headLines);
            $lines[] = sprintf('... (以下 %d 行省略。全 %d 行)', $total - $headLines, $total);
            $trunc = true;
        }
        $out = implode("\n", $lines);
    }
    return ['ok' => $rc === 0, 'rc' => $rc, 'out' => $out, 'err' => $err,
            'ms' => $ms, 'truncated' => $trunc];
}

$examples = [
    [
        'id'    => 'explain',
        'title' => '入力が何かを確認する',
        'note'  => '実データは入力しない。入力は「生成条件」と「パラメータ源の JSON」だけ。',
        'cmd'   => 'python3 cli/synthlab.py --explain',
        'head'  => 34,
    ],
    [
        'id'    => 'list',
        'title' => '使える検査項目を見る',
        'note'  => 'カタログ (data/catalog.json) に入っている項目と基準範囲。',
        'cmd'   => 'python3 cli/synthlab.py --list-items',
        'head'  => 14,
    ],
    [
        'id'    => 'default',
        'title' => '既定の生成 (1人 × 12回受診)',
        'note'  => '研究が個人に着目しているので既定は1人。CSV の # 行に再現条件が入る。',
        'cmd'   => 'python3 cli/synthlab.py --items Hb,AST,CRE,Glu,HbA1c,TG',
        'head'  => 22,
    ],
    [
        'id'    => 'deviation',
        'title' => '偏差値つきの個人票',
        'note'  => '論文2 の主目的。対数スケールに移せば正規分布なので数値積分が要らない。',
        'cmd'   => 'python3 cli/synthlab.py --items Hb,AST,CRE,Glu,HbA1c,TG '
                 . '--deviation --no-comment > /dev/null',
        'head'  => 0,
        'use'   => 'err',
    ],
    [
        'id'    => 'cross',
        'title' => '断面データを 2000 人分 (男女混合)',
        'note'  => '男女それぞれの基準範囲で半数ずつ生成する。sex 列が入る。',
        'cmd'   => 'python3 cli/synthlab.py --n 2000 --mode cross --sex A '
                 . '--items Hb,Ht,AST,CRE,TG --no-comment',
        'head'  => 8,
    ],
    [
        'id'    => 'params',
        'title' => '復元されたパラメータを見る',
        'note'  => '基準範囲の3つの値から (mu, sigma, d) を閉じた形で復元している。',
        'cmd'   => 'python3 cli/synthlab.py --items Hb,AST,CRE,TG,CRP '
                 . '--show-params --no-comment > /dev/null',
        'head'  => 0,
        'use'   => 'err',
    ],
    [
        'id'    => 'swap',
        'title' => '基準範囲を差し替える',
        'note'  => 'カタログの JSON を書き換えれば、別の施設・別の国の分布を作れる。'
                 . 'ここでは Hb の基準範囲を狭めた一時ファイルで実行している。',
        'cmd'   => 'python3 - <<\'EOF\' > /tmp/sl_cat.json' . "\n"
                 . 'import json' . "\n"
                 . 'c=json.load(open("data/catalog.json"))' . "\n"
                 . 'for it in c["items"]:' . "\n"
                 . '    if it["key"]=="Hb":' . "\n"
                 . '        it["reference"]["M"]={"lower":14.0,"upper":15.5,"median":14.7}' . "\n"
                 . 'json.dump(c,open("/tmp/sl_cat.json","w"),ensure_ascii=False)' . "\n"
                 . 'EOF' . "\n"
                 . 'python3 cli/synthlab.py --catalog /tmp/sl_cat.json --items Hb '
                 . '--show-params --no-comment > /dev/null',
        'head'  => 0,
        'use'   => 'err',
    ],
    [
        'id'    => 'sex',
        'title' => '男女で分布を切り替える (--sex)',
        'note'  => '論文は検査項目を男女別に当てはめている (生物試料 Fig.2 「男女別の TG および TC」)。'
                 . '--sex M / F で基準範囲ごと切り替わり、--sex A は男女 50:50 の混合になる。',
        'cmd'   => 'echo "### sex=M で復元されたパラメータ ###"' . "\n"
                 . 'python3 cli/synthlab.py --items Hb,RBC,CRE --sex M --show-params '
                 . '--no-comment 2>&1 >/dev/null' . "\n"
                 . 'echo "### sex=F で復元されたパラメータ ###"' . "\n"
                 . 'python3 cli/synthlab.py --items Hb,RBC,CRE --sex F --show-params '
                 . '--no-comment 2>&1 >/dev/null' . "\n"
                 . 'echo "### sex=A (男女 50:50) 6人・断面 ###"' . "\n"
                 . 'python3 cli/synthlab.py --items Hb,RBC,CRE --sex A --n 6 --mode cross --no-comment',
        'head'  => 0,
    ],
    [
        'id'    => 'json',
        'title' => 'JSON で出力する',
        'note'  => 'パイプラインに流しやすい形。meta に復元したパラメータが入る。',
        'cmd'   => 'python3 cli/synthlab.py --n 1 --points 2 --items Hb,TG --format json',
        'head'  => 20,
    ],
    [
        'id'    => 'java',
        'title' => 'Java 版も同じ入力・同じ引数',
        'note'  => '外部ライブラリなしで動く。JSON パーサと線形代数も自前。',
        'cmd'   => 'cd cli/java && javac -d . SynthLab.java 2>&1 && '
                 . 'java SynthLab --items Hb,AST,CRE,Glu,HbA1c,TG --deviation '
                 . '--no-comment 2>&1 >/dev/null',
        'head'  => 0,
    ],
];

$out = ['captured_at' => date('c'), 'examples' => []];
foreach ($examples as $ex) {
    $r = run($ex['cmd'], (int)($ex['head'] ?? 0));
    $text = (($ex['use'] ?? 'out') === 'err') ? $r['err'] : $r['out'];
    if (($ex['use'] ?? 'out') !== 'err' && trim((string)$r['err']) !== '' && !$r['ok']) {
        $text .= "\n" . $r['err'];
    }
    $out['examples'][] = [
        'id' => $ex['id'], 'title' => $ex['title'], 'note' => $ex['note'],
        'cmd' => $ex['cmd'], 'output' => rtrim((string)$text, "\n"),
        'ok' => $r['ok'], 'ms' => round($r['ms'], 1),
    ];
    printf("%-10s %s  %6.0f ms  %d 文字\n", $ex['id'], $r['ok'] ? 'OK  ' : '失敗',
        $r['ms'], mb_strlen((string)$text));
}

$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
file_put_contents($root . '/data/cli-examples.json', $json);

// 公開ページから読めるようにコピーする。
// Apache は /home/sanuki を辿れない (drwxr-x---) ため、
// ドキュメントルート配下にも置く必要がある。
// 中身は実行例と入力ファイルだけで秘密は含まない。
$web = '/var/www/html/synthlab/data';
if (is_dir($web) || @mkdir($web, 0755, true)) {
    file_put_contents($web . '/cli-examples.json', $json);
    foreach (['catalog.json', 'correlation.json'] as $f) {
        if (is_readable($root . '/data/' . $f)) {
            copy($root . '/data/' . $f, $web . '/' . $f);
        }
    }
    echo "\n$web にも配置しました (公開ページ用)\n";
}
echo "data/cli-examples.json に保存しました\n";
