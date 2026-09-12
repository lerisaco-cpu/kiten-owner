<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
require_login();

$activeValue = (int)cfg('status_active_value', 1);

/*
 * オーナー用のメモ。
 *
 * 出すのは role = 1（オーナー）のときだけです。画面に出していなくても POST は
 * 届いてしまうので、保存側でも同じ確認をします。
 *
 * allow_edit とは切り離しています。allow_edit は稼働中システムのテーブルを守る
 * ための設定で、dashboard_memo は独立した別テーブルだからです。
 * 閲覧専用モードでも、オーナーであれば書けます。
 */
$admin   = current_admin();
$isOwner = $admin !== null && (int)$admin['role'] === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'memo') {
    csrf_check();
    if (!$isOwner) {
        http_response_code(403);
        exit('この操作を行う権限がありません。');
    }

    // 行はいつも id = 1 の1行だけ。まだ無ければここで作る。
    ex(
        'INSERT INTO dashboard_memo (id, body, updated_by, updated_at)
              VALUES (1, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE body       = VALUES(body),
                                 updated_by = VALUES(updated_by),
                                 updated_at = NOW()',
        [(string)($_POST['body'] ?? ''), (int)$admin['admin_id']]
    );
    audit('update_memo');   // 本文は残さない

    // 再読み込みで二重に保存されないよう、GET に戻してから表示する
    header('Location: index.php?memo=saved');
    exit;
}

$memoSaved = ($_GET['memo'] ?? '') === 'saved';
$memo      = null;
if ($isOwner) {
    $memo = q1(
        'SELECT m.body, m.updated_at, a.name AS updated_name
           FROM dashboard_memo m
           LEFT JOIN admin_users a ON a.admin_id = m.updated_by
          WHERE m.id = 1'
    );
}

$shopTotal  = (int)qv('SELECT COUNT(*) FROM users');
$shopActive = (int)qv('SELECT COUNT(*) FROM users WHERE status = ?', [$activeValue]);
$girlTotal  = (int)qv('SELECT COUNT(*) FROM kiten_girl');

$girlRunning = (int)qv(
    'SELECT COUNT(*) FROM kiten_girl g
       JOIN users u ON u.user_id = g.shopr_id
      WHERE u.status = ? AND g.kitengirl_status = 1',
    [$activeValue]
);

render_head('ダッシュボード', 'index');
?>

<div class="summary-row">
    <?php
    summary_card('契約中の店舗', number_format($shopActive), 'fa-store');
    summary_card('停止中の店舗', number_format($shopTotal - $shopActive), 'fa-store-slash');
    summary_card('稼働キャスト', number_format($girlRunning), 'fa-user-check');
    summary_card('登録キャスト総数', number_format($girlTotal), 'fa-address-book');
    ?>
</div>

<?php if ($isOwner): ?>
<div class="section-card memo-card">
    <div class="head">
        <i class="fa-solid fa-note-sticky"></i>運営メモ
        <?php if ($memo && $memo['updated_at']): ?>
            <span class="count">
                最終更新 <?= h((string)$memo['updated_at']) ?>
                <?php if ($memo['updated_name'] !== null && $memo['updated_name'] !== ''): ?>
                    ／ <?= h((string)$memo['updated_name']) ?>
                <?php endif; ?>
            </span>
        <?php else: ?>
            <span class="count">まだ書き込まれていません</span>
        <?php endif; ?>
    </div>
    <div class="body">
        <?php if ($memoSaved): ?>
            <div class="alert-box alert-ok">
                <i class="fa-solid fa-circle-check"></i>
                <div>
                    保存しました。
                    <?php if ($memo && $memo['updated_at']): ?>
                        （<?= h((string)$memo['updated_at']) ?>
                        <?php if ($memo['updated_name'] !== null && $memo['updated_name'] !== ''): ?>
                            ／ <?= h((string)$memo['updated_name']) ?>
                        <?php endif; ?>）
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <form method="post" action="index.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="memo">
            <?php /*
             * メモはこのテキストエリアが表示そのものです。中身は h() を通します。
             * ここに nl2br() は使いません。テキストエリアの中では改行はそのまま
             * 改行として出るので、<br> を入れると文字として見えてしまいます。
             */ ?>
            <textarea id="memo-body" class="memo-text" name="body" rows="10"
                      placeholder="運営の申し送りなどを書いてください。"><?= h((string)($memo['body'] ?? '')) ?></textarea>
            <div class="form-actions">
                <button class="btn btn-main" type="submit"><i class="fa-solid fa-floppy-disk"></i>保存</button>
                <span class="muted" style="font-size:13px">このメモはオーナーだけに表示されます。</span>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php render_foot(); ?>
