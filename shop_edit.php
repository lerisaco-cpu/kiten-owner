<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$shop = q1('SELECT * FROM users WHERE user_id = ?', [$id]);
if ($shop === null) {
    render_head('店舗管理', 'shops');
    flash('この店舗は見つかりませんでした。削除された可能性があります。', 'warn');
    echo '<a class="btn btn-outline" href="shops.php"><i class="fa-solid fa-arrow-left"></i>店舗一覧へ戻る</a>';
    render_foot();
    exit;
}

// 稼働中システムが読んでいるテーブルなので、書き換えを許すカラムは限定する
$editable = ['status', 'ktype', 'tantou', 'tel', 'bank', 'etc', 'exeserver'];

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_edit();

    $changes = [];
    $sets    = [];
    $params  = [];

    foreach ($editable as $colName) {
        if (!array_key_exists($colName, $_POST)) {
            continue;
        }
        $new = trim((string)$_POST[$colName]);
        $old = (string)$shop[$colName];
        if (in_array($colName, ['status', 'ktype', 'exeserver'], true)) {
            $new = (string)(int)$new;
        }
        if ($new !== $old) {
            $sets[]   = "`$colName` = ?";
            $params[] = $new;
            $changes[$colName] = ['from' => $old, 'to' => $new];
        }
    }

    if ($sets) {
        $params[] = $id;
        ex('UPDATE users SET ' . implode(', ', $sets) . ' WHERE user_id = ?', $params);
        audit('update_shop', 'user', $id, $changes);
        $shop = q1('SELECT * FROM users WHERE user_id = ?', [$id]);
        $message = '保存しました。';
    } else {
        $message = '変更はありませんでした。';
    }
}

$girlTotal  = (int)qv('SELECT COUNT(*) FROM kiten_girl WHERE shopr_id = ?', [$id]);
$girlActive = (int)qv('SELECT COUNT(*) FROM kiten_girl WHERE shopr_id = ? AND kitengirl_status = 1', [$id]);

$status = table_exists('exec_status') ? q1('SELECT * FROM exec_status WHERE user_id = ?', [$id]) : null;
$logs   = table_exists('exec_log')
    ? q('SELECT * FROM exec_log WHERE user_id = ? ORDER BY started_at DESC LIMIT 20', [$id])
    : [];

render_head($shop['username'], 'shops');
crumbs(['店舗管理' => 'shops.php', $shop['username'] . '（ID ' . $id . '）' => null]);

if ($message !== '') {
    flash($message, 'ok');
}
?>

<div class="summary-row">
    <?php
    summary_card('稼働キャスト', number_format($girlActive), 'fa-user-check');
    summary_card('登録キャスト', number_format($girlTotal), 'fa-address-book');
    if ($status) {
        summary_card('最終成功', ago($status['last_success_at']), 'fa-clock');
        summary_card('連続失敗', (string)(int)$status['fail_streak'], 'fa-circle-exclamation', (int)$status['fail_streak'] > 0 ? 'alert' : '');
    }
    ?>
</div>

