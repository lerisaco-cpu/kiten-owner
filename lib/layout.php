<?php
declare(strict_types=1);

/**
 * 画面の外枠。サイドバーの現在位置は $active で切り替えます。
 */
function render_head(string $title, string $active = ''): void
{
    $admin = current_admin();
    $nav = [
        'index'  => ['index.php',  '概要'],
        'shops'  => ['shops.php',  '店舗'],
        'girls'  => ['girls.php',  'キャスト'],
        'logs'   => ['logs.php',   '稼働ログ'],
        'health' => ['health.php', 'データ点検'],
        'audit'  => ['audit.php',  '操作履歴'],
    ];
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> — <?= h((string)cfg('site_name', '運営管理')) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/app.css" rel="stylesheet">
</head>
<body>
<header class="topbar">
    <a class="brand" href="index.php"><?= h((string)cfg('site_name', '運営管理')) ?></a>
    <nav class="mainnav">
        <?php foreach ($nav as $key => [$href, $label]): ?>
            <a href="<?= h($href) ?>"<?= $key === $active ? ' class="on"' : '' ?>><?= h($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="me">
        <?php if ($admin): ?>
            <span><?= h($admin['name']) ?></span>
            <a href="logout.php">ログアウト</a>
        <?php endif; ?>
    </div>
</header>
<main class="wrap">
<h1 class="page-title"><?= h($title) ?></h1>
<?php if (!cfg('allow_edit', false)): ?>
    <p class="readonly-note">閲覧専用モードです。編集するには config.php の allow_edit を true にしてください。</p>
<?php endif; ?>
<?php
}

function render_foot(): void
{
    ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
}

/** 画面上部に出す一言メッセージ */
function flash(string $msg, string $type = 'info'): void
{
    echo '<div class="notice notice-' . h($type) . '">' . h($msg) . '</div>';
}
