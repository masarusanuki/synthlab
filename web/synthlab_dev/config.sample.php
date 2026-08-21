<?php
// config.sample.php -- 認証設定の雛形
//
// これを config.php にコピーして hash を入れ替える。
// ハッシュの作り方:
//   php -r 'echo password_hash("新しいパスワード", PASSWORD_DEFAULT), "\n";'
//
// config.php は .gitignore で除外している (ハッシュを持つため)。
// 直接 HTTP アクセスされても 404 を返すよう定数ガードを入れてある。
if (!defined('SYNTHLAB_DEV')) { http_response_code(404); exit; }

return [
    'user' => 'dev',   // 空文字 '' にするとユーザ名の入力欄を出さない
    'hash' => '＜password_hash() の出力をここに＞',
];
