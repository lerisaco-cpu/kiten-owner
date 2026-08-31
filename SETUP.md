# 設置手順書

`~/public_html/kiten/owner` に管理画面を置くまでの手順です。
上から順にやれば終わります。1ステップずつ、終わってから次に進んでください。

所要時間はだいたい30分です。

---

## 用意するもの

- GitHubのアカウント（`lerisaco-cpu`）
- カゴヤのphpMyAdmin
- カゴヤのSSH鍵（`C:\ssh\private.key`）
- WinSCP
- 受け取ったZIPファイル

---

## ステップ1　ZIPを展開する

受け取った `owner-admin.zip` を、デスクトップなど分かりやすい場所に展開します。

展開すると `owner` というフォルダができて、中に `index.php` や `lib` などが入っています。

**確認：** `owner` フォルダを開いて、`index.php` と `README.md` があればOKです。

---

## ステップ2　GitHubにリポジトリを作る

1. ブラウザで https://github.com/new を開く
2. 「Repository name」に `kiten-owner` と入力
3. 「Private」を選ぶ（**ここは必ずPrivateにしてください**）
4. 「Create repository」を押す

作成後に表示されるURLをメモしておきます。こういう形です。

```
https://github.com/lerisaco-cpu/kiten-owner.git
```

**確認：** 空のリポジトリのページが表示されていればOKです。

---

## ステップ3　ファイルをGitHubに上げる

Claude Codeに次のように頼むのが一番早いです。

```
デスクトップの owner フォルダの中身を、
https://github.com/lerisaco-cpu/kiten-owner.git に push してください。
owner フォルダごとではなく、中身をリポジトリのルートに置いてください。
```

**確認：** GitHubのページを再読み込みして、`index.php` や `lib` が一覧に並んでいればOKです。
`owner` というフォルダが1つだけ表示されている場合は、階層が1つ深くなっています。
Claude Codeに「中身をルートに移動して」と伝えて直してください。

---

## ステップ4　データベースにテーブルを作る

1. カゴヤのphpMyAdminにログインする
2. 左側で、`kiten` のシステムが使っているデータベース名をクリックする
   （`users` と `kiten_girl` が入っているデータベースです）
3. 上のタブから「SQL」をクリックする
4. 展開したフォルダの `sql/schema.sql` をメモ帳で開き、**中身を全部コピー**する
5. phpMyAdminの入力欄に貼り付けて、右下の「実行」を押す

**確認：** 緑色で成功のメッセージが出て、左側のテーブル一覧に
`admin_users` `exec_log` `exec_status` `admin_audit_log` の4つが増えていればOKです。

> **もしエラーが出たら**
> 「Duplicate key name 'idx_shopr'」と出た場合は、そのインデックスが既にあるという意味なので、
> 無視して問題ありません。それ以外のエラーが出たら、メッセージをそのまま控えておいてください。

---

## ステップ5　DBの接続情報を調べる

管理画面がデータベースに繋ぐための情報が必要です。既存システムのファイルに書いてあります。

1. WinSCPでカゴヤに接続する（ホスト `lerisa.kir.jp` / ポート `10022` / 鍵 `C:\ssh\private.key`）
2. `public_html/kiten` を開く
3. 中にある設定ファイルらしきもの（`config.php`、`db.php`、`common.php` など）を探して開く

次の4つが書いてあるはずなので、メモ帳に控えます。

| 探すもの | 例 |
|---|---|
| ホスト名 | `mysql57s-32.kagoya.net` |
| データベース名 | `lerisa_kiten` |
| ユーザー名 | `lerisa_kiten` |
| パスワード | （伏せ字にせずそのまま） |

**この4つは、メールやチャットに貼らないでください。** メモ帳に置いておくだけにします。

---

## ステップ6　config.php を作る

1. 展開したフォルダの中の `config.sample.php` をコピーして、同じ場所に貼り付ける
2. コピーしたファイルの名前を **`config.php`** に変える
3. `config.php` をメモ帳で開く
4. 上のほうにある次の4行を、ステップ5で控えた値に書き換える

```php
    'db' => [
        'host' => 'mysql57s-XX.kagoya.net',   ← ホスト名
        'name' => 'lerisa_xxxxx',             ← データベース名
        'user' => 'lerisa_xxxxx',             ← ユーザー名
        'pass' => '',                          ← パスワード
    ],
```

5. あわせて、サイドバーに出す名前も変えられます