<div class="section-card form-card">
    <div class="head"><i class="fa-solid fa-sliders"></i>契約・運用の設定</div>
    <div class="body">
        <form method="post" action="shop_edit.php?id=<?= $id ?>">
            <?= csrf_field() ?>
            <div class="form-row">
                <label for="status">契約状況</label>
                <div>
                    <select id="status" name="status"<?= can_edit() ? '' : ' disabled' ?>>
                        <?php foreach ((array)cfg('status_labels', []) as $v => $lbl): ?>
                            <option value="<?= (int)$v ?>"<?= (int)$shop['status'] === (int)$v ? ' selected' : '' ?>><?= h($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <label for="ktype">プラン</label>
                <div>
                    <select id="ktype" name="ktype"<?= can_edit() ? '' : ' disabled' ?>>
                        <?php foreach ((array)cfg('plan_labels', []) as $v => $lbl): ?>
                            <option value="<?= (int)$v ?>"<?= (int)$shop['ktype'] === (int)$v ? ' selected' : '' ?>><?= h($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <label for="exeserver">実行サーバー</label>
                <div><input type="number" id="exeserver" name="exeserver" value="<?= (int)$shop['exeserver'] ?>"<?= can_edit() ? '' : ' disabled' ?>></div>
            </div>
            <div class="form-row">
                <label for="tantou">担当者</label>
                <div><input type="text" id="tantou" name="tantou" value="<?= h($shop['tantou']) ?>"<?= can_edit() ? '' : ' disabled' ?>></div>
            </div>
            <div class="form-row">
                <label for="tel">電話番号</label>
                <div><input type="text" id="tel" name="tel" value="<?= h($shop['tel']) ?>"<?= can_edit() ? '' : ' disabled' ?>></div>
            </div>
            <div class="form-row">
                <label for="bank">振込先</label>
                <div><input type="text" id="bank" name="bank" value="<?= h($shop['bank']) ?>"<?= can_edit() ? '' : ' disabled' ?>></div>
            </div>
            <div class="form-row">
                <label for="etc">備考</label>
                <div><input type="text" id="etc" name="etc" value="<?= h($shop['etc']) ?>"<?= can_edit() ? '' : ' disabled' ?>></div>
            </div>
            <?php if (can_edit()): ?>
                <div class="form-actions">
                    <button class="btn btn-main" type="submit"><i class="fa-solid fa-floppy-disk"></i>変更を保存</button>
                    <a class="btn btn-outline" href="shops.php">戻る</a>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="section-card">
    <div class="head"><i class="fa-solid fa-gears"></i>システム設定（表示のみ）</div>
    <div class="table-scroll">
    <table class="kv">
        <tr><th>ログインメール</th><td><?= h($shop['email']) ?></td></tr>
        <tr><th>disp_id</th><td class="id-col"><?= h((string)$shop['disp_id']) ?></td></tr>
        <tr><th>ライセンス</th><td class="id-col"><?= h($shop['license']) ?></td></tr>
        <tr><th>ヘブンURL</th><td class="wrapcell"><?= $shop['hvnurl'] !== '' ? h($shop['hvnurl']) : '<span class="soft">—</span>' ?></td></tr>
        <tr><th>eki URL</th><td class="wrapcell"><?= $shop['ekiurl'] !== '' ? h($shop['ekiurl']) : '<span class="soft">—</span>' ?></td></tr>
        <tr><th>optionfst / optionfst2</th><td><?= (int)$shop['optionfst'] ?> / <?= (int)$shop['optionfst2'] ?></td></tr>
        <tr><th>置き画像</th><td><?= (int)$shop['okigazou'] ?></td></tr>
        <tr><th>共通文 分割</th><td><?= (int)$shop['cmnbunkatu'] ?></td></tr>
        <tr><th>共通文1</th><td class="wrapcell"><?= nl2br(h(mb_strimwidth((string)$shop['cmnmsg1'], 0, 400, '…'))) ?></td></tr>
        <tr><th>共通文2</th><td class="wrapcell"><?= nl2br(h(mb_strimwidth((string)$shop['cmnmsg2'], 0, 400, '…'))) ?></td></tr>
    </table>
    </div>
</div>

<div class="section-card">
    <div class="head"><i class="fa-solid fa-address-book"></i>この店舗のキャスト</div>
    <div class="body">
        <a class="btn btn-link" href="girls.php?shop=<?= $id ?>"><i class="fa-solid fa-arrow-up-right-from-square"></i>キャスト <?= number_format($girlTotal) ?> 件を見る</a>
    </div>
</div>

<?php if ($logs): ?>
<div class="section-card">
    <div class="head"><i class="fa-solid fa-list-check"></i>直近の実行ログ</div>
    <div class="table-scroll">
    <table class="data-table">
        <thead><tr><th style="width:150px">日時</th><th style="width:120px">処理</th><th style="width:90px">結果</th><th>内容</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
            <tr>
                <td class="muted"><?= h((string)$l['started_at']) ?></td>
                <td><?= h((string)$l['job_type']) ?></td>
                <td>
                    <?php if ((int)$l['result'] === 1): ?><span class="badge badge-active">成功</span>
                    <?php elseif ((int)$l['result'] === 0): ?><span class="badge badge-danger">失敗</span>
                    <?php else: ?><span class="badge badge-inactive">スキップ</span><?php endif; ?>
                </td>
                <td class="wrapcell"><?= h(mb_strimwidth((string)$l['message'], 0, 140, '…')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php render_foot(); ?>
