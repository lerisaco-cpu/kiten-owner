<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
require_login();

if (!table_exists('exec_log')) {
    render_head('稼働ログ', 'logs');
    ?>
    <div class="alert-box alert-warn"><i class="fa-solid fa-triangle-exclamation"></i><div>実行ログのテーブルがまだありません。</div></div>
    <div class="section-card">
        <div class="head"><i class="fa-solid fa-circle-info"></i>この画面を動かすには</div>
        <div class="body">
            <p>今のデータベースには、いつ何が動いたかの記録が残っていません。次の2つが揃うと、この画面に稼働状況が出ます。</p>
            <ol>
                <li>phpMyAdmin で <code>sql/schema.sql</code> を実行し、<code>exec_log</code> と <code>exec_status</code> を作る</li>
                <li>VPSのプログラムに、1処理ごとの結果をこの2つのテーブルへ書き込む処理を足す</li>
            </ol>
            <p class="muted">書き込むSQLの雛形は README に載せてあります。</p>
        </div>
    </div>
    <?php
    render_foot();
    exit;
}

$perPage = (int)cfg('per_page', 50);
$page    = max(1, (int)($_GET['page'] ?? 1));

$result = $_GET['result'] ?? '';
$shop   = $_GET['shop'] ?? '';
$job    = trim((string)($_GET['job'] ?? ''));
$days   = (int)($_GET['days'] ?? 7);
if (!in_array($days, [1, 3, 7, 30], true)) {
    $days = 7;
}

$where  = ['l.started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)'];
$params = [$days];
if ($result !== '') {
    $where[] = 'l.result = ?';
    $params[] = (int)$result;
}
if ($shop !== '') {
    $where[] = 'l.user_id = ?';
    $params[] = (int)$shop;
}
if ($job !== '') {
    $where[] = 'l.job_type = ?';
    $params[] = $job;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$total = (int)qv("SELECT COUNT(*) FROM exec_log l $whereSql", $params);
$rows  = q(
    "SELECT l.*, u.username
       FROM exec_log l
       LEFT JOIN users u ON u.user_id = l.user_id
       $whereSql
      ORDER BY l.started_at DESC
      LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
    $params
);

$jobTypes = q('SELECT DISTINCT job_type FROM exec_log ORDER BY job_type');

render_head('稼働ログ', 'logs');
?>

<form class="filter-card" method="get" action="logs.php">
    <div class="filter-row">
        <div class="filter-field">
            <label for="days">期間</label>
            <select id="days" name="days">
                <?php foreach ([1 => '過去1日', 3 => '過去3日', 7 => '過去7日', 30 => '過去30日'] as $v => $lbl): ?>
                    <option value="<?= $v ?>"<?= $days === $v ? ' selected' : '' ?>><?= h($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label for="result">結果</label>
            <select id="result" name="result">
                <option value="">すべて</option>
                <option value="1"<?= $result === '1' ? ' selected' : '' ?>>成功</option>
                <option value="0"<?= $result === '0' ? ' selected' : '' ?>>失敗</option>
                <option value="2"<?= $result === '2' ? ' selected' : '' ?>>スキップ</option>
            </select>
        </div>
        <div class="filter-field">
            <label for="job">処理種別</label>
            <select id="job" name="job">
                <option value="">すべて</option>
                <?php foreach ($jobTypes as $j): ?>
                    <option value="<?= h($j['job_type']) ?>"<?= $job === $j['job_type'] ? ' selected' : '' ?>><?= h($j['job_type']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label for="shop">店舗ID</label>
            <input type="text" id="shop" name="shop" value="<?= h((string)$shop) ?>" placeholder="例: 14">
        </div>
        <div class="filter-actions">
            <button class="btn btn-main" type="submit"><i class="fa-solid fa-magnifying-glass"></i>検索</button>
            <a class="btn btn-outline" href="logs.php"><i class="fa-solid fa-rotate-left"></i>リセット</a>
        </div>
    </div>
</form>

<div class="section-card">
    <div class="head">
        <i class="fa-solid fa-list-check"></i>実行ログ
        <span class="count">全 <?= number_format($total) ?> 件</span>
    </div>
    <?php if (!$rows): ?>
        <div class="empty"><i class="fa-solid fa-inbox"></i>この期間の記録はありません。</div>
    <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
        <thead>
        <tr>
            <th style="width:150px">日時</th>
            <th style="width:60px">ID</th>
            <th>店舗名</th>
            <th style="width:120px">処理</th>
            <th style="width:90px">結果</th>
            <th style="width:90px" class="num">所要</th>
            <th>内容</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="muted"><?= h((string)$r['started_at']) ?></td>
                <td class="id-col"><?= (int)$r['user_id'] ?></td>
                <td class="strong"><a href="shop_edit.php?id=<?= (int)$r['user_id'] ?>"><?= h((string)$r['username']) ?></a></td>
                <td><?= h((string)$r['job_type']) ?></td>
                <td>
                    <?php if ((int)$r['result'] === 1): ?><span class="badge badge-active">成功</span>
                    <?php elseif ((int)$r['result'] === 0): ?><span class="badge badge-danger">失敗</span>
                    <?php else: ?><span class="badge badge-inactive">スキップ</span><?php endif; ?>
                </td>
                <td class="num muted"><?= $r['duration_ms'] === null ? '—' : number_format((int)$r['duration_ms']) . 'ms' ?></td>
                <td class="wrapcell"><?= h(mb_strimwidth((string)$r['message'], 0, 160, '…')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php render_pager($page, $total, $perPage); ?>
    <?php endif; ?>
</div>

<?php render_foot(); ?>
