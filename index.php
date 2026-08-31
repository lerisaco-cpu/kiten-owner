<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
require_login();

$activeValue = (int)cfg('status_active_value', 1);
$staleHours  = (int)cfg('stale_hours', 6);
$hasStatus   = table_exists('exec_status');
$hasLog      = table_exists('exec_log');

// --- 契約状況 -------------------------------------------------------
$shopTotal  = (int)qv('SELECT COUNT(*) FROM users');
$shopActive = (int)qv('SELECT COUNT(*) FROM users WHERE status = ?', [$activeValue]);
$girlTotal  = (int)qv('SELECT COUNT(*) FROM kiten_girl');
$girlActive = (int)qv('SELECT COUNT(*) FROM kiten_girl WHERE kitengirl_status = 1');

// 契約中の店舗に属する稼働キャストだけを数える（実際に動いている量）
$girlRunning = (int)qv(
    'SELECT COUNT(*) FROM kiten_girl g
      JOIN users u ON u.user_id = g.shopr_id
     WHERE u.status = ? AND g.kitengirl_status = 1',
    [$activeValue]
);

// --- 稼働状況 -------------------------------------------------------
$stale = [];
$recentFails = [];
if ($hasStatus) {
    $stale = q(
        'SELECT u.user_id, u.username, s.last_success_at, s.last_exec_at, s.fail_streak, s.last_message
           FROM users u
           LEFT JOIN exec_status s ON s.user_id = u.user_id
          WHERE u.status = ?
            AND (s.last_success_at IS NULL OR s.last_success_at < DATE_SUB(NOW(), INTERVAL ? HOUR))
          ORDER BY s.last_success_at IS NULL DESC, s.last_success_at ASC
          LIMIT 30',
        [$activeValue, $staleHours]
    );
}
if ($hasLog) {
    $recentFails = q(
        'SELECT l.id, l.user_id, u.username, l.job_type, l.message, l.started_at
           FROM exec_log l
           LEFT JOIN users u ON u.user_id = l.user_id
          WHERE l.result = 0
          ORDER BY l.started_at DESC
          LIMIT 15'
    );
    $todayOk   = (int)qv('SELECT COUNT(*) FROM exec_log WHERE result = 1 AND started_at >= CURDATE()');
    $todayFail = (int)qv('SELECT COUNT(*) FROM exec_log WHERE result = 0 AND started_at >= CURDATE()');
} else {
    $todayOk = $todayFail = null;
}

// --- プラン別 -------------------------------------------------------
$byPlan = q('SELECT ktype, status, COUNT(*) AS n FROM users GROUP BY ktype, status ORDER BY ktype');
$planRows = [];
foreach ($byPlan as $r) {
    $k = (int)$r['ktype'];
    $planRows[$k]['active']   = $planRows[$k]['active']   ?? 0;
    $planRows[$k]['inactive'] = $planRows[$k]['inactive'] ?? 0;
    if ((int)$r['status'] === $activeValue) {
        $planRows[$k]['active'] += (int)$r['n'];
    } else {
        $planRows[$k]['inactive'] += (int)$r['n'];
    }
}

render_head('概要', 'index');
?>

<div class="stats">
    <div class="stat"><div class="n"><?= number_format($shopActive) ?></div><div class="k">契約中の店舗</div></div>
    <div class="stat"><div class="n"><?= number_format($shopTotal - $shopActive) ?></div><div class="k">停止中の店舗</div></div>
    <div class="stat"><div class="n"><?= number_format($girlRunning) ?></div><div class="k">稼働キャスト（契約中の店舗）</div></div>
    <div class="stat"><div class="n"><?= number_format($girlTotal) ?></div><div class="k">登録キャスト総数</div></div>
    <?php if ($hasLog): ?>
        <div class="stat"><div class="n"><?= number_format((int)$todayOk) ?></div><div class="k">本日の成功</div></div>
        <div class="stat<?= $todayFail > 0 ? ' alert' : '' ?>"><div class="n"><?= number_format((int)$todayFail) ?></div><div class="k">本日の失敗</div></div>
    <?php endif; ?>
</div>

<?php if (!$hasStatus || !$hasLog): ?>
    <div class="notice notice-warn">
        稼働監視のテーブルがまだありません。sql/schema.sql を実行し、VPSのプログラムから exec_log と exec_status に書き込む処理を追加すると、この画面に稼働状況が出ます。
    </div>
<?php endif; ?>

<?php if ($hasStatus): ?>
<div class="panel">
    <h2><?= (int)$staleHours ?>時間以上、成功していない契約中の店舗</h2>
    <?php if (!$stale): ?>
        <div class="panel-body muted">ありません。契約中の店舗はすべて直近で成功しています。</div>
    <?php else: ?>
    <table class="grid">
        <thead>
        <tr>
            <th style="width:70px">ID</th>
            <th>店舗</th>
            <th style="width:130px">最終成功</th>
            <th style="width:130px">最終実行</th>
            <th style="width:90px" class="num">連続失敗</th>
            <th>直近のエラー</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($stale as $r): ?>
            <tr>
                <td class="id"><?= (int)$r['user_id'] ?></td>
                <td><a href="shop_edit.php?id=<?= (int)$r['user_id'] ?>"><?= h($r['username']) ?></a></td>
                <td><?php if ($r['last_success_at']): ?><span class="pill pill-warn"><?= h(ago($r['last_success_at'])) ?></span><?php else: ?><span class="pill pill-bad">記録なし</span><?php endif; ?></td>
                <td class="muted"><?= h(ago($r['last_exec_at'])) ?></td>
                <td class="num"><?= (int)$r['fail_streak'] ?></td>
                <td class="wrapcell muted"><?= h(mb_strimwidth((string)$r['last_message'], 0, 90, '…')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($hasLog && $recentFails): ?>
<div class="panel">
    <h2>直近の失敗</h2>
    <table class="grid">
        <thead>
        <tr>
            <th style="width:150px">日時</th>
            <th style="width:70px">ID</th>
            <th>店舗</th>
            <th style="width:120px">処理</th>
            <th>内容</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($recentFails as $r): ?>
            <tr>
                <td class="muted"><?= h((string)$r['started_at']) ?></td>
                <td class="id"><?= (int)$r['user_id'] ?></td>
                <td><a href="shop_edit.php?id=<?= (int)$r['user_id'] ?>"><?= h((string)$r['username']) ?></a></td>
                <td><?= h((string)$r['job_type']) ?></td>
                <td class="wrapcell"><?= h(mb_strimwidth((string)$r['message'], 0, 120, '…')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="panel">
    <h2>プラン別の内訳</h2>
    <table class="grid">
        <thead>
        <tr><th>プラン</th><th class="num" style="width:120px">契約中</th><th class="num" style="width:120px">停止</th><th class="num" style="width:120px">合計</th></tr>
        </thead>
        <tbody>
        <?php foreach ($planRows as $k => $v): ?>
            <tr>
                <td><?= h(label_of('plan_labels', $k)) ?></td>
                <td class="num"><?= number_format($v['active']) ?></td>
                <td class="num muted"><?= number_format($v['inactive']) ?></td>
                <td class="num"><?= number_format($v['active'] + $v['inactive']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php render_foot(); ?>
