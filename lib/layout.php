<?php
declare(strict_types=1);

/**
 * 画面の外枠。左サイドバー + トップバーの構成です。
 */
function render_head(string $pageName, string $active = ''): void
{
    $admin = current_admin();
    $nav = [
        'index'  => ['index.php',  'ダッシュボード', 'fa-gauge-high'],
        'shops'  => ['shops.php',  '店舗管理',       'fa-users'],
        'girls'  => ['girls.php',  'キャスト管理',   'fa-address-book'],
        'logs'   => ['logs.php',   '稼働ログ',       'fa-list-check'],
        'health' => ['health.php', 'データ点検',     'fa-triangle-exclamation'],
        'audit'  => ['audit.php',  '操作履歴',       'fa-clock-rotate-left'],
    ];
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($pageName) ?> — <?= h((string)cfg('site_name', '運営管理')) ?></title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;700&display=swap">
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/js/all.min.js" defer></script>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<div class="app-shell">

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="logo"><i class="fa-solid fa-chart-line"></i></div>
            <div>
                <div class="name"><?= h((string)cfg('site_name', '運営管理')) ?></div>
                <div class="sub"><?= h((string)cfg('site_sub', 'Owner Console')) ?></div>
            </div>
        </div>
        <div class="sidebar-label">MENU</div>
        <nav class="sidebar-nav">
            <?php foreach ($nav as $key => [$href, $labelText, $icon]): ?>
                <a href="<?= h($href) ?>"<?= $key === $active ? ' class="on"' : '' ?>>
                    <i class="fa-solid <?= h($icon) ?>"></i><?= h($labelText) ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <div class="main-area">
        <header class="topbar">
            <button class="sidebar-toggle" id="sidebar-toggle" type="button" aria-label="メニュー">
                <i class="fa-solid fa-bars"></i>
            </button>
            <div class="page-name"><?= h($pageName) ?></div>
            <div class="me">
                <?php if ($admin): ?>
                    <span class="who"><i class="fa-solid fa-circle-user"></i><?= h($admin['name']) ?></span>
                    <a class="btn btn-outline btn-sm" href="logout.php"><i class="fa-solid fa-right-from-bracket"></i>ログアウト</a>
                <?php endif; ?>
            </div>
        </header>
        <main class="page-content">
<?php if (!cfg('allow_edit', false)): ?>
    <div class="alert-box alert-warn">
        <i class="fa-solid fa-lock"></i>
        <div>閲覧専用モードです。編集するには config.php の allow_edit を true にしてください。</div>
    </div>
<?php endif; ?>
<?php
}

function render_foot(): void
{
    ?>
        </main>
    </div>
</div>
<script>
(function () {
    var btn = document.getElementById('sidebar-toggle');
    var bar = document.getElementById('sidebar');
    var ov  = document.getElementById('sidebar-overlay');
    if (!btn) { return; }
    function close() { bar.classList.remove('open'); ov.classList.remove('show'); }
    btn.addEventListener('click', function () {
        bar.classList.toggle('open');
        ov.classList.toggle('show');
    });
    ov.addEventListener('click', close);
})();
</script>
</body>
</html>
<?php
}

/** 画面上部に出す一言メッセージ */
function flash(string $msg, string $type = 'info'): void
{
    $icons = ['info' => 'fa-circle-info', 'ok' => 'fa-circle-check', 'warn' => 'fa-triangle-exclamation', 'bad' => 'fa-circle-exclamation'];
    echo '<div class="alert-box alert-' . h($type) . '"><i class="fa-solid ' . h($icons[$type] ?? 'fa-circle-info') . '"></i><div>' . h($msg) . '</div></div>';
}

/** パンくず */
function crumbs(array $items): void
{
    echo '<div class="crumbs">';
    $last = count($items) - 1;
    $i = 0;
    foreach ($items as $labelText => $href) {
        if ($i > 0) {
            echo '<span>/</span>';
        }
        if ($href === null || $i === $last) {
            echo h((string)$labelText);
        } else {
            echo '<a href="' . h((string)$href) . '">' . h((string)$labelText) . '</a>';
        }
        $i++;
    }
    echo '</div>';
}

/** サマリーカード1枚 */
function summary_card(string $labelText, string $value, string $icon, string $mod = ''): void
{
    echo '<div class="summary-card' . ($mod !== '' ? ' is-' . h($mod) : '') . '">'
       . '<div class="icon"><i class="fa-solid ' . h($icon) . '"></i></div>'
       . '<div><div class="n">' . h($value) . '</div><div class="k">' . h($labelText) . '</div></div>'
       . '</div>';
}
