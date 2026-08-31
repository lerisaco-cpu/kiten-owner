<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$shop = q1('SELECT * FROM users WHERE user_id = ?', [$id]);
if ($shop === null) {
    render_head('店舗', 'shops');
    flash('この店舗は見つかりませんでした。削除された可能性があります。', 'warn');
    echo '<a class="btn-plain" href="shops.php">店舗一覧へ戻る</a>';
    render_foot();
    exit;
}

// 稼働中システムが読んでいるテーブルなので、書き換えを許すカラムは限定する。
// 動作そのものを変える設定（cmnmsg, optionfst, license 等）は表示のみ。
$editable = [
    'status'   => '契約状況',
    'ktype'    => 'プラン',
    'tantou'   => '担当者',
    'tel'      => '電話番号',
    'bank'     => '振込先',
    'etc'      => '備考',
    'exeserver'=> '実行サーバー',
];

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_edit();

    $changes = [];
    $sets    = [];
    $params  = [];

    foreach ($editable as $col => $labelText) {
        if (!array_key_exists($col, $_POST)) {
            continue;
        }
        $new = trim((string)$_POST[$col]);
        $old = (string)$shop[$col];
        if (in_array($col, ['status', 'ktype', 'exeserver'], true)) {
            $new = (string)(int)$new;
        }
        if ($new !== $old) {
            $sets[]   = "`$col` = ?";
            $params[] = $new;
            $changes[$col] = ['from' => $old, 'to' => $new];
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

$status = table_exists('exec_status')
    ? q1('SELECT * FROM exec_status WHERE user_id = ?', [$id])
    : null;

$logs = table_exists('exec_log')
    ? q('SELECT * FROM exec_log WHERE user_id = ? ORDER BY started_at DESC LIMIT 20', [$id])
    : [];

render_head($shop['username'] . '（ID ' . $id . '）', 'shops');
if ($message !== '') {
    flash($message, 'ok');
}
?>

<p><a class="btn-plain" href="shops.php">店舗一覧へ戻る</a></p>

<div class="stats">
    <div class="stat"><div class="n"><?= number_format($girlActive) ?></div><div class="k">稼働キャスト</div></div>
    <div class="stat"><div class="n"><?= number_format($girlTotal) ?></div><div class="k">登録キャスト</div></div>
    <?php if ($status): ?>
        <div class="stat"><div class="n" style="font-size:16px"><?= h(ago($status['last_success_at'])) ?></div><div class="k">最終成功</div></div>
        <div class="stat<?= (int)$status['fail_streak'] > 0 ? ' alert' : '' ?>"><div class="n"><?= (int)$status['fail_streak'] ?></div><div class="k">連続失敗</div></div>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>契約・運用の設定</h2>
    <div class="panel-body">
        <?php if (!can_edit()): ?>
            <p class="muted">閲覧専用モードのため、この内容は変更できません。</p>
        <?php endif; ?>
        <form method="post" action="shop_edit.php?id=<?= $id ?>">
            <?= csrf_field() ?>
            <div class="form-row">
                <label for="status">契約状況</label>
                <select id="status" name="status"<?= can_edit() ? '' : ' disabled' ?>>
                    <?php foreach ((array)cfg('status_labels', []) as $v => $lbl): ?>
                        <option value="<?= (int)$v ?>"<?= (int)$shop['status'] === (int)$v ? ' selected' : '' ?>><?= h($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="ktype">プラン</label>
                <select id="ktype" name="ktype"<?= can_edit() ? '' : ' disabled' ?>>
                    <?php foreach ((array)cfg('plan_labels', []) as $v => $lbl): ?>
                        <option value="<?= (int)$v ?>"<?= (int)$shop['ktype'] === (int)$v ? ' selected' : '' ?>><?= h($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="exeserver">実行サーバー</label>
                <input type="number" id="exeserver" name="exeserver" value="<?= (int)$shop['exeserver'] ?>"<?= can_edit() ? '' : ' disabled' ?>>
            </div>
            <div class="form-row">
                <label for="tantou">担当者</label>
                <input type="text" id="tantou" name="tantou" value="<?= h($shop['tantou']) ?>"<?= can_edit() ? '' : ' disabled' ?>>
            </div>
            <div class="form-row">
                <label for="tel">電話番号</label>
                <input type="text" id="tel" name="tel" value="<?= h($shop['tel']) ?>"<?= can_edit() ? '' : ' disabled' ?>>
            </div>
            <div class="form-row">
                <label for="bank">振込先</label>
                <input type="text" id="bank" name="bank" value="<?= h($shop['bank']) ?>"<?= can_edit() ? '' : ' disabled' ?>>
            </div>
            <div class="form-row">
                <label for="etc">備考</label>
                <input type="text" id="etc" name="etc" value="<?= h($shop['etc']) ?>"<?= can_edit() ? '' : ' disabled' ?>>
            </div>
            <?php if (can_edit()): ?>
                <button class="btn-primary-app" type="submit">変更を保存</button>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="panel">
    <h2>システム設定（表示のみ）</h2>
    <table class="kv">
        <tr><th>ログインメール</th><td><?= h($shop['email']) ?></td></tr>
        <tr><th>disp_id</th><td class="id"><?= h((string)$shop['disp_id']) ?></td></tr>
        <tr><th>ライセンス</th><td class="id"><?= h($shop['license']) ?></td></tr>
        <tr><th>ヘブンURL</th><td class="wrapcell"><?= h($shop['hvnurl']) ?: '<span class="muted">—</span>' ?></td></tr>
        <tr><th>eki URL</th><td class="wrapcell"><?= h($shop['ekiurl']) ?: '<span class="muted">—</span>' ?></td></tr>
        <tr><th>optionfst / optionfst2</th><td><?= (int)$shop['optionfst'] ?> / <?= (int)$shop['optionfst2'] ?></td></tr>
        <tr><th>置き画像</th><td><?= (int)$shop['okigazou'] ?></td></tr>
        <tr><th>共通文 分割</th><td><?= (int)$shop['cmnbunkatu'] ?></td></tr>
        <tr><th>共通文1</th><td class="wrapcell"><?= nl2br(h(mb_strimwidth((string)$shop['cmnmsg1'], 0, 400, '…'))) ?></td></tr>
        <tr><th>共通文2</th><td class="wrapcell"><?= nl2br(h(mb_strimwidth((string)$shop['cmnmsg2'], 0, 400, '…'))) ?></td></tr>
    </table>
</div>

<div class="panel">
    <h2>この店舗のキャスト</h2>
    <div class="panel-body">
        <a class="btn-plain" href="girls.php?shop=<?= $id ?>">キャスト<?= number_format($girlTotal) ?>件を見る</a>
    </div>
</div>

<?php if ($logs): ?>
<div class="panel">
    <h2>直近の実行ログ</h2>
    <table class="grid">
        <thead><tr><th style="width:150px">日時</th><th style="width:110px">処理</th><th style="width:80px">結果</th><th>内容</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
            <tr>
                <td class="muted"><?= h((string)$l['started_at']) ?></td>
                <td><?= h((string)$l['job_type']) ?></td>
                <td>
                    <?php if ((int)$l['result'] === 1): ?><span class="pill pill-on">成功</span>
                    <?php elseif ((int)$l['result'] === 0): ?><span class="pill pill-bad">失敗</span>
                    <?php else: ?><span class="pill pill-off">スキップ</span><?php endif; ?>
                </td>
                <td class="wrapcell"><?= h(mb_strimwidth((string)$l['message'], 0, 140, '…')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php render_foot(); ?>
