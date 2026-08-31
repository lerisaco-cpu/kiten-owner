<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
$admin = require_login();

$perPage = (int)cfg('per_page', 50);
$page    = max(1, (int)($_GET['page'] ?? 1));

$kw     = trim((string)($_GET['kw'] ?? ''));
$shop   = $_GET['shop'] ?? '';
$gstat  = $_GET['gstat'] ?? '';

// 認証情報の表示は1件ずつ、押した時だけ。押した事実は操作履歴に残す。
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

render_head('キャスト' . ($shopName !== '' ? '（' . $shopName . '）' : ''), 'girls');
?>

<form class="filters" method="get" action="girls.php">
    <?php if ($shop !== ''): ?><input type="hidden" name="shop" value="<?= (int)$shop ?>"><?php endif; ?>
    <input type="text" name="kw" value="<?= h($kw) ?>" placeholder="キャスト名・店舗名・girlid">
    <select name="gstat">
        <option value="">状態すべて</option>
        <?php foreach ((array)cfg('girl_status_labels', []) as $v => $lbl): ?>
            <option value="<?= (int)$v ?>"<?= $gstat !== '' && (int)$gstat === (int)$v ? ' selected' : '' ?>><?= h($lbl) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn-primary-app" type="submit">絞り込む</button>
    <?php if ($kw !== '' || $shop !== '' || $gstat !== ''): ?>
        <a class="btn-plain" href="girls.php">解除</a>
    <?php endif; ?>
</form>

<?php if (!$rows): ?>
    <div class="notice notice-info">条件に合うキャストはありません。</div>
<?php else: ?>
<table class="grid">
    <thead>
    <tr>
        <th style="width:70px">ID</th>
        <th>キャスト</th>
        <th>店舗</th>
        <th style="width:90px">状態</th>
        <th style="width:110px">曜日設定</th>
        <th style="width:230px">姫デコ / eki</th>
        <th>メモ</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
        $showCred = ($reveal === (int)$r['kitengirl_id']);
        ?>
        <tr>
            <td class="id"><?= (int)$r['kitengirl_id'] ?></td>
            <td>
                <?= h($r['girlname']) ?>
                <div class="muted" style="font-size:12px">girlid <?= (int)$r['girlid'] ?></div>
            </td>
            <td>
                <?php if ($r['username'] === null): ?>
                    <span class="pill pill-bad">店舗なし(<?= (int)$r['shopr_id'] ?>)</span>
                <?php else: ?>
                    <a href="shop_edit.php?id=<?= (int)$r['shopr_id'] ?>"><?= h($r['username']) ?></a>
                <?php endif; ?>
            </td>
            <td>
                <?php $on = (int)$r['kitengirl_status'] === 1; ?>
                <span class="pill <?= $on ? 'pill-on' : 'pill-off' ?>"><?= h(label_of('girl_status_labels', $r['kitengirl_status'])) ?></span>
            </td>
            <td class="id">
                <?php for ($d = 1; $d <= 7; $d++) { echo (int)$r['day' . $d]; } ?>
            </td>
            <td>
                <?php if ($showCred): ?>
                    <div class="id"><?= h($r['himedeco_id']) ?> / <?= h($r['himedeco_pw']) ?></div>
                    <div class="id"><?= h($r['ekilogin']) ?> / <?= h($r['ekipw']) ?></div>
                <?php else: ?>
                    <div class="id muted"><?= h(mask($r['himedeco_id'])) ?> / <?= h(mask($r['himedeco_pw'])) ?></div>
                    <a class="btn-plain" style="padding:2px 8px;font-size:12px"
                       href="<?= h(url_with(['reveal' => (int)$r['kitengirl_id']])) ?>">表示</a>
                <?php endif; ?>
            </td>
            <td class="wrapcell muted"><?= h(mb_strimwidth((string)$r['memo'], 0, 60, '…')) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php render_pager($page, $total, $perPage); ?>
<?php endif; ?>

<?php render_foot(); ?>
