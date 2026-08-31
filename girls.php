<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
require_login();

$perPage = (int)cfg('per_page', 50);
$page    = max(1, (int)($_GET['page'] ?? 1));

$kw    = trim((string)($_GET['kw'] ?? ''));
$shop  = $_GET['shop'] ?? '';
$gstat = $_GET['gstat'] ?? '';

$reveal = (int)($_GET['reveal'] ?? 0);
if ($reveal > 0) {
    audit('reveal_credential', 'kiten_girl', $reveal);
}

$where  = [];
$params = [];
if ($kw !== '') {
    $where[] = '(g.girlname LIKE ? OR u.username LIKE ? OR g.girlid = ?)';
    $like = '%' . $kw . '%';
    array_push($params, $like, $like, (int)$kw);
}
if ($shop !== '') {
    $where[] = 'g.shopr_id = ?';
    $params[] = (int)$shop;
}
if ($gstat !== '') {
    $where[] = 'g.kitengirl_status = ?';
    $params[] = (int)$gstat;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$total = (int)qv("SELECT COUNT(*) FROM kiten_girl g LEFT JOIN users u ON u.user_id = g.shopr_id $whereSql", $params);

$rows = q(
    "SELECT g.*, u.username, u.status AS shop_status
       FROM kiten_girl g
       LEFT JOIN users u ON u.user_id = g.shopr_id
       $whereSql
      ORDER BY g.shopr_id ASC, g.kitengirl_id ASC
      LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
    $params
);

$shopName = '';
if ($shop !== '') {
    $shopName = (string)qv('SELECT username FROM users WHERE user_id = ?', [(int)$shop]);
}

render_head('キャスト管理', 'girls');
if ($shopName !== '') {
    crumbs(['店舗管理' => 'shops.php', $shopName => 'shop_edit.php?id=' . (int)$shop, 'キャスト一覧' => null]);
}
?>

<form class="filter-card" method="get" action="girls.php">
    <?php if ($shop !== ''): ?><input type="hidden" name="shop" value="<?= (int)$shop ?>"><?php endif; ?>
    <div class="filter-row">
        <div class="filter-field">
            <label for="kw">キャスト名・店舗名</label>
            <input type="text" id="kw" name="kw" value="<?= h($kw) ?>" placeholder="部分一致検索">
        </div>
        <div class="filter-field">
            <label for="gstat">状態</label>
            <select id="gstat" name="gstat">
                <option value="">すべて</option>
                <?php foreach ((array)cfg('girl_status_labels', []) as $v => $lbl): ?>
                    <option value="<?= (int)$v ?>"<?= $gstat !== '' && (int)$gstat === (int)$v ? ' selected' : '' ?>><?= h($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions">
            <button class="btn btn-main" type="submit"><i class="fa-solid fa-magnifying-glass"></i>検索</button>
            <a class="btn btn-outline" href="girls.php"><i class="fa-solid fa-rotate-left"></i>リセット</a>
        </div>
    </div>
</form>

<div class="section-card">
    <div class="head">
        <i class="fa-solid fa-address-book"></i>キャスト一覧<?= $shopName !== '' ? '（' . h($shopName) . '）' : '' ?>
        <span class="count">全 <?= number_format($total) ?> 件</span>
    </div>
    <?php if (!$rows): ?>
        <div class="empty"><i class="fa-solid fa-magnifying-glass"></i>条件に合うキャストはありません。</div>
    <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
        <thead>
        <tr>
            <th style="width:70px">ID</th>
            <th>キャスト名</th>
            <th>店舗名</th>
            <th style="width:90px">状態</th>
            <th style="width:100px">曜日設定</th>
            <th style="width:250px">姫デコ / eki</th>
            <th>メモ</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $showCred = ($reveal === (int)$r['kitengirl_id']);
            ?>
            <tr>
                <td class="id-col"><?= (int)$r['kitengirl_id'] ?></td>
                <td>
                    <div class="strong"><?= h($r['girlname']) ?></div>
                    <div class="muted" style="font-size:12px">girlid <?= (int)$r['girlid'] ?></div>
                </td>
                <td>
                    <?php if ($r['username'] === null): ?>
                        <span class="badge badge-danger">店舗なし(<?= (int)$r['shopr_id'] ?>)</span>
                    <?php else: ?>
                        <a href="shop_edit.php?id=<?= (int)$r['shopr_id'] ?>"><?= h($r['username']) ?></a>
                    <?php endif; ?>
                </td>
                <td>
                    <?php $on = (int)$r['kitengirl_status'] === 1; ?>
                    <span class="badge <?= $on ? 'badge-active' : 'badge-inactive' ?>"><?= h(label_of('girl_status_labels', $r['kitengirl_status'])) ?></span>
                </td>
                <td class="id-col"><?php for ($d = 1; $d <= 7; $d++) { echo (int)$r['day' . $d]; } ?></td>
                <td>
                    <?php if ($showCred): ?>
                        <div class="id-col"><?= h($r['himedeco_id']) ?> / <?= h($r['himedeco_pw']) ?></div>
                        <div class="id-col"><?= h($r['ekilogin']) ?> / <?= h($r['ekipw']) ?></div>
                    <?php else: ?>
                        <div class="soft" style="font-size:13px"><?= h(mask($r['himedeco_id'])) ?> / <?= h(mask($r['himedeco_pw'])) ?></div>
                        <a class="btn btn-outline btn-sm" href="<?= h(url_with(['reveal' => (int)$r['kitengirl_id']])) ?>"><i class="fa-solid fa-eye"></i>表示</a>
                    <?php endif; ?>
                </td>
                <td class="wrapcell muted"><?= h(mb_strimwidth((string)$r['memo'], 0, 60, '…')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php render_pager($page, $total, $perPage); ?>
    <?php endif; ?>
</div>

<?php render_foot(); ?>
