<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
require __DIR__ . '/lib/logfiles.php';
require_login();

$id = (int)($_GET['id'] ?? 0);

$shop = q1(
    "SELECT u.user_id, u.username,
            COALESCE(m.folder_name, '') AS folder_name,
            COALESCE(m.ip_address,  '') AS ip_address
       FROM users u
       LEFT JOIN shop_meta m ON m.user_id = u.user_id
      WHERE u.user_id = ?",
    [$id]
);

if ($shop === null) {
    render_head('稼働ログ', 'logs');
    flash('この店舗は見つかりませんでした。', 'warn');
    echo '<a class="btn btn-outline" href="logs.php"><i class="fa-solid fa-arrow-left"></i>稼働ログへ戻る</a>';
    render_foot();
    exit;
}

$folder = (string)$shop['folder_name'];

// フォルダ名が未設定、または受け付けられない文字を含む場合はここで止める。
// 画面にはシステム上のパスを一切出さない。
$folderOk = $folder !== '' && log_dir($folder) !== null;

$dates = $folderOk ? log_available_dates($folder) : [];

// 日付。指定が無ければ最新の営業日。その日が無ければフォルダにある一番新しい日。
$date = (string)($_GET['date'] ?? '');
if (!log_date_valid($date)) {
    $date = log_business_date();
    if ($dates && !in_array($date, $dates, true)) {
        $date = $dates[0];
    }
}

$tabs = log_kinds();
$tab  = (string)($_GET['tab'] ?? 'status');
if (!isset($tabs[$tab])) {
    $tab = 'status';
}

$perPage = log_per_page();
$page    = max(1, (int)($_GET['page'] ?? 1));

$sum = $folderOk
    ? log_day_summary($folder, $date)
    : ['verdict' => 'nofolder', 'start' => 0, 'report' => 0, 'loginerr' => 0, 'taps' => 0,
       'okini' => 0, 'bad' => [], 'bad_count' => 0, 'last_at' => '', 'mismatch' => false, 'files' => []];

/**
 * 選択中のタブのファイルを1回だけ流し、表示するページ分の行だけを取り出します。
 * ミテネのように1,300行を超えるファイルでも、メモリに載るのは1ページ分だけです。
 */
function log_page_rows(string $path, string $kind, int $page, int $perPage): array
{
    $offset = ($page - 1) * $perPage;
    $seen   = 0;
    $rows   = [];
    foreach (log_rows($path) as $f) {
        if ($seen >= $offset && count($rows) < $perPage) {
            $rows[] = log_row($kind, $f);
        }
        $seen++;
    }
    return ['rows' => $rows, 'total' => $seen];
}

$tabPath  = $sum['files'][$tab] ?? null;
$tabRows  = [];
$tabTotal = 0;
if ($tabPath !== null) {
    $got      = log_page_rows($tabPath, $tab, $page, $perPage);
    $tabRows  = $got['rows'];
    $tabTotal = $got['total'];
}

// 直近7営業日の推移。フォルダに実在する日付のうち、選択中の日以前を新しい順に7件。
$trend = [];
foreach ($dates as $d) {
    if ($d > $date) {
        continue;
    }
    $trend[] = ['date' => $d, 'sum' => log_day_summary($folder, $d)];
    if (count($trend) >= 7) {
        break;
    }
}

$badNames = array_map(static fn(array $r): string => $r['name'], $sum['bad']);

render_head($shop['username'] . ' の稼働ログ', 'logs');
crumbs([
    '稼働ログ' => 'logs.php',
    $shop['username'] . '（ID ' . (int)$shop['user_id'] . '）' => null,
]);
?>

<?php if (!$folderOk): ?>
    <div class="alert-box alert-warn">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div>
            <?php if ($folder === ''): ?>
                この店舗にはフォルダ名が設定されていません。店舗詳細でフォルダ名を登録すると、ログを読み込めるようになります。
            <?php else: ?>
                設定されているフォルダ名ではログを読み込めませんでした。フォルダ名に使えるのは半角の英数字・ハイフン・アンダースコアだけです。
            <?php endif; ?>
        </div>
    </div>
    <a class="btn btn-outline" href="shop_edit.php?id=<?= (int)$shop['user_id'] ?>"><i class="fa-solid fa-pen-to-square"></i>店舗詳細で設定する</a>
    <?php render_foot(); exit; ?>
