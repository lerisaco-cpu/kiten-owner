<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
require_login();

$activeValue = (int)cfg('status_active_value', 1);
$staleHours  = (int)cfg('stale_hours', 6);
$hasStatus   = table_exists('exec_status');
$hasLog      = table_exists('exec_log');

$shopTotal  = (int)qv('SELECT COUNT(*) FROM users');
$shopActive = (int)qv('SELECT COUNT(*) FROM users WHERE status = ?', [$activeValue]);
$girlTotal  = (int)qv('SELECT COUNT(*) FROM kiten_girl');

$girlRunning = (int)qv(
    'SELECT COUNT(*) FROM kiten_girl g
       JOIN users u ON u.user_id = g.shopr_id
      WHERE u.status = ? AND g.kitengirl_status = 1',
    [$activeValue]
);

$stale = [];
$recentFails = [];
$todayOk = $todayFail = null;

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
}

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

render_head('ダッシュボード', 'index');
?>

<div class="summary-row">
    <?php
    summary_card('契約中の店舗', number_format($shopActive), 'fa-store');
    summary_card('停止中の店舗', number_format($shopTotal - $shopActive), 'fa-store-slash');
    summary_card('稼働キャスト', number_format($girlRunning), 'fa-user-check');
    summary_card('登録キャスト総数', number_format($girlTotal), 'fa-address-book');
    if ($hasLog) {
        summary_card('本日の成功', number_format((int)$todayOk), 'fa-circle-check');
        summary_card('本日の失敗', number_format((int)$todayFail), 'fa-circle-exclamation', $todayFail > 0 ? 'alert' : '');
    }
    ?>
</div>

<?php if (!$hasStatus || !$hasLog): ?>
    <div class="alert-box alert-warn">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div>稼働監視のテーブルがまだありません。VPSのプログラムから exec_log と exec_status に書き込む処理を追加すると、この画面に稼働状況が出ます。</div>
    </div>
<?php endif; ?>

<?php if ($hasStatus): ?>
<div class="section-card">
    <div class="head">
        <i class="fa-solid fa-clock"></i><?= (int)$staleHours ?>時間以上、成功していない契約中の店舗
        <span class="count">全 <?= number_format(count($stale)) ?> 件</span>
    </div>
    <?php if (!$stale): ?>
        <div class="empty"><i class="fa-solid fa-circle-check"></i>契約中の店舗はすべて直近で成功しています。</div>
    <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
        <thead>
        <tr>
            <th style="width:70px">ID</th>
            <th>店舗名</th>
            <th style="width:130px">最終成功</th>
            <th style="width:120px">最終実行</th>
            <th style="width:90px">連続失敗</th>
            <th>直近のエラー</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($stale as $r): ?>
            <tr>
                <td class="id-col"><?= (int)$r['user_id'] ?></td>
                <td class="strong"><a href="shop_edit.php?id=<?= (int)$r['user_id'] ?>"><?= h($r['username']) ?></a></td>
                <td>
                    <?php if ($r['last_success_at']): ?>
                        <span class="badge badge-warn"><?= h(ago($r['last_success_at'])) ?></span>
                    <?php else: ?>
                        <span class="badge badge-danger">記録なし</span>
                    <?php endif; ?>
                </td>
                <td class="muted"><?= h(ago($r['last_exec_at'])) ?></td>
                <td class="num"><?= (int)$r['fail_streak'] ?></td>
                <td class="wrapcell muted"><?= h(mb_strimwidth((string)$r['last_message'], 0, 90, '…')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($hasLog && $recentFails): ?>
<div class="section-card">
    <div class="head"><i class="fa-solid fa-circle-exclamation"></i>直近の失敗</div>
    <div class="table-scroll">
    <table class="data-table">
        <thead>
        <tr>
            <th style="width:150px">日時</th>
            <th style="width:60px">ID</th>
            <th>店舗名</th>
            <th style="width:120px">処理</th>
            <th>内容</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($recentFails as $r): ?>
            <tr>
                <td class="muted"><?= h((string)$r['started_at']) ?></td>
                <td class="id-col"><?= (int)$r['user_id'] ?></td>
                <td class="strong"><a href="shop_edit.php?id=<?= (int)$r['user_id'] ?>"><?= h((string)$r['username']) ?></a></td>
                <td><?= h((string)$r['job_type']) ?></td>
                <td class="wrapcell"><?= h(mb_strimwidth((string)$r['message'], 0, 120, '…')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<div class="section-card">
    <div class="head"><i class="fa-solid fa-layer-group"></i>プラン別の内訳</div>
    <div class="table-scroll">
    <table class="data-table">
        <thead>
        <tr><th>プラン</th><th class="num" style="width:130px">契約中</th><th class="num" style="width:130px">停止</th><th class="num" style="width:130px">合計</th></tr>
        </thead>
        <tbody>
        <?php foreach ($planRows as $k => $v): ?>
            <tr>
                <td class="strong"><?= h(label_of('plan_labels', $k)) ?></td>
                <td class="num"><?= number_format($v['active']) ?></td>
                <td class="num muted"><?= number_format($v['inactive']) ?></td>
                <td class="num"><?= number_format($v['active'] + $v['inactive']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php render_foot(); ?>
