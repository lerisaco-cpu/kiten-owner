<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
require __DIR__ . '/lib/logfiles.php';
require_login();

$activeValue = (int)cfg('status_active_value', 1);
$perPage     = (int)cfg('per_page', 50);
$page        = max(1, (int)($_GET['page'] ?? 1));

// 日付。指定が無ければ「最新の営業日」（5:55 より前に開いたら前日）
$date = (string)($_GET['date'] ?? '');
if (!log_date_valid($date)) {
    $date = log_business_date();
}

// フォルダ未設定や停止中の店舗も混ぜるかどうか
$showAll = ($_GET['all'] ?? '') === '1';

// 判定での絞り込み
$verdict = (string)($_GET['verdict'] ?? '');
if (!in_array($verdict, ['done', 'timeout', 'nofile'], true)) {
    $verdict = '';
}

// 対象の店舗を先に絞る。ログの読み取りは後段で行う。
$where  = [];
$params = [];
if (!$showAll) {
    $where[] = 'u.status = ?';
    $params[] = $activeValue;
    $where[] = "COALESCE(m.folder_name, '') <> ''";
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$shops = q(
    "SELECT u.user_id, u.username, u.status,
            COALESCE(m.folder_name, '') AS folder_name,
            COALESCE(m.ip_address,  '') AS ip_address
       FROM users u
       LEFT JOIN shop_meta m ON m.user_id = u.user_id
       $whereSql
      ORDER BY u.user_id ASC",
    $params
);

// 1店舗ずつログを読む。status / report / loginerr だけで、ミテネとオキニは開かない。
$list    = [];
$tally   = ['done' => 0, 'timeout' => 0, 'nofile' => 0, 'nofolder' => 0];
$sysBad  = 0;   // システム異常のある店舗数
$loginNg = 0;   // ログイン失敗のあった店舗数
foreach ($shops as $s) {
    $sum = log_day_summary((string)$s['folder_name'], $date);
    $tally[$sum['verdict']] = ($tally[$sum['verdict']] ?? 0) + 1;
    if (log_has_system_issue($sum)) {
        $sysBad++;
    }
    if ($sum['loginerr'] > 0) {
        $loginNg++;
    }
    if ($verdict !== '' && $sum['verdict'] !== $verdict) {
        continue;
    }
    $list[] = $s + ['sum' => $sum];
}

$total = count($list);
$rows  = array_slice($list, ($page - 1) * $perPage, $perPage);

// 日付の候補。全店舗のフォルダを走査すると重いので、直近30日を並べる。
$dateOptions = [];
for ($i = 0; $i < 30; $i++) {
    $dateOptions[] = log_date_shift(log_business_date(), -$i);
}
if (!in_array($date, $dateOptions, true)) {
    array_unshift($dateOptions, $date);
}

render_head('稼働ログ', 'logs');
?>

<form class="filter-card" method="get" action="logs.php">
    <div class="filter-row">
        <div class="filter-field">
            <label for="date">営業日</label>
            <select id="date" name="date">
                <?php foreach ($dateOptions as $d): ?>
                    <option value="<?= h($d) ?>"<?= $d === $date ? ' selected' : '' ?>><?= h(log_date_label($d)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label for="verdict">判定</label>
            <select id="verdict" name="verdict">
                <option value="">すべて</option>
                <?php foreach (['done' => '完了', 'timeout' => '時間切れ', 'nofile' => 'ファイルなし'] as $v => $lbl): ?>
                    <option value="<?= h($v) ?>"<?= $verdict === $v ? ' selected' : '' ?>><?= h($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label>表示範囲</label>
            <label class="check-inline">
                <input type="checkbox" name="all" value="1"<?= $showAll ? ' checked' : '' ?>>
                全店舗を表示（停止中・フォルダ未設定も含む）
            </label>
        </div>
        <div class="filter-actions">
            <button class="btn btn-main" type="submit"><i class="fa-solid fa-magnifying-glass"></i>表示</button>
            <a class="btn btn-outline" href="logs.php"><i class="fa-solid fa-rotate-left"></i>リセット</a>
        </div>
    </div>
</form>

<div class="date-nav">
    <a class="btn btn-outline btn-sm" href="<?= h(url_with(['date' => log_date_shift($date, -1), 'page' => 1])) ?>">
        <i class="fa-solid fa-chevron-left"></i>前日
    </a>
    <span class="date-nav-current"><?= h(log_date_label($date)) ?> の営業分</span>
    <a class="btn btn-outline btn-sm" href="<?= h(url_with(['date' => log_date_shift($date, 1), 'page' => 1])) ?>">
        翌日<i class="fa-solid fa-chevron-right"></i>
    </a>
    <a class="btn btn-link btn-sm" href="<?= h(url_with(['date' => log_business_date(), 'page' => 1])) ?>">最新の営業日へ</a>
</div>

<div class="summary-row">
    <?php
    summary_card('完了', number_format($tally['done']), 'fa-circle-check');
    summary_card('時間切れ', number_format($tally['timeout']), 'fa-hourglass-end', $tally['timeout'] > 0 ? 'alert' : '');
    summary_card('システム異常のある店舗', number_format($sysBad), 'fa-triangle-exclamation', $sysBad > 0 ? 'alert' : '');
    summary_card('ログイン失敗のある店舗', number_format($loginNg), 'fa-key', $loginNg > 0 ? 'warn' : '');
    ?>
</div>

<div class="section-card">
    <div class="head">
        <i class="fa-solid fa-list-check"></i>店舗別の稼働状況
        <span class="head-hint">店舗名をクリックすると、その日のログを開きます</span>
        <span class="count">全 <?= number_format($total) ?> 件</span>
    </div>
    <?php if (!$rows): ?>
        <div class="empty"><i class="fa-solid fa-inbox"></i>条件に合う店舗はありません。</div>
    <?php else: ?>
    <div class="table-scroll">
    <table class="data-table" id="log-table">
        <thead>
        <tr>
            <th style="width:64px">ID</th>
            <th style="min-width:150px">店舗名</th>
            <th style="width:130px">フォルダ名</th>
            <th style="width:140px">IP</th>
            <th style="width:120px">判定</th>
            <th style="width:90px" class="num">START</th>
            <th style="width:90px" class="num">report</th>
            <th style="width:100px" class="num">ログイン失敗</th>
            <th style="width:100px" class="num">異常キャスト</th>
            <th style="width:170px">最終行の時刻</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $sum    = $r['sum'];
            $folder = (string)$r['folder_name'];
            $canOpen = $folder !== '' && $sum['verdict'] !== 'nofolder';
            $link   = 'shop_logs.php?id=' . (int)$r['user_id'] . '&date=' . urlencode($date);
            ?>
            <tr<?= log_has_system_issue($sum) ? ' class="row-alert"' : '' ?>>
                <td class="id-col"><?= (int)$r['user_id'] ?></td>
                <td>
                    <div class="strong">
                        <?php if ($canOpen): ?>
                            <a href="<?= h($link) ?>"><?= h($r['username']) ?></a>
                        <?php else: ?>
                            <?= h($r['username']) ?>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="wrapcell"><?= $folder !== '' ? h($folder) : '<span class="soft">—</span>' ?></td>
                <td class="wrapcell"><?= $r['ip_address'] !== '' ? h($r['ip_address']) : '<span class="soft">—</span>' ?></td>
                <td>
                    <?= log_verdict_badge($sum['verdict']) ?>
                    <?php if ($sum['mismatch']): ?>
                        <span class="badge badge-danger" title="START件数と report + ログイン失敗の合計が合いません">取りこぼし</span>
                    <?php endif; ?>
                </td>
                <td class="num"><?= $sum['verdict'] === 'done' || $sum['verdict'] === 'timeout' ? number_format($sum['start']) : '<span class="soft">—</span>' ?></td>
                <td class="num"><?= $sum['verdict'] === 'done' || $sum['verdict'] === 'timeout' ? number_format($sum['report']) : '<span class="soft">—</span>' ?></td>
                <td class="num"><?= $sum['loginerr'] > 0 ? '<span class="num-warn">' . number_format($sum['loginerr']) . '</span>' : '<span class="soft">0</span>' ?></td>
                <td class="num"><?= $sum['bad_count'] > 0 ? '<span class="num-bad">' . number_format($sum['bad_count']) . '</span>' : '<span class="soft">0</span>' ?></td>
                <td class="muted"><?= $sum['last_at'] !== '' ? h($sum['last_at']) : '<span class="soft">—</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php render_pager($page, $total, $perPage); ?>
    <?php endif; ?>
</div>

<?php render_foot(); ?>
