<?php
/**
 * 最初の管理者アカウントを作るためのスクリプト。
 *
 * カゴヤのSSHで実行します:
 *   php tools/make_admin.php <ログインID> <表示名> <パスワード>
 *
 * 実行後はこのファイルを消すか、tools/ ごとドキュメントルート外へ移してください。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('このスクリプトはコマンドラインからのみ実行できます。');
}

require __DIR__ . '/../lib/bootstrap.php';

$loginId  = $argv[1] ?? '';
$name     = $argv[2] ?? '';
$password = $argv[3] ?? '';

if ($loginId === '' || $name === '' || $password === '') {
    exit("使い方: php tools/make_admin.php <ログインID> <表示名> <パスワード>\n");
}
if (strlen($password) < 10) {
    exit("パスワードは10文字以上にしてください。\n");
}

$exists = qv('SELECT COUNT(*) FROM admin_users WHERE login_id = ?', [$loginId]);
if ((int)$exists > 0) {
    exit("そのログインIDは既に使われています。\n");
}

ex(
    'INSERT INTO admin_users (login_id, name, password, role, is_active) VALUES (?, ?, ?, 1, 1)',
    [$loginId, $name, password_hash($password, PASSWORD_DEFAULT)]
);

echo "作成しました: {$loginId}（オーナー権限）\n";
