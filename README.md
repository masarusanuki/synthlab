# SynthLab

`paper/` の4論文と `code/jamia26a.cpp` の解説、および論文の手法を実装した
擬似血液検査値生成ツール。

## 成果物の場所

### 公開サイト (認証なし)

| URL | 内容 |
|---|---|
| `/synthlab/` | 擬似検査値の生成 (トップ) |
| `/synthlab/about.php` | 手法の解説 (一般向け・数式は要点のみ) |
| `/synthlab/feedback.php` | 使用感・ご意見の投稿フォーム |

3ページのリンクは `lib/nav.php` の `sl_pages()` に一元化してあり、
全ページのヘッダとフッタに同じものが出る。ページを増やすときはここに1行足す。

### 開発サイト (パスワード認証)

| URL | 内容 |
|---|---|
| `/synthlab_dev/` | 技術解説の目次 (9本) |
| `/synthlab_dev/doc.php?id=...` | 各解説 (数式は MathJax で描画) |
| `/synthlab_dev/source.php` | **ソースコード閲覧** (C++ / Python / PHP、行番号・構文着色つき) |
| `/synthlab_dev/usage.php` | **アクセス履歴と生成内容のログ** (CSV 書き出し可) |
| `/synthlab_dev/feedback.php` | 公開フォームに寄せられた意見の一覧 |
| `/synthlab_dev/mailsetup.php` | **メール送信設定** (SMTP サーバ・パスワード・テスト送信) |

### ソース

| パス | 内容 |
|---|---|
| `doc_details/*.md` | 解説の原稿 (9本, Markdown) — **正本** |
| `backend/synthgen.cpp` | 依存関係ゼロの C++ サンプラ |
| `python/synthlab_ref.py` | numpy による参照実装 (論文と同じ言語) |
| `python/validate.py` | 3実装 + 棄却法の真値を突き合わせる検証スクリプト |
| `code/jamia26a.cpp` | 論文の実装 (Eigen / libconfig / libcsv が必要) |
| `tools/publish-docs.php` | 解説を dev サイトへ配置する |
| `tools/publish-code.php` | ソースを dev サイトへ配置する |

## 開発解説サイトのログイン

ユーザ名は既定で `dev`。**パスワードはリポジトリに含めない。**
稼働中のサーバでは `/var/www/html/synthlab_dev/config.php` に
`password_hash()` のハッシュだけが保存されている
(このファイルは `.gitignore` で除外)。

初回設定・変更の手順は次節のとおり。

### パスワードの設定・変更

```bash
php -r 'echo password_hash("新しいパスワード", PASSWORD_DEFAULT), "\n";'
```

出力を `/var/www/html/synthlab_dev/config.php` の `hash` に貼り替える。
`user` を空文字 `''` にするとユーザ名の入力欄が出なくなる (パスワードのみ)。

`config.php` の雛形は `web/synthlab_dev/config.sample.php` にある。
コピーしてハッシュを入れれば動く。

### 認証方式について

Apache の `/var/www/` ディレクティブが `AllowOverride None` で、
パスワードなしの sudo も通らないため、**`.htaccess` による Basic 認証は使えない**。
そのため PHP セッションによるアプリケーション認証にしている。

- 全ページの先頭で `auth.php` の `dev_require_login()` を呼ぶ
- パスワードは `password_hash()` のハッシュのみ保存 (平文はどこにもない)
- CSRF トークン、セッション ID の再生成、30分の無操作タイムアウト、
  失敗回数に応じた遅延を実装
- 解説本文 (`content/*.md.php`) と認証設定 (`config.php`) は
  **定数ガード**により直接 HTTP アクセスすると 404 を返す。
  認証を回避して読む経路はない

`sudo` が使える場合は Basic 認証に移行できる (モジュールは有効済み)。

```apache
# /etc/apache2/apache2.conf の <Directory /var/www/> を
AllowOverride AuthConfig
# に変更して reload したのち、
htpasswd -c /etc/apache2/.htpasswd_synthlab dev
```

```apache
# /var/www/html/synthlab_dev/.htaccess
AuthType Basic
AuthName "SynthLab dev"
AuthUserFile /etc/apache2/.htpasswd_synthlab
Require valid-user
```

その場合も `auth.php` の呼び出しは残して二重にしておくのが安全。

## ビルド

バックエンドは標準ライブラリのみで、外部依存はない。

```bash
g++ -std=c++17 -O2 -Wall -Wextra -o backend/synthgen backend/synthgen.cpp
install -m 755 backend/synthgen /var/www/html/synthlab/bin/synthgen
```

`code/jamia26a.cpp` (論文の実装) は Eigen3・libconfig・libcsv を必要とする。
**インストール済みなのでビルドできる。**

```bash
sudo apt install libeigen3-dev libconfig++-dev libcsv-dev   # 済
cd code && g++ -std=c++17 -O2 jamia26a.cpp -o jamia26a -lconfig -lcsv
./jamia26a jamia26a.cfg          # out/ に 5,000 標本と診断が出る
```

## 3実装の相互検証

