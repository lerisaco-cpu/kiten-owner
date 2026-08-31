<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
require_login();

$activeValue  = (int)cfg('status_active_value', 1);
$validServers = (array)cfg('valid_exeservers', [0, 1, 2, 3]);
if (!$validServers) {
    $validServers = [0];
}
$placeholders = implode(',', array_fill(0, count($validServers), '?'));

$orphans = q(
    'SELECT g.shopr_id, COUNT(*) AS n, SUM(g.kitengirl_status = 1) AS active_n
       FROM kiten_girl g
       LEFT JOIN users u ON u.user_id = g.shopr_id
      WHERE u.user_id IS NULL
      GROUP BY g.shopr_id
      ORDER BY n DESC'
);

$stoppedButRunning = q(
    'SELECT u.user_id, u.username, COUNT(*) AS n
       FROM kiten_girl g
       JOIN users u ON u.user_id = g.shopr_id
      WHERE u.status <> ? AND g.kitengirl_status = 1
      GROUP BY u.user_id, u.username
      ORDER BY n DESC
      LIMIT 100',
    [$activeValue]
);

$badServer = q(
    "SELECT user_id, username, status, exeserver FROM users
      WHERE exeserver NOT IN ($placeholders)
      ORDER BY user_id",
    $validServers
);

$noGirls = q(
    'SELECT u.user_id, u.username, u.tantou
       FROM users u
       LEFT JOIN kiten_girl g ON g.shopr_id = u.user_id
      WHERE u.status = ? AND g.kitengirl_id IS NULL
      ORDER BY u.user_id',
    [$activeValue]
);

$orphanRows = 0;
foreach ($orphans as $o) {
    $orphanRows += (int)$o['n'];
}

render_head('データ点検', 'health');
?>

<div class="summary-row">
    <?php
    summary_card('店舗が無いキャスト', number_format($orphanRows), 'fa-user-slash', $orphanRows ? 'alert' : '');
    summary_card('停止店舗に稼働キャスト', number_format(count($stoppedButRunning)), 'fa-store-slash', $stoppedButRunning ? 'alert' : '');
    summary_card('実行サーバー値が不正', number_format(count($badServer)), 'fa-server', $badServer ? 'alert' : '');
    summary_card('契約中でキャスト0件', number_format(count($noGirls)), 'fa-circle-question', $noGirls ? 'warn' : '');
    ?>
</div>

<div class="section-card">
    <div class="head"><i class="fa-solid fa-user-slash"></i>店舗が存在しないキャスト</div>
    <div class="body">
        <p class="muted" style="margin:0">店舗を削除したときにキャストが残った跡です。集計の分母を狂わせるので、消すか、退避用の店舗IDにまとめるかを決めてください。</p>
    </div>
    <?php if (!$orphans): ?>
        <div class="empty"><i class="fa-solid fa-circle-check"></i>ありません。</div>
    <?php else: ?>
        <div class="table-scroll">
        <table class="data-table">
            <thead><tr><th style="width:130px">shopr_id</th><th class="num" style="width:120px">件数</th><th class="num" style="width:140px">うち稼働中</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($orphans as $o): ?>
                <tr>
                    <td class="id-col"><?= (int)$o['shopr_id'] ?></td>
                    <td class="num"><?= number_format((int)$o['n']) ?></td>
                    <td class="num"><?= (int)$o['active_n'] > 0 ? '<span class="badge badge-danger">' . (int)$o['active_n'] . '</span>' : '0' ?></td>
                    <td class="act"><a class="btn btn-link btn-sm" href="girls.php?shop=<?= (int)$o['shopr_id'] ?>">一覧</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<div class="section-card">
    <div class="head"><i class="fa-solid fa-store-slash"></i>停止中の店舗に、稼働中のキャストが残っている</div>
    <div class="body">
        <p class="muted" style="margin:0">契約が切れているのにキャスト側が稼働のままです。プログラムが店舗の契約状況を見ずにキャスト状態だけで動いていると、解約後も処理が続きます。</p>
    </div>
    <?php if (!$stoppedButRunning): ?>
        <div class="empty"><i class="fa-solid fa-circle-check"></i>ありません。</div>
    <?php else: ?>
        <div class="table-scroll">
        <table class="data-table">
            <thead><tr><th style="width:70px">ID</th><th>店舗名</th><th class="num" style="width:150px">稼働キャスト</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($stoppedButRunning as $r): ?>
                <tr>
                    <td class="id-col"><?= (int)$r['user_id'] ?></td>
                    <td class="strong"><a href="shop_edit.php?id=<?= (int)$r['user_id'] ?>"><?= h($r['username']) ?></a></td>
                    <td class="num"><?= number_format((int)$r['n']) ?></td>
                    <td class="act"><a class="btn btn-link btn-sm" href="girls.php?shop=<?= (int)$r['user_id'] ?>&gstat=1">一覧</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<div class="section-card">
    <div class="head"><i class="fa-solid fa-server"></i>実行サーバーの値が想定外</div>
    <div class="body">
        <p class="muted" style="margin:0">設定として使われない値が入っています。別の項目の値が誤って書き込まれた可能性があります。</p>
    </div>
    <?php if (!$badServer): ?>
        <div class="empty"><i class="fa-solid fa-circle-check"></i>ありません。</div>
    <?php else: ?>
        <div class="table-scroll">
        <table class="data-table">
            <thead><tr><th style="width:70px">ID</th><th>店舗名</th><th style="width:100px">契約</th><th style="width:140px">exeserver</th></tr></thead>
            <tbody>
            <?php foreach ($badServer as $r): ?>
                <tr>
                    <td class="id-col"><?= (int)$r['user_id'] ?></td>
                    <td class="strong"><a href="shop_edit.php?id=<?= (int)$r['user_id'] ?>"><?= h($r['username']) ?></a></td>
                    <td><span class="badge <?= (int)$r['status'] === $activeValue ? 'badge-active' : 'badge-inactive' ?>"><?= h(label_of('status_labels', $r['status'])) ?></span></td>
                    <td><span class="badge badge-danger"><?= (int)$r['exeserver'] ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<div class="section-card">
    <div class="head"><i class="fa-solid fa-circle-question"></i>契約中だが、キャストが1件も登録されていない</div>
    <?php if (!$noGirls): ?>
        <div class="empty"><i class="fa-solid fa-circle-check"></i>ありません。</div>
    <?php else: ?>
        <div class="table-scroll">
        <table class="data-table">
            <thead><tr><th style="width:70px">ID</th><th>店舗名</th><th style="width:150px">担当</th></tr></thead>
            <tbody>
            <?php foreach ($noGirls as $r): ?>
                <tr>
                    <td class="id-col"><?= (int)$r['user_id'] ?></td>
                    <td class="strong"><a href="shop_edit.php?id=<?= (int)$r['user_id'] ?>"><?= h($r['username']) ?></a></td>
                    <td><?= h($r['tantou']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<?php render_foot(); ?>
