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
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;700&display=swap">
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/js/all.min.js" defer></script>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="login-shell">
    <form class="login-box" method="post" action="login.php">
        <div class="brand">
            <div class="logo"><i class="fa-solid fa-chart-line"></i></div>
            <div>
                <div class="name"><?= h((string)cfg('site_name', '運営管理')) ?></div>
                <div class="sub"><?= h((string)cfg('site_sub', 'Owner Console')) ?></div>
            </div>
        </div>
        <?php if ($error !== ''): ?>
            <div class="alert-box alert-bad"><i class="fa-solid fa-circle-exclamation"></i><div><?= h($error) ?></div></div>
        <?php endif; ?>
        <?= csrf_field() ?>
        <label for="login_id">ID</label>
        <input type="text" id="login_id" name="login_id" autocomplete="username" autofocus required>
        <label for="password">パスワード</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
        <button class="btn btn-main" type="submit"><i class="fa-solid fa-right-to-bracket"></i>ログイン</button>
    </form>
</div>
</body>
</html>