```bash
cd code && ./jamia26a jamia26a.cfg     # 論文実装の出力を作る
cd ../python && python3 validate.py    # 3実装 + 棄却法の真値を比較
```

- A) Python 参照実装 `python/synthlab_ref.py`
- B) C++ バックエンド `backend/synthgen`
- C) 論文の実装 `code/jamia26a`
- D) numpy の棄却法で作った「真の切断分布」20万標本 ← 外部基準

**結果: A/B/C すべてが D と有意差なし** (2標本 KS 検定 $p = 0.17 \sim 0.95$)。
切断区間の違反 0 件、基準範囲の復元誤差 $2\times10^{-10}$。

相関は指定値より小さく出るが、これは**切断そのものの効果**である
(指定 0.1333 → 真の切断後 0.0648 → 各実装 0.065〜0.086)。
`validate.py` は「真の切断後」の列を出すので、実装のバグと誤診しない。

## 解説を更新する手順

`doc_details/*.md` が原稿の正本。編集したら公開しなおす。

```bash
php tools/publish-docs.php
```

各 `.md` が `content/<id>.md.php` として nowdoc に包まれて配置される。
往復検証 (包んで読み戻して原文と一致するか) が入っているので、
壊れた場合は非ゼロで終了する。

## バックエンドの単体実行

標準入力に spec、標準出力に CSV。

```bash
cat <<'EOF' | backend/synthgen
seed 42
npat 5
mode cross
burnin 400
item TG mg/dL 4.6881 0.4100 -8.6486 -inf inf 0
item HDL mg/dL 3.8019 0.2817 12.2143 -inf inf 0
corr 1.0 -0.45
corr -0.45 1.0
EOF
```

`backend/synthgen --help` で spec の全キーが出る。

## 解説の構成

| # | 文書 | 内容 |
|---|---|---|
| 00 | `00-index.md` | 総目次。4論文と実装を貫く一本の筋 |
| 01 | `01-medinfo2022-lognormal.md` | MEDINFO 2022。12種の分布比較と lnorm3 の選定 |
| 02 | `02-seibutsu-shiryo-2024.md` | 生物試料分析 2024。**実質的な主論文**。式1〜5 |
| 03 | `03-medinfo2025-timeseries.md` | MEDINFO 2025。Cholesky + 逆関数法の時系列合成 |
| 04 | `04-code-jamia26a.md` | `code/jamia26a.cpp` の実装解説 |
| 05 | `05-algorithm.md` | **数式レベルの統一的な導出** |
| 06 | `06-synthea-comparison.md` | Synthea との違い (AI による解釈) |
| 07 | `07-webapp-spec.md` | 本ツールの実装仕様と検証結果 |
| 08 | `08-devdiary.md` | 開発日記 |

論文は発表順ではなく **02 → 01 → 03** の順に読むのがわかりやすい。
MEDINFO の2本は2ページの extended abstract で数式がほとんど載っておらず、
和文の教育講演 (02) に式1〜5 がすべて書かれている。

## 手法の要点

3パラメータ対数正規分布

$$\mathrm{lnorm3}(x;\mu,\sigma,d) = \frac{1}{\sqrt{2\pi\sigma^2}(x-d)}\exp\left\{-\frac{(\log(x-d)-\mu)^2}{2\sigma^2}\right\}$$

は $X = d + e^Z,\ Z\sim\mathcal N(\mu,\sigma^2)$ と書けるので、
$d$ を引いて対数をとればあとは全部正規分布の話になる。これにより

- 偏差値が数値積分なしで計算できる
- Graphical LASSO が使える (正規分布を前提とする手法)
- Cholesky 分解で相関つきサンプリングができる
- 切断多変量正規分布の条件付き分布が閉じた形で書ける
- 基準範囲の両端と中央値の**三つの値**から $(\mu,\sigma,d)$ が閉じた形で復元できる

$$d = \frac{LU-M^2}{L+U-2M},\qquad \mu = \log(M-d),\qquad \sigma = \frac{1}{1.959964}\log\frac{U-d}{M-d}$$

最後の逆問題は論文に「分布が完全に推定できる」と書かれているが
閉じた解は示されていない。導出は `doc_details/05-algorithm.md` 2節にある。

## 出力される CSV の列

```
patient_id,sex,disease,disease_code,[cohort,][visit,week,]<検査項目...>[,z_<項目>...]
SL000001,M,2型糖尿病,t2dm,検証コホート,15.4,45.8,0.74,151,6.6,332,45
```

- `patient_id` は `SL` + 6桁。数字だけだと表計算ソフトが数値として扱い、
  実在の患者番号と誤認される危険があるため接頭辞を付けている
- `sex` は `M` / `F`。「男女混合」を選ぶと**男女それぞれの基準範囲で半数ずつ**生成する
  (基準範囲を平均してから当てはめるのは混合分布とは別物なので採らない)
- `disease` は日本語名、`disease_code` は識別子
- `cohort` はコホート名を入力した場合のみ
- 先頭に BOM を付けているので Excel で日本語がそのまま開ける
- `#` 始まりのコメント行に使用した $(\mu,\sigma,d)$・基準範囲・乱数種が入る。
  **同じ乱数種で同じデータが再現できる**

