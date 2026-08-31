<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
require_login();

if (!table_exists('admin_audit_log')) {
    render_head('操作履歴', 'audit');
    flash('操作履歴のテーブルがまだありません。sql/schema.sql を実行してください。', 'warn');
    render_foot();
    exit;
}

$perPage = (int)cfg('per_page', 50);
$page    = max(1, (int)($_GET['page'] ?? 1));

$total = (int)qv('SELECT COUNT(*) FROM admin_audit_log');
$rows  = q(
    'SELECT a.*, m.name AS admin_name
       FROM admin_audit_log a
       LEFT JOIN admin_users m ON m.admin_id = a.admin_id
      ORDER BY a.created_at DESC, a.id DESC
      LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
);

$actionLabels = [
    'login'             => 'ログイン',
    'logout'            => 'ログアウト',
    'update_shop'       => '店舗設定の変更',
    'reveal_credential' => '認証情報の表示',
];

render_head('操作履歴', 'audit');
?>

<div class="section-card">
    <div class="head">
        <i class="fa-solid fa-clock-rotate-left"></i>操作履歴
        <span class="count">全 <?= number_format($total) ?> 件</span>
    </div>
    <?php if (!$rows): ?>
        <div class="empty"><i class="fa-solid fa-inbox"></i>まだ記録はありません。</div>
    <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
        <thead>
        <tr>
            <th style="width:150px">日時</th>
            <th style="width:120px">担当</th>
            <th style="width:160px">操作</th>
            <th style="width:140px">対象</th>
            <th>内容</th>
            <th style="width:120px">IP</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="muted"><?= h((string)$r['created_at']) ?></td>
                <td class="strong"><?= h((string)$r['admin_name']) ?></td>
                <td><?= h($actionLabels[$r['action']] ?? $r['action']) ?></td>
                <td class="id-col">
                    <?php if ($r['target_type'] === 'user' && $r['target_id']): ?>
                        <a href="shop_edit.php?id=<?= (int)$r['target_id'] ?>">店舗 <?= (int)$r['target_id'] ?></a>
                    <?php elseif ($r['target_type'] !== ''): ?>
                        <?= h($r['target_type']) ?> <?= (int)$r['target_id'] ?>
                    <?php else: ?>
                        <span class="soft">—</span>
                    <?php endif; ?>
                </td>
                <td class="wrapcell muted"><?= h(mb_strimwidth((string)$r['detail'], 0, 160, '…')) ?></td>
                <td class="id-col"><?= h($r['ip']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php render_pager($page, $total, $perPage); ?>
    <?php endif; ?>
</div>

<?php render_foot(); ?>
