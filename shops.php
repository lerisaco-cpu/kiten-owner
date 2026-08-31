<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
require_login();

$activeValue = (int)cfg('status_active_value', 1);
$perPage     = (int)cfg('per_page', 50);
$page        = max(1, (int)($_GET['page'] ?? 1));

$kw     = trim((string)($_GET['kw'] ?? ''));
$plan   = $_GET['plan'] ?? '';

// 契約状況は「未指定＝契約中のみ」を初期値にする。
// 全件を見たいときは 'all' を明示的に選んでもらう（空文字だと url_with() で
// 落ちてしまい、ページングや並び替えで初期値に戻ってしまうため）。
const STATUS_ALL = 'all';
$statusParam = (string)($_GET['status'] ?? '');
$status = $statusParam === '' ? (string)$activeValue : $statusParam;

$where  = [];
$params = [];

if ($kw !== '') {
    $where[] = '(u.username LIKE ? OR u.email LIKE ? OR u.tantou LIKE ? OR u.tel LIKE ? OR u.user_id = ?)';
    $like = '%' . $kw . '%';
    array_push($params, $like, $like, $like, $like, (int)$kw);
}
if ($status !== STATUS_ALL) {
    $where[] = 'u.status = ?';
    $params[] = (int)$status;
}
if ($plan !== '') {
    $where[] = 'u.ktype = ?';
    $params[] = (int)$plan;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$total = (int)qv("SELECT COUNT(*) FROM users u $whereSql", $params);

$sortMap = [
    'id'     => 'u.user_id',
    'name'   => 'u.username',
    'status' => 'u.status',
    'plan'   => 'u.ktype',
    'girls'  => 'girl_active',
    'last'   => 's.last_success_at',
];
$sort = $_GET['sort'] ?? 'id';
$col  = $sortMap[$sort] ?? 'u.user_id';
$dir  = (($_GET['dir'] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';

$hasStatusTable = table_exists('exec_status');
$joinStatus = $hasStatusTable ? 'LEFT JOIN exec_status s ON s.user_id = u.user_id' : '';
$selStatus  = $hasStatusTable ? 's.last_success_at, s.fail_streak,' : '';
if (!$hasStatusTable && $sort === 'last') {
    $col = 'u.user_id';
}

$rows = q(
    "SELECT u.user_id, u.username, u.email, u.status, u.ktype, u.tantou, u.tel, u.exeserver,
            $selStatus
            COALESCE(g.total, 0)  AS girl_total,
            COALESCE(g.active, 0) AS girl_active
       FROM users u
       LEFT JOIN (
            SELECT shopr_id, COUNT(*) AS total, SUM(kitengirl_status = 1) AS active
              FROM kiten_girl GROUP BY shopr_id
       ) g ON g.shopr_id = u.user_id
       $joinStatus
       $whereSql
      ORDER BY $col $dir
      LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
    $params
);

function sort_link(string $key, string $labelText): string
{
    $cur = $_GET['sort'] ?? 'id';
    $dir = ($cur === $key && ($_GET['dir'] ?? 'asc') === 'asc') ? 'desc' : 'asc';
    $mark = $cur === $key ? (($_GET['dir'] ?? 'asc') === 'asc' ? ' <i class="fa-solid fa-caret-up"></i>' : ' <i class="fa-solid fa-caret-down"></i>') : '';
    return '<a href="' . h(url_with(['sort' => $key, 'dir' => $dir, 'page' => 1])) . '">' . h($labelText) . $mark . '</a>';
}

render_head('店舗管理', 'shops');
?>

<form class="filter-card" method="get" action="shops.php">
    <div class="filter-row">
        <div class="filter-field">
            <label for="kw">店舗名</label>
            <input type="text" id="kw" name="kw" value="<?= h($kw) ?>" placeholder="部分一致検索">
        </div>
        <div class="filter-field">
            <label for="status">契約状況</label>
            <select id="status" name="status">
                <option value="<?= STATUS_ALL ?>"<?= $status === STATUS_ALL ? ' selected' : '' ?>>すべて</option>
                <?php foreach ((array)cfg('status_labels', []) as $v => $lbl): ?>
                    <option value="<?= (int)$v ?>"<?= $status !== STATUS_ALL && (int)$status === (int)$v ? ' selected' : '' ?>><?= h($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label for="plan">プラン</label>
            <select id="plan" name="plan">
                <option value="">すべて</option>
                <?php foreach ((array)cfg('plan_labels', []) as $v => $lbl): ?>
                    <option value="<?= (int)$v ?>"<?= $plan !== '' && (int)$plan === (int)$v ? ' selected' : '' ?>><?= h($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions">
            <button class="btn btn-main" type="submit"><i class="fa-solid fa-magnifying-glass"></i>検索</button>
            <a class="btn btn-outline" href="shops.php"><i class="fa-solid fa-rotate-left"></i>リセット</a>
        </div>
    </div>
</form>

<div class="section-card">
    <div class="head">
        <i class="fa-solid fa-users"></i>店舗一覧
        <span class="count">全 <?= number_format($total) ?> 件</span>
    </div>
    <?php if (!$rows): ?>
        <div class="empty"><i class="fa-solid fa-magnifying-glass"></i>条件に合う店舗はありません。条件を変えて検索してください。</div>
    <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
        <thead>
        <tr>
            <th style="width:64px"><?= sort_link('id', 'ID') ?></th>
            <th><?= sort_link('name', '店舗名') ?></th>
            <th style="width:110px"><?= sort_link('plan', 'プラン') ?></th>
            <th style="width:120px"><?= sort_link('girls', 'キャスト') ?></th>
            <th style="width:100px"><?= sort_link('status', '契約') ?></th>
            <th style="width:110px">担当</th>
            <th style="width:80px">実行鯖</th>
            <?php if ($hasStatusTable): ?><th style="width:120px"><?= sort_link('last', '稼働状況') ?></th><?php endif; ?>
            <th style="width:100px"></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $isActive = (int)$r['status'] === $activeValue;
            $validSrv = in_array((int)$r['exeserver'], (array)cfg('valid_exeservers', []), true);
            ?>
            <tr>
                <td class="id-col"><?= (int)$r['user_id'] ?></td>
                <td>
                    <div class="strong"><a href="shop_edit.php?id=<?= (int)$r['user_id'] ?>"><?= h($r['username']) ?></a></div>
                    <div class="muted" style="font-size:12px"><?= h($r['email']) ?></div>
                </td>
                <td><?= h(label_of('plan_labels', $r['ktype'])) ?></td>
                <td class="num">
                    <?= number_format((int)$r['girl_active']) ?>
                    <span class="soft">/ <?= number_format((int)$r['girl_total']) ?></span>
                </td>
                <td>
                    <span class="badge <?= $isActive ? 'badge-active' : 'badge-inactive' ?>"><?= h(label_of('status_labels', $r['status'])) ?></span>
                </td>
                <td><?= h($r['tantou']) ?></td>
                <td>
                    <?php if ($validSrv): ?>
                        <?= (int)$r['exeserver'] ?>
                    <?php else: ?>
                        <span class="badge badge-danger"><?= (int)$r['exeserver'] ?></span>
                    <?php endif; ?>
                </td>
                <?php if ($hasStatusTable): ?>
                    <td>
                        <?php if (!$isActive): ?>
                            <span class="soft">—</span>
                        <?php elseif (empty($r['last_success_at'])): ?>
                            <span class="badge badge-danger">記録なし</span>
                        <?php else:
                            $late = time() - strtotime((string)$r['last_success_at']) > (int)cfg('stale_hours', 6) * 3600;
                            ?>
                            <span class="badge <?= $late ? 'badge-warn' : 'badge-active' ?>"><?= h(ago($r['last_success_at'])) ?></span>
                        <?php endif; ?>
                    </td>
                <?php endif; ?>
                <td class="act">
                    <a class="btn btn-link btn-sm" href="shop_edit.php?id=<?= (int)$r['user_id'] ?>"><i class="fa-solid fa-pen-to-square"></i>詳細</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php render_pager($page, $total, $perPage); ?>
    <?php endif; ?>
</div>

<?php render_foot(); ?>