## メール通知の設定

ご意見が投稿されたとき通知メールを送れる。設定画面は
**`/synthlab_dev/mailsetup.php`** (要ログイン)。

- 依存関係ゼロの SMTP クライアント (`lib/mailer.php`) を実装している。
  STARTTLS (587) / 暗黙 SSL (465) / 平文、AUTH PLAIN / LOGIN に対応
- ローカル MTA (`sendmail`) 経由も選べるが、**このサーバの postfix は停止中**
  (`systemctl is-active postfix` → inactive)。外部 SMTP のほうが確実
- 設定画面に**テスト送信**があり、SMTP のやりとりがそのまま表示される
  (認証情報は記録しない)。Gmail に対して TLS 確立と AUTH 到達を確認済み
- 送信の成否は記録され、設定画面で確認できる

### 設定ファイル

`/var/www/html/synthlab_dev/config/mail.php`

- base64 化した JSON を定数ガード付き PHP に包む形式 → **HTTP から 404**
- `www-data` が作成し **`0600`** にするので、
  **www-data と root 以外は読めない** (`sanuki` からも読めない)
- SMTP 認証は平文パスワードを要するため可逆保持。
  **専用のアプリパスワードを使い、主アカウントのパスワードは入れないこと**

画面を使わずファイルで設定する手順は `mailsetup.php` の
「ファイルで設定する場合」に、コピー可能なコマンドとキー一覧を載せてある。

## アクセス・生成ログ

`/synthlab_dev/usage.php` (要ログイン) で閲覧。CSV 書き出し可。

記録する内容:

- **ページ閲覧**: どのページを、いつ、どこから
- **データ生成**: モード・人数・受診回数・性別・疾患・切断・相関強度・**乱数種**・
  項目一覧・行数・所要時間 → **後から同じデータを再現できる**
- **ご意見投稿**: 職種・目的・評価・本文の長さ
- **エラー**: 発生箇所とメッセージ

保存先は `/var/www/html/synthlab_dev/logs/ul_YYYYMMDD.php` (1日1ファイル)。
先頭に定数ガードと `__halt_compiler()` を置き、その後ろに
「1行 = base64 化した JSON」を追記する。追記が O(1) で競合しにくい。
ファイルは `0600` で `www-data` と root のみ読める。

`127.0.0.1` / `::1` からのアクセスは**記録しない**
(動作確認で埋まるのを避けるため)。`lib/usagelog.php` の
`UL_SKIP_IPS` で変えられる。

## ご意見フォームの保存先

投稿は `/var/www/html/synthlab_dev/feedback/` に保存される。
1件1ファイルで、**base64 化した JSON を定数ガード付きの PHP ファイルに包む**形式。

```php
<?php
if (!defined('SYNTHLAB_DEV')) { http_response_code(404); exit; }
return 'eyJ0cyI6IjIwMjYtMDgt...';
```

base64 は英数字と `+ / =` だけなので、投稿内容が PHP の文法を壊すことが原理的にない。
HTTP で直接叩くと 404 / 0 バイト。閲覧は `/synthlab_dev/feedback.php` (要ログイン)。

**パーミッションの注意**: `chown www-data` が sudo なしでできないため、
保存ディレクトリを `0707` にしている。HTTP からは読めないが
**同じマシンのローカルユーザからは読める**。sudo が使える環境では

```bash
sudo chown -R www-data:www-data /var/www/html/synthlab_dev/feedback
sudo chmod 0750 /var/www/html/synthlab_dev/feedback
```

としたほうがよい。

## 差し替えるべき箇所

本ツールは実データを持たないため、次の2つは論文の再現ではなく構成物である。
実運用する場合は差し替える。

| 対象 | 場所 | 差し替え先 |
|---|---|---|
| 基準範囲と中央値 | `/var/www/html/synthlab/lib/catalog.php` の `ri` | 論文の Zenodo データ (doi:10.5281/zenodo.3841232) |
| 項目間相関 | `/var/www/html/synthlab/lib/corr.php` の `synthlab_edges()` | 実データから推定した $\Sigma$ (GLASSO 後) |
| 疾患プリセット | `/var/www/html/synthlab/lib/disease.php` | 疫学データに基づく効果量 |

疾患プリセットは論文に含まれていない。詳細は
`doc_details/07-webapp-spec.md` と `doc_details/06-synthea-comparison.md` 4.3 節。

## 出典

1. Shiro M., Kagawa R. *Validity of Lognormal Distribution in Analyzing Laboratory
   Test Values.* Stud Health Technol Inform 290:1066–1067, 2022. doi:10.3233/SHTI220270
2. 城 真範. *血液検査値に偏差値をつける.* 生物試料分析 47(4):113–121, 2024
3. Furukawa T., Kagawa R., Sanuki M., Shiro M. *A Method of Synthesis of Time Series
   Data of Blood Test Values.* MEDINFO 2025:1854–1855. doi:10.3233/SHTI251248

倫理: 筑波大学附属病院 R1-080 / R01-080、自治医科大学 臨大16-変059・臨大15-096。

**生成されるデータは架空の数値であり、診断・治療・臨床判断には使用できない。**
