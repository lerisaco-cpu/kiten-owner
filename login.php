<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';

start_session();
if (current_admin() !== null) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $loginId  = trim((string)($_POST['login_id'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $admin = q1('SELECT * FROM admin_users WHERE login_id = ? AND is_active = 1', [$loginId]);

    if ($admin !== null && password_verify($password, $admin['password'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$admin['admin_id'];
        ex('UPDATE admin_users SET last_login = NOW() WHERE admin_id = ?', [$admin['admin_id']]);
        audit('login');
        header('Location: index.php');
        exit;
    }

    // IDとパスワードのどちらが違うかは伝えない
    $error = 'IDまたはパスワードが違います。';
    usleep(400000);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>ログイン — <?= h((string)cfg('site_name', '運営管理')) ?></title>
<link href="assets/app.css" rel="stylesheet">
</head>
<body>
<div class="login-page">
    <form class="login-box" method="post" action="login.php">
        <h1><?= h((string)cfg('site_name', '運営管理')) ?></h1>
        <?php if ($error !== ''): ?>
            <div class="notice notice-bad"><?= h($error) ?></div>
        <?php endif; ?>
        <?= csrf_field() ?>
        <label for="login_id">ID</label>
        <input type="text" id="login_id" name="login_id" autocomplete="username" autofocus required>
        <label for="password">パスワード</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
        <button type="submit">ログイン</button>
    </form>
</div>
</body>
</html>
