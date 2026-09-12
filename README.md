# オーナー管理画面

契約店舗（`users`）とキャスト（`kiten_girl`）を運営側から見るための画面です。
カゴヤ共用サーバーに置き、カゴヤのMySQLを直接読みます。

## 画面

| ファイル | 内容 |
|---|---|
| `index.php` | 概要。契約数、稼働キャスト数、止まっている店舗、直近の失敗 |
| `shops.php` | 店舗一覧。契約状況・プラン・キャスト数・最終成功で絞り込みと並び替え |
| `shop_edit.php` | 店舗詳細。契約状況とプランの変更、システム設定の確認、直近ログ |
| `girls.php` | キャスト横断一覧。店舗・状態・名前で絞り込み |
| `shop_logs.php` | 店舗ごとの iMacros 稼働ログ。店舗一覧・店舗詳細から開く |
| `health.php` | データ点検。孤立キャスト、停止店舗の稼働キャストなど |
| `audit.php` | 運営側の操作履歴 |

## 設置手順

### 1. DBにテーブルを作る

phpMyAdmin で `sql/schema.sql` を実行します。作られるのは4つのテーブルと、
`kiten_girl` へのインデックス2本です。**既存テーブルのカラムは一切変更しません。**

インデックスは 15,893 行に対する追加なので数秒で終わります。カラムを増やさないため、
稼働中のプログラムの SELECT / INSERT には影響しません。

### 2. 設定ファイルを作る

`config.sample.php` を `config.php` にコピーして、DB接続情報を書き込みます。
`config.php` は `.gitignore` に入っているので、GitHubには上がりません。

あわせて次の3つを実際の値に合わせてください。

- `plan_labels` — `ktype` 0〜4 の実際のプラン名
- `girl_status_labels` — `kitengirl_status` 0/1/2 の意味
- `status_active_value` — `users.status` のどちらが契約中か（既定は 1）

### 3. 最初のアカウントを作る

カゴヤにSSHで入り、次を実行します。

```
php tools/make_admin.php owner オーナー <10文字以上のパスワード>
```

実行後、`tools/` はドキュメントルート外へ移すか削除してください。

### 4. アクセス制限をかける

`.htaccess` は設定ファイルと `lib/` への直接アクセスを止めますが、画面そのものは
ログインだけで守られています。運営専用なので、Basic認証かIP制限を重ねてください。

### 5. 編集を有効にする

初期状態は `allow_edit => false` の閲覧専用です。稼働中システムの設定テーブルを
書き換えることになるので、表示内容が正しいことを確認してから `true` にしてください。

編集できるのは `status` / `ktype` / `tantou` / `tel` / `bank` / `etc` / `exeserver` の7項目だけです。
動作そのものを変える設定（`cmnmsg`、`optionfst`、`license` など）は表示のみにしてあります。

## 稼働ログについて

`exec_log` と `exec_status` は、作っただけでは空のままです。
VPS側のプログラムに、1処理ごとに次を実行する処理を足してください。

```sql
INSERT INTO exec_log (user_id, kitengirl_id, job_type, result, message, exeserver, duration_ms, started_at)
VALUES (?, ?, ?, ?, ?, ?, ?, ?);
```

あわせて店舗別サマリーを更新します。一覧表示はこの1行だけを読むので、
ログが何百万件になっても店舗一覧の表示速度は変わりません。

成功時:

```sql
INSERT INTO exec_status (user_id, last_exec_at, last_success_at, last_result, last_message, fail_streak)
VALUES (?, NOW(), NOW(), 1, NULL, 0)
ON DUPLICATE KEY UPDATE
  last_exec_at = NOW(), last_success_at = NOW(),
  last_result = 1, last_message = NULL, fail_streak = 0;
```

失敗時:

```sql
INSERT INTO exec_status (user_id, last_exec_at, last_result, last_message, fail_streak)
VALUES (?, NOW(), 0, ?, 1)
ON DUPLICATE KEY UPDATE
  last_exec_at = NOW(), last_result = 0,
  last_message = VALUES(last_message), fail_streak = fail_streak + 1;
```

ログは増え続けるので、90日を超えたものを定期的に消す運用にしてください。

```sql
DELETE FROM exec_log WHERE started_at < DATE_SUB(NOW(), INTERVAL 90 DAY) LIMIT 10000;
```

## 前提としている仕様

データから読み取れなかった部分は、次のように仮定しています。違っていれば `config.php` で直せます。

- `users.status` は 1 が契約中、0 が停止
- `users.ktype` がプラン区分
- `kiten_girl.kitengirl_status` は 1 が稼働中
- `users.exeserver` の正常値は 0〜3

## 注意点

`kiten_girl` の `himedeco_pw` と `ekipw` は平文で保存されています。
この画面では伏せ字にし、「表示」を押したときだけ1件ずつ出す形にしました。
押した事実は操作履歴に残ります。