<?php endif; ?>

<div class="date-nav">
    <a class="btn btn-outline btn-sm" href="<?= h(url_with(['date' => log_date_shift($date, -1), 'page' => 1])) ?>">
        <i class="fa-solid fa-chevron-left"></i>前日
    </a>
    <form method="get" action="shop_logs.php" class="date-nav-form">
        <input type="hidden" name="id" value="<?= (int)$shop['user_id'] ?>">
        <input type="hidden" name="tab" value="<?= h($tab) ?>">
        <select name="date" onchange="this.form.submit()">
            <?php if (!$dates): ?>
                <option value="<?= h($date) ?>"><?= h(log_date_label($date)) ?></option>
            <?php else: ?>
                <?php if (!in_array($date, $dates, true)): ?>
                    <option value="<?= h($date) ?>" selected><?= h(log_date_label($date)) ?>（ログなし）</option>
                <?php endif; ?>
                <?php foreach ($dates as $d): ?>
                    <option value="<?= h($d) ?>"<?= $d === $date ? ' selected' : '' ?>><?= h(log_date_label($d)) ?></option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
        <noscript><button class="btn btn-outline btn-sm" type="submit">表示</button></noscript>
    </form>
    <a class="btn btn-outline btn-sm" href="<?= h(url_with(['date' => log_date_shift($date, 1), 'page' => 1])) ?>">
        翌日<i class="fa-solid fa-chevron-right"></i>
    </a>
    <span class="date-nav-current">フォルダ <?= h($folder) ?></span>
</div>

<div class="summary-row">
    <?php
    $vlabel = ['done' => '完了', 'timeout' => '時間切れ', 'nofile' => 'ファイルなし', 'nofolder' => 'フォルダ未設定'];
    summary_card('判定', $vlabel[$sum['verdict']] ?? '不明', 'fa-flag-checkered', $sum['verdict'] === 'timeout' ? 'alert' : '');
    summary_card('START件数', number_format($sum['start']), 'fa-play');
    summary_card('report行数', number_format($sum['report']), 'fa-table-list');
    summary_card('異常キャスト', number_format($sum['bad_count']), 'fa-triangle-exclamation', $sum['bad_count'] > 0 ? 'alert' : '');
    summary_card('ログイン失敗', number_format($sum['loginerr']), 'fa-key', $sum['loginerr'] > 0 ? 'warn' : '');
    summary_card('タップ数 合計', number_format($sum['taps']), 'fa-hand-pointer');
    summary_card('オキニ送信 合計', number_format($sum['okini']), 'fa-heart');
    ?>
</div>

<?php if ($sum['verdict'] === 'nofile'): ?>
    <div class="alert-box alert-info">
        <i class="fa-solid fa-circle-info"></i>
        <div><?= h(log_date_label($date)) ?> のログはこのフォルダにありません。上の日付から別の営業日を選んでください。</div>
    </div>
<?php endif; ?>

<?php if (log_has_system_issue($sum)): ?>
    <div class="alert-box alert-bad">
        <i class="fa-solid fa-circle-exclamation"></i>
        <div>
            <strong>システム異常</strong>
            <ul class="issue-list">
                <?php if ($sum['verdict'] === 'timeout'): ?>
                    <li>FINISH が記録されていません。件数に対して処理時間が足りず、完走できていません。</li>
                <?php endif; ?>
                <?php if ($sum['bad_count'] > 0): ?>
                    <li>
                        リストが読めていないキャストが <?= number_format($sum['bad_count']) ?> 件あります
                        （残数があるのに走査数が <?= (int)log_scan_min() ?> 未満）:
                        <?= h(implode('、', $badNames)) ?>
                    </li>
                <?php endif; ?>
                <?php if ($sum['mismatch']): ?>
                    <li>
                        START <?= number_format($sum['start']) ?> 件に対して、
                        report <?= number_format($sum['report']) ?> 行 + ログイン失敗 <?= number_format($sum['loginerr']) ?> 行 =
                        <?= number_format($sum['report'] + $sum['loginerr']) ?> 件でした。取りこぼしがあります。
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<?php if ($sum['loginerr'] > 0): ?>
    <div class="alert-box alert-warn">
        <i class="fa-solid fa-key"></i>
        <div>
            <strong>ログイン失敗が <?= number_format($sum['loginerr']) ?> 件</strong>
            あります。店舗側のアカウントの問題なので、システム異常とは別に確認してください。
            <a href="<?= h(url_with(['tab' => 'loginerr', 'page' => 1])) ?>">loginerr タブを開く</a>
        </div>
    </div>
