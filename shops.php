<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
require_login();

$activeValue = (int)cfg('status_active_value', 1);
$perPage     = (int)cfg('per_page', 50);
$page        = max(1, (int)($_GET['page'] ?? 1));

$kw     = trim((string)($_GET['kw'] ?? ''));
$status = $_GET['status'] ?? '';
$plan   = $_GET['plan'] ?? '';

$where  = [];
$params = [];

if ($kw !== '') {
    $where[] = '(u.username LIKE ? OR u.email LIKE ? OR u.tantou LIKE ? OR u.tel LIKE ? OR u.user_id = ?)';
    $like = '%' . $kw . '%';
    array_push($params, $like, $like, $like, $like, (int)$kw);
}
if ($status !== '') {
    $where[] = 'u.status = ?';
    $params[] = (int)$status;
}
if ($plan !== '') {
    $where[] = 'u.ktype = ?';
    $params[] = (int)$plan;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$total = (int)qv("SELECT COUNT(*) FROM users u $whereSql", $params);

// 並び替え。列名はホワイトリストで縛る（SQL文に文字列を直接入れるため）
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
$selStatus  = $hasStatusTable ? 's.last_success_at, s.last_exec_at, s.fail_streak,' : '';
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

/** 並び替えリンク */
function sort_link(string $key, string $label): string
{
    $cur = $_GET['sort'] ?? 'id';
    $dir = ($cur === $key && ($_GET['dir'] ?? 'asc') === 'asc') ? 'desc' : 'asc';
    $mark = $cur === $key ? (($_GET['dir'] ?? 'asc') === 'asc' ? ' ▲' : ' ▼') : '';
    return '<a href="' . h(url_with(['sort' => $key, 'dir' => $dir, 'page' => 1])) . '">' . h($label) . $mark . '</a>';
}

render_head('店舗', 'shops');
?>

<form class="filters" method="get" action="shops.php">
    <input type="text" name="kw" value="<?= h($kw) ?>" placeholder="店舗名・メール・担当・電話・ID">
    <select name="status">
        <option value="">契約状況すべて</option>
        <?php foreach ((array)cfg('status_labels', []) as $v => $lbl): ?>
            <option value="<?= (int)$v ?>"<?= $status !== '' && (int)$status === (int)$v ? ' selected' : '' ?>><?= h($lbl) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="plan">
        <option value="">プランすべて</option>
        <?php foreach ((array)cfg('plan_labels', []) as $v => $lbl): ?>
            <option value="<?= (int)$v ?>"<?= $plan !== '' && (int)$plan === (int)$v ? ' selected' : '' ?>><?= h($lbl) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn-primary-app" type="submit">絞り込む</button>
    <?php if ($kw !== '' || $status !== '' || $plan !== ''): ?>
        <a class="btn-plain" href="shops.php">解除</a>
    <?php endif; ?>
</form>

<?php if (!$rows): ?>
    <div class="notice notice-info">条件に合う店舗はありません。条件を緩めるか、解除して一覧に戻ってください。</div>
<?php else: ?>
<table class="grid">
    <thead>
    <tr>
        <th style="width:64px"><?= sort_link('id', 'ID') ?></th>
        <th><?= sort_link('name', '店舗') ?></th>
        <th style="width:90px"><?= sort_link('status', '契約') ?></th>
        <th style="width:110px"><?= sort_link('plan', 'プラン') ?></th>
        <th style="width:110px" class="num"><?= sort_link('girls', 'キャスト') ?></th>
        <th style="width:110px">担当</th>
        <th style="width:70px" class="num">実行鯖</th>
        <?php if ($hasStatusTable): ?><th style="width:120px"><?= sort_link('last', '最終成功') ?></th><?php endif; ?>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
        $isActive = (int)$r['status'] === $activeValue;
        ?>
        <tr>
            <td class="id"><?= (int)$r['user_id'] ?></td>
            <td>
                <a href="shop_edit.php?id=<?= (int)$r['user_id'] ?>"><?= h($r['username']) ?></a>
                <div class="muted" style="font-size:12px"><?= h($r['email']) ?></div>
            </td>
            <td><span class="pill <?= $isActive ? 'pill-on' : 'pill-off' ?>"><?= h(label_of('status_labels', $r['status'])) ?></span></td>
            <td><?= h(label_of('plan_labels', $r['ktype'])) ?></td>
            <td class="num">
                <?= number_format((int)$r['girl_active']) ?>
                <span class="muted">/ <?= number_format((int)$r['girl_total']) ?></span>
            </td>
            <td><?= h($r['tantou']) ?></td>
            <td class="num<?= in_array((int)$r['exeserver'], (array)cfg('valid_exeservers', []), true) ? '' : ' ' ?>">
                <?php if (in_array((int)$r['exeserver'], (array)cfg('valid_exeservers', []), true)): ?>
                    <?= (int)$r['exeserver'] ?>
                <?php else: ?>
                    <span class="pill pill-bad"><?= (int)$r['exeserver'] ?></span>
                <?php endif; ?>
            </td>
            <?php if ($hasStatusTable): ?>
                <td>
                    <?php if (!$isActive): ?>
                        <span class="muted">—</span>
                    <?php elseif (empty($r['last_success_at'])): ?>
                        <span class="pill pill-bad">記録なし</span>
                    <?php else:
                        $lateSec = time() - strtotime((string)$r['last_success_at']);
                        $cls = $lateSec > (int)cfg('stale_hours', 6) * 3600 ? 'pill-warn' : 'pill-on';
                        ?>
                        <span class="pill <?= $cls ?>"><?= h(ago($r['last_success_at'])) ?></span>
                    <?php endif; ?>
                </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php render_pager($page, $total, $perPage); ?>
<?php endif; ?>

<?php render_foot(); ?>
