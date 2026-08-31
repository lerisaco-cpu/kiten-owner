<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
require_login();

$activeValue = (int)cfg('status_active_value', 1);
$validServers = (array)cfg('valid_exeservers', [0, 1, 2, 3]);
$placeholders = implode(',', array_fill(0, max(1, count($validServers)), '?'));

// 1. 存在しない店舗を参照しているキャスト
$orphans = q(
    'SELECT g.shopr_id, COUNT(*) AS n, SUM(g.kitengirl_status = 1) AS active_n
       FROM kiten_girl g
       LEFT JOIN users u ON u.user_id = g.shopr_id
      WHERE u.user_id IS NULL
      GROUP BY g.shopr_id
      ORDER BY n DESC'
);

// 2. 停止店舗なのに稼働中のキャストが残っている
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

// 3. 実行サーバーの値が想定外
$badServer = q(
    "SELECT user_id, username, status, exeserver FROM users
      WHERE exeserver NOT IN ($placeholders)
      ORDER BY user_id",
    $validServers
);

// 4. 契約中なのにキャストが1件も無い
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

<div class="stats">
    <div class="stat<?= $orphanRows ? ' alert' : '' ?>"><div class="n"><?= number_format($orphanRows) ?></div><div class="k">店舗が無いキャスト</div></div>
    <div class="stat<?= $stoppedButRunning ? ' alert' : '' ?>"><div class="n"><?= number_format(count($stoppedButRunning)) ?></div><div class="k">停止店舗に稼働キャスト</div></div>
    <div class="stat<?= $badServer ? ' alert' : '' ?>"><div class="n"><?= number_format(count($badServer)) ?></div><div class="k">実行サーバー値が不正</div></div>
    <div class="stat"><div class="n"><?= number_format(count($noGirls)) ?></div><div class="k">契約中でキャスト0件</div></div>
</div>

<div class="panel">
    <h2>店舗が存在しないキャスト</h2>
    <div class="panel-body">
        <p class="muted">店舗を削除したときにキャストが残った跡です。集計の分母を狂わせるので、消すか、退避用の店舗IDにまとめるかを決めてください。</p>
    </div>
    <?php if (!$orphans): ?>
        <div class="panel-body muted">ありません。</div>
    <?php else: ?>
        <table class="grid">
            <thead><tr><th style="width:120px">shopr_id</th><th class="num" style="width:120px">件数</th><th class="num" style="width:140px">うち稼働中</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($orphans as $o): ?>
                <tr>
                    <td class="id"><?= (int)$o['shopr_id'] ?></td>
                    <td class="num"><?= number_format((int)$o['n']) ?></td>
                    <td class="num"><?= (int)$o['active_n'] > 0 ? '<span class="pill pill-bad">' . (int)$o['active_n'] . '</span>' : '0' ?></td>
                    <td><a class="btn-plain" style="padding:2px 8px;font-size:12px" href="girls.php?shop=<?= (int)$o['shopr_id'] ?>">一覧</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>停止中の店舗に、稼働中のキャストが残っている</h2>
    <div class="panel-body">
        <p class="muted">契約が切れているのにキャスト側が稼働のままです。プログラムが店舗の契約状況を見ずにキャスト状態だけで動いていると、解約後も処理が続きます。</p>
    </div>
    <?php if (!$stoppedButRunning): ?>
        <div class="panel-body muted">ありません。</div>
    <?php else: ?>
        <table class="grid">
            <thead><tr><th style="width:70px">ID</th><th>店舗</th><th class="num" style="width:140px">稼働キャスト</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($stoppedButRunning as $r): ?>
                <tr>
                    <td class="id"><?= (int)$r['user_id'] ?></td>
                    <td><a href="shop_edit.php?id=<?= (int)$r['user_id'] ?>"><?= h($r['username']) ?></a></td>
                    <td class="num"><?= number_format((int)$r['n']) ?></td>
                    <td><a class="btn-plain" style="padding:2px 8px;font-size:12px" href="girls.php?shop=<?= (int)$r['user_id'] ?>&gstat=1">一覧</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>実行サーバーの値が想定外</h2>
    <div class="panel-body">
        <p class="muted">設定として使われない値が入っています。別の項目の値が誤って書き込まれた可能性があります。</p>
    </div>
    <?php if (!$badServer): ?>
        <div class="panel-body muted">ありません。</div>
    <?php else: ?>
        <table class="grid">
            <thead><tr><th style="width:70px">ID</th><th>店舗</th><th style="width:90px">契約</th><th style="width:130px">exeserver</th></tr></thead>
            <tbody>
            <?php foreach ($badServer as $r): ?>
                <tr>
                    <td class="id"><?= (int)$r['user_id'] ?></td>
                    <td><a href="shop_edit.php?id=<?= (int)$r['user_id'] ?>"><?= h($r['username']) ?></a></td>
                    <td><span class="pill <?= (int)$r['status'] === $activeValue ? 'pill-on' : 'pill-off' ?>"><?= h(label_of('status_labels', $r['status'])) ?></span></td>
                    <td><span class="pill pill-bad"><?= (int)$r['exeserver'] ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>契約中だが、キャストが1件も登録されていない</h2>
    <?php if (!$noGirls): ?>
        <div class="panel-body muted">ありません。</div>
    <?php else: ?>
        <table class="grid">
            <thead><tr><th style="width:70px">ID</th><th>店舗</th><th style="width:140px">担当</th></tr></thead>
            <tbody>
            <?php foreach ($noGirls as $r): ?>
                <tr>
                    <td class="id"><?= (int)$r['user_id'] ?></td>
                    <td><a href="shop_edit.php?id=<?= (int)$r['user_id'] ?>"><?= h($r['username']) ?></a></td>
                    <td><?= h($r['tantou']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php render_foot(); ?>