<?php endif; ?>

<div class="section-card">
    <div class="log-tabs">
        <?php foreach ($tabs as $key => $meta):
            $exists = !empty($sum['files'][$key]);
            ?>
            <a class="log-tab<?= $key === $tab ? ' on' : '' ?><?= $exists ? '' : ' is-missing' ?>"
               href="<?= h(url_with(['tab' => $key, 'page' => 1])) ?>">
                <i class="fa-solid <?= h($meta['icon']) ?>"></i><?= h($meta['label']) ?>
                <?php if (!$exists): ?><span class="soft">（なし）</span><?php endif; ?>
            </a>
        <?php endforeach; ?>
        <span class="count"><?= $tabPath !== null ? number_format($tabTotal) . ' 行' : '' ?></span>
    </div>

    <?php if ($tabPath === null): ?>
        <div class="empty"><i class="fa-solid fa-file-circle-question"></i>この日は出力されていません。</div>
    <?php elseif (!$tabRows): ?>
        <div class="empty"><i class="fa-solid fa-inbox"></i>表示できる行がありません。</div>
    <?php else: ?>
    <div class="table-scroll">
        <?php if ($tab === 'status'): ?>
            <table class="data-table">
                <thead><tr>
                    <th style="width:190px">日時</th>
                    <th style="width:110px">状態</th>
                    <th>詳細</th>
                </tr></thead>
                <tbody>
                <?php foreach ($tabRows as $r): ?>
                    <tr>
                        <td class="muted"><?= h($r['at']) ?></td>
                        <td><?= log_state_badge($r['state']) ?></td>
                        <td class="wrapcell">
                            <?php if ($r['state'] === 'START'): ?>
                                キャスト <?= h($r['detail']) ?> 件
                            <?php elseif ($r['state'] === 'WAIT'): ?>
                                <span class="muted"><?= h($r['detail']) ?> 秒 待機</span>
                            <?php elseif ($r['detail'] === ''): ?>
                                <span class="soft">—</span>
                            <?php else: ?>
                                <?= h($r['detail']) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

        <?php elseif ($tab === 'report'): ?>
            <table class="data-table">
                <thead><tr>
                    <th style="width:110px">ID</th>
                    <th style="min-width:140px">キャスト名</th>
                    <th style="width:90px" class="num">残数</th>
                    <th style="width:90px" class="num">タップ数</th>
                    <th style="width:90px" class="num">走査数</th>
                    <th style="width:110px" class="num">オキニ送信</th>
                    <th style="width:170px">日時</th>
                </tr></thead>
                <tbody>
                <?php foreach ($tabRows as $r):
                    $bad = $r['remain'] > 0 && $r['scans'] < log_scan_min();
                    ?>
                    <tr<?= $bad ? ' class="row-alert"' : '' ?>>
                        <td class="id-col"><?= h($r['id']) ?></td>
                        <td>
                            <?= h($r['name']) ?>
                            <?php if ($bad): ?>
                                <span class="badge badge-danger" title="残数があるのに走査数が足りません">リスト未取得</span>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= number_format($r['remain']) ?></td>
                        <td class="num"><?= number_format($r['taps']) ?></td>
                        <td class="num<?= $bad ? ' num-bad' : '' ?>"><?= number_format($r['scans']) ?></td>
                        <td class="num"><?= number_format($r['okini']) ?></td>
                        <td class="muted"><?= h($r['at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

        <?php elseif ($tab === 'mitene'): ?>
            <table class="data-table">
                <thead><tr>
                    <th style="min-width:140px">キャスト名</th>
                    <th style="min-width:200px">会員名</th>
                    <th style="width:170px">ミテネ履歴日</th>
                    <th style="width:120px">結果</th>
                </tr></thead>
                <tbody>
                <?php foreach ($tabRows as $r): ?>
                    <tr>
                        <td><?= h($r['name']) ?></td>
                        <td class="wrapcell"><?= h($r['member']) ?></td>
                        <td class="muted"><?= h($r['day']) ?></td>
                        <td>
                            <span class="badge <?= $r['result'] === 'タップ済み' ? 'badge-active' : 'badge-inactive' ?>"><?= h($r['result']) ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

        <?php elseif ($tab === 'okini'): ?>
            <table class="data-table">
                <thead><tr>
                    <th style="min-width:140px">キャスト名</th>
                    <th style="min-width:200px">会員名</th>
                    <th style="min-width:220px">結果</th>
                </tr></thead>
                <tbody>
                <?php foreach ($tabRows as $r):
                    $ok = $r['result'] === '送信済み';
                    $ng = strpos($r['result'], '失敗') !== false;
                    ?>
                    <tr>
                        <td><?= h($r['name']) ?></td>
                        <td class="wrapcell"><?= $r['member'] !== '' && $r['member'] !== '-' ? h($r['member']) : '<span class="soft">—</span>' ?></td>
                        <td class="wrapcell">
                            <span class="badge <?= $ok ? 'badge-active' : ($ng ? 'badge-danger' : 'badge-inactive') ?>"><?= h($r['result']) ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

        <?php else: ?>
            <table class="data-table">
                <thead><tr>
                    <th style="width:150px">種別</th>
                    <th style="min-width:140px">ID</th>
                    <th style="min-width:140px">パスワード</th>
                    <th style="min-width:140px">キャスト名</th>
                    <th style="width:170px">日時</th>
                </tr></thead>
                <tbody>
                <?php foreach ($tabRows as $r): ?>
                    <tr>
                        <td><span class="badge badge-warn"><?= h($r['kind']) ?></span></td>
                        <td class="wrapcell"><?= h($r['id']) ?></td>
                        <td class="wrapcell id-col"><?= h($r['pw']) ?></td>
                        <td><?= h($r['name']) ?></td>
                        <td class="muted"><?= h($r['at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php render_pager($page, $tabTotal, $perPage); ?>
    <?php endif; ?>
</div>

<?php if ($trend): ?>
<div class="section-card">
    <div class="head">
        <i class="fa-solid fa-chart-line"></i>直近7営業日の推移
        <span class="head-hint">このフォルダにログがある日だけを、新しい順に並べています</span>
    </div>
    <div class="table-scroll">
    <table class="data-table">
        <thead><tr>
            <th style="width:130px">営業日</th>
            <th style="width:120px">判定</th>
            <th style="width:90px" class="num">START</th>
            <th style="width:90px" class="num">report</th>
            <th style="width:110px" class="num">タップ数</th>
            <th style="width:110px" class="num">オキニ送信</th>
            <th style="width:110px" class="num">ログイン失敗</th>
            <th style="width:110px" class="num">異常キャスト</th>
        </tr></thead>
        <tbody>
        <?php foreach ($trend as $t):
            $ts = $t['sum'];
            ?>
            <tr<?= $t['date'] === $date ? ' class="row-on"' : '' ?>>
                <td>
                    <a href="<?= h(url_with(['date' => $t['date'], 'page' => 1])) ?>"><?= h(log_date_label($t['date'])) ?></a>
                </td>
                <td><?= log_verdict_badge($ts['verdict']) ?></td>
                <td class="num"><?= number_format($ts['start']) ?></td>
                <td class="num"><?= number_format($ts['report']) ?></td>
                <td class="num"><?= number_format($ts['taps']) ?></td>
                <td class="num"><?= number_format($ts['okini']) ?></td>
                <td class="num"><?= $ts['loginerr'] > 0 ? '<span class="num-warn">' . number_format($ts['loginerr']) . '</span>' : '<span class="soft">0</span>' ?></td>
                <td class="num"><?= $ts['bad_count'] > 0 ? '<span class="num-bad">' . number_format($ts['bad_count']) . '</span>' : '<span class="soft">0</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<div class="form-actions">
    <a class="btn btn-outline" href="logs.php?date=<?= h($date) ?>"><i class="fa-solid fa-arrow-left"></i>稼働ログ一覧へ</a>
    <a class="btn btn-link" href="shop_edit.php?id=<?= (int)$shop['user_id'] ?>"><i class="fa-solid fa-pen-to-square"></i>店舗詳細</a>
</div>

<?php render_foot(); ?>