```php
    'site_name' => '運営管理',          ← サイドバー上部の大きい文字
    'site_sub'  => 'Owner Console',    ← その下の小さい文字
```

6. 上書き保存する

> **文字コードに注意**
> メモ帳で保存するとき、下の「文字コード」が **UTF-8** になっていることを確認してください。
> 「ANSI」だと日本語が化けます。

**確認：** `config.php` というファイルができていればOKです。
このファイルはGitHubには上げません。次のステップで直接サーバーに置きます。

---

## ステップ7　サーバーにファイルを置く

WinSCPでカゴヤに接続した状態から始めます。

1. WinSCPの上部メニューから「コマンド」→「PuTTYを開く」を選ぶ
   （PuTTYが無い場合は、Windowsのコマンドプロンプトを開いて次を実行）

```
ssh -p 10022 -i C:\ssh\private.key lerisa@lerisa.kir.jp
```

2. 接続できたら、次を1行ずつ実行します（1行入れてEnter、を繰り返す）

```
cd ~/public_html/kiten
```

```
ls
```

ここで表示された一覧に **`owner` が無いこと**を確認してください。
もし既に `owner` があったら、そのまま進めず一度止めて相談してください。

```
git clone https://github.com/lerisaco-cpu/kiten-owner.git owner
```

GitHubのユーザー名とパスワードを聞かれたら、パスワードの代わりに
**Personal Access Token** を入力します。持っていない場合は、
リポジトリをPublicに一時変更してcloneし、終わったらPrivateに戻す方法でも構いません。

**確認：** `ls owner` を実行して、`index.php` などが表示されればOKです。

---

## ステップ8　config.php をアップロードする

`config.php` はGitHubに含まれていないので、手で置きます。

1. WinSCPの右側（サーバー側）で `public_html/kiten/owner` を開く
2. 左側（自分のPC）で、ステップ6で作った `config.php` を探す
3. `config.php` を右側にドラッグして置く

**確認：** サーバー側の `owner` フォルダに `config.php` が並んでいればOKです。

---

## ステップ9　ログインアカウントを作る

ステップ7のSSH画面に戻ります。

```
cd ~/public_html/kiten/owner
```

```
php tools/make_admin.php owner オーナー ここにパスワード
```

`ここにパスワード` の部分を、自分で決めた**10文字以上**のパスワードに置き換えて実行します。
記号を使う場合は、パスワード全体をダブルクォートで囲んでください。

**確認：** `作成しました: owner（オーナー権限）` と表示されればOKです。

> **`php: command not found` と出たら**
> カゴヤではPHPのパスを指定する必要がある場合があります。次を試してください。
> ```
> /usr/local/bin/php tools/make_admin.php owner オーナー ここにパスワード
> ```

---

## ステップ10　ブラウザで開く

```
https://lerisa.kir.jp/kiten/owner/
```

ログイン画面が出たら、ステップ9で作ったID（`owner`）とパスワードで入ります。

**確認：** 「概要」の画面が表示され、契約中の店舗数などの数字が出ていれば設置完了です。

---

## ステップ11　後片付け

SSH画面で次を実行して、アカウント作成用のスクリプトを消します。

```
rm -rf ~/public_html/kiten/owner/tools
```

---

## これで完了です

以後、画面を修正したときの反映は、自宅PCのコマンドプロンプトからこの1行だけです。

```
ssh -p 10022 -i C:\ssh\private.key lerisa@lerisa.kir.jp "cd ~/public_html/kiten/owner && git pull origin main"
```

---

## うまくいかないとき

| 症状 | 原因と対処 |
|---|---|
| 真っ白な画面 | PHPのバージョンが古い可能性があります。カゴヤのコントロールパネルで、そのディレクトリのPHPが7.4以上か確認してください |
| 「config.php がありません」 | ステップ8のアップロード先が違います。`owner` フォルダの直下に置いてください |
| 「データベースに接続できません」 | ステップ6で書いた4つの値のどれかが違います。特にホスト名の数字部分を見直してください |
| ログインしても弾かれる | ステップ9をやり直してください。IDを変えて作り直しても構いません |
| 日本語が「譁ｸ蟄励・」のように化ける | `config.php` をUTF-8で保存し直してアップロードし直してください |
| 数字は出るがプラン名がおかしい | 想定した仮の名前です。`config.php` の `plan_labels` を実際のプラン名に書き換えてください |

エラーメッセージが出た場合は、その文言をそのまま伝えてください。
どのステップで何が表示されたかが分かれば、原因を絞れます。
