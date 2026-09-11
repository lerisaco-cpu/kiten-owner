<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
require __DIR__ . '/lib/logfiles.php';
require_login();

$activeValue = (int)cfg('status_active_value', 1);
$perPage     = (int)cfg('per_page', 50);
$page        = max(1, (int)($_GET['page'] ?? 1));

$kw = trim((string)($_GET['kw'] ?? ''));

// 一覧に出す営業日。既定は「前日」。
// 営業日の変わり目は 5:55 なので、5:55 より前に開いた場合は log_business_date() が
// 1日戻し、そこからさらに1日前を見ることになる。
//   09/12 14:00 → 営業日 09/12 → 表示 09/11
//   09/12 03:00 → 営業日 09/11 → 表示 09/10
$date = (string)($_GET['date'] ?? '');
if (!log_date_valid($date)) {
    $date = log_date_shift(log_business_date(), -1);
}

// システム異常かログイン失敗のある店舗だけに絞る
$onlyBad = ($_GET['bad'] ?? '') === '1';

// 契約状況は「未指定＝契約中のみ」を初期値にする。
// 全件を見たいときは 'all' を明示的に選んでもらう（空文字だと url_with() で
// 落ちてしまい、ページングや並び替えで初期値に戻ってしまうため）。
const STATUS_ALL = 'all';
$statusParam = (string)($_GET['status'] ?? '');
$status = $statusParam === '' ? (string)$activeValue : $statusParam;

// 運営メモ（フォルダ名・IP）は shop_meta 側。行が無い店舗も一覧から消えないよう
// LEFT JOIN で読む。絞り込み条件に m.* を使うので、件数用のクエリにも同じ JOIN が要る。
$joinMeta = 'LEFT JOIN shop_meta m ON m.user_id = u.user_id';

$where  = [];
$params = [];

if ($kw !== '') {
    $where[] = '(u.username LIKE ? OR u.email LIKE ? OR u.tantou LIKE ? OR u.tel LIKE ?'
             . ' OR m.folder_name LIKE ? OR m.ip_address LIKE ? OR u.user_id = ?)';
    $like = '%' . $kw . '%';
    array_push($params, $like, $like, $like, $like, $like, $like, (int)$kw);
}
if ($status !== STATUS_ALL) {
    $where[] = 'u.status = ?';
    $params[] = (int)$status;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sortMap = [
    'id'     => 'u.user_id',
    'name'   => 'u.username',
    'status' => 'u.status',
    'girls'  => 'girl_active',
    'last'   => 's.last_success_at',
];
$sort = $_GET['sort'] ?? 'id';
$col  = $sortMap[$sort] ?? 'u.user_id';
$dir  = (($_GET['dir'] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';

$canEditMeta    = can_edit();   // 一覧のインライン編集を出すかどうか
$hasStatusTable = table_exists('exec_status');
$joinStatus = $hasStatusTable ? 'LEFT JOIN exec_status s ON s.user_id = u.user_id' : '';
$selStatus  = $hasStatusTable ? 's.last_success_at, s.fail_streak,' : '';
if (!$hasStatusTable && $sort === 'last') {
    $col = 'u.user_id';
}

// 「異常のみ」で絞るときは、全店舗のログを読まないと件数が確定しない。
// 絞らないときは、表示する1ページ分だけ読めば足りるので SQL 側でページングする。
$offset   = ($page - 1) * $perPage;
$limitSql = $onlyBad ? '' : "\n      LIMIT $perPage OFFSET $offset";

$rows = q(
    "SELECT u.user_id, u.username, u.email, u.status,
            $selStatus
            m.folder_name, m.ip_address,
            COALESCE(g.total, 0)  AS girl_total,
            COALESCE(g.active, 0) AS girl_active
       FROM users u
       LEFT JOIN (
            SELECT shopr_id, COUNT(*) AS total, SUM(kitengirl_status = 1) AS active
              FROM kiten_girl GROUP BY shopr_id
       ) g ON g.shopr_id = u.user_id
       $joinStatus
       $joinMeta
       $whereSql
      ORDER BY $col $dir" . $limitSql,
    $params
);

/*
 * 選んだ営業日のログを1店舗ずつ読む。
 *
 * 読むのは status / report / loginerr の3つだけで、mitene_report と okini_report は
 * 開かない（ミテネは1日1,300行を超えることがあるため）。status も START行・FINISHの有無・
 * 最終行だけを1回流して拾い、全行を配列に貯めない。
 *
 * 速度: 契約中167店舗すべてにフォルダを設定した状態で、全店舗ぶんの走査が約30ms。
 * 「異常のみ」を外している間は1ページ分（50店舗）しか読まないので、さらに軽い。
 * 店舗数が増えて体感が重くなるようなら、この集計をキャッシュする方式に切り替えること。
 */
foreach ($rows as $i => $r) {
    $rows[$i]['sum'] = log_day_summary((string)($r['folder_name'] ?? ''), $date);
}

if ($onlyBad) {
    $rows  = array_values(array_filter($rows, static fn(array $r): bool => log_has_any_issue($r['sum'])));
    $total = count($rows);
    $rows  = array_slice($rows, $offset, $perPage);
} else {
    $total = (int)qv("SELECT COUNT(*) FROM users u $joinMeta $whereSql", $params);
}

/**
 * フォルダ名 / IP のセル。編集できるときは、JS が使う値を data-* に持たせる。
 * 表示値は h()、data-value も h() を通すので、どちらもそのまま出ることはない。
 */
function meta_cell(int $userId, string $field, string $value, int $maxLen, bool $editable): string
{
    $shown = $value !== '' ? h($value) : '<span class="soft">—</span>';
    if (!$editable) {
        return '<td class="wrapcell">' . $shown . '</td>';
    }
    return '<td class="wrapcell meta-cell"'
         . ' data-id="' . $userId . '"'
         . ' data-field="' . h($field) . '"'
         . ' data-value="' . h($value) . '"'
         . ' data-max="' . $maxLen . '"'
         . ' title="ダブルクリックで編集">' . $shown . '</td>';
}

function sort_link(string $key, string $labelText): string
{
    $cur = $_GET['sort'] ?? 'id';
    $dir = ($cur === $key && ($_GET['dir'] ?? 'asc') === 'asc') ? 'desc' : 'asc';
    $mark = $cur === $key ? (($_GET['dir'] ?? 'asc') === 'asc' ? ' <i class="fa-solid fa-caret-up"></i>' : ' <i class="fa-solid fa-caret-down"></i>') : '';
    return '<a href="' . h(url_with(['sort' => $key, 'dir' => $dir, 'page' => 1])) . '">' . h($labelText) . $mark . '</a>';
}

// 日付の候補。全店舗のフォルダを走査すると重いので、直近30営業日を並べる。
$dateOptions = [];
for ($i = 0; $i < 30; $i++) {
    $dateOptions[] = log_date_shift(log_business_date(), -$i);
}
if (!in_array($date, $dateOptions, true)) {
    array_unshift($dateOptions, $date);
}

render_head('店舗管理', 'shops');
?>

<form class="filter-card" method="get" action="shops.php">
    <?php /* 検索し直しても、選んでいる営業日を保つ */ ?>
    <input type="hidden" name="date" value="<?= h($date) ?>">
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
            <label>ログ</label>
            <label class="check-inline">
                <input type="checkbox" name="bad" value="1"<?= $onlyBad ? ' checked' : '' ?>>
                異常のみ表示
            </label>
        </div>
        <div class="filter-actions">
            <button class="btn btn-main" type="submit"><i class="fa-solid fa-magnifying-glass"></i>検索</button>
            <a class="btn btn-outline" href="shops.php"><i class="fa-solid fa-rotate-left"></i>リセット</a>
        </div>
    </div>
</form>

<div class="date-nav">
    <a class="btn btn-outline btn-sm" href="<?= h(url_with(['date' => log_date_shift($date, -1), 'page' => 1])) ?>">
        <i class="fa-solid fa-chevron-left"></i>前日
    </a>
    <form method="get" action="shops.php" class="date-nav-form">
        <?php /* 日付だけを変えて、検索条件は保つ */ ?>
        <?php if ($kw !== ''): ?><input type="hidden" name="kw" value="<?= h($kw) ?>"><?php endif; ?>
        <input type="hidden" name="status" value="<?= h($status) ?>">
        <?php if ($onlyBad): ?><input type="hidden" name="bad" value="1"><?php endif; ?>
        <select name="date" onchange="this.form.submit()">
            <?php foreach ($dateOptions as $d): ?>
                <option value="<?= h($d) ?>"<?= $d === $date ? ' selected' : '' ?>><?= h(log_date_label($d)) ?></option>
            <?php endforeach; ?>
        </select>
        <noscript><button class="btn btn-outline btn-sm" type="submit">表示</button></noscript>
    </form>
    <a class="btn btn-outline btn-sm" href="<?= h(url_with(['date' => log_date_shift($date, 1), 'page' => 1])) ?>">
        翌日<i class="fa-solid fa-chevron-right"></i>
    </a>
    <span class="date-nav-current"><?= h(log_date_label($date)) ?> の営業分のログで判定しています</span>
</div>

<div class="section-card">
    <div class="head">
        <i class="fa-solid fa-users"></i>店舗一覧
        <?php if ($canEditMeta): ?>
            <span class="head-hint">フォルダ名 / IP はダブルクリックで編集できます</span>
        <?php endif; ?>
        <span class="count">全 <?= number_format($total) ?> 件</span>
    </div>
    <?php if (!$rows): ?>
        <div class="empty"><i class="fa-solid fa-magnifying-glass"></i>条件に合う店舗はありません。条件を変えて検索してください。</div>
    <?php else: ?>
    <div class="table-scroll">
    <table class="data-table" id="shop-table"<?= $canEditMeta ? ' data-csrf="' . h(csrf_token()) . '"' : '' ?>>
        <thead>
        <tr>
            <th style="width:64px"><?= sort_link('id', 'ID') ?></th>
            <th style="min-width:150px"><?= sort_link('name', '店舗名') ?></th>
            <th style="width:260px">ログインID</th>
            <th style="width:170px">フォルダ名</th>
            <th style="width:140px">IP</th>
            <th style="width:110px"><?= sort_link('girls', 'キャスト') ?></th>
            <th style="width:90px"><?= sort_link('status', '契約') ?></th>
            <th style="width:230px">システム異常</th>
            <th style="width:110px">ログイン失敗</th>
            <?php if ($hasStatusTable): ?><th style="width:120px"><?= sort_link('last', '稼働状況') ?></th><?php endif; ?>
            <th style="width:150px"></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $isActive = (int)$r['status'] === $activeValue;
            $sum      = $r['sum'];
            $logUrl   = 'shop_logs.php?id=' . (int)$r['user_id'] . '&date=' . urlencode($date);
            $folder   = (string)($r['folder_name'] ?? '');
            $ipAddr   = (string)($r['ip_address'] ?? '');
            $loginId  = (string)($r['email'] ?? '');
            ?>
            <tr<?= log_has_system_issue($sum) ? ' class="row-alert"' : '' ?>>
                <td class="id-col"><?= (int)$r['user_id'] ?></td>
                <td>
                    <div class="strong"><a href="shop_edit.php?id=<?= (int)$r['user_id'] ?>"><?= h($r['username']) ?></a></div>
                </td>
                <td class="wrapcell">
                    <div class="id-col"><?= $loginId !== '' ? h($loginId) : '<span class="soft">—</span>' ?></div>
                </td>
                <?= meta_cell($r['user_id'], 'folder_name', $folder, 191, $canEditMeta) ?>
                <?= meta_cell($r['user_id'], 'ip_address', $ipAddr, 45, $canEditMeta) ?>
                <td class="num">
                    <?= number_format((int)$r['girl_active']) ?>
                    <span class="soft">/ <?= number_format((int)$r['girl_total']) ?></span>
                </td>
                <td>
                    <span class="badge <?= $isActive ? 'badge-active' : 'badge-inactive' ?>"><?= h(label_of('status_labels', $r['status'])) ?></span>
                </td>
                <td class="wrapcell">
                    <?php if ($sum['verdict'] === 'nofolder'): ?>
                        <?= log_issue_badges($sum) ?>
                    <?php else: ?>
                        <a class="plain" href="<?= h($logUrl) ?>"><?= log_issue_badges($sum) ?></a>
                    <?php endif; ?>
                </td>
                <td class="num"><?= log_login_badge($sum) ?></td>
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
                    <?php if ($sum['verdict'] !== 'nofolder'): ?>
                        <a class="btn btn-outline btn-sm" href="<?= h($logUrl) ?>"><i class="fa-solid fa-list-check"></i>ログ</a>
                    <?php endif; ?>
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

<?php if ($canEditMeta): ?>
<div class="toast-area" id="toast-area"></div>
<script>
(function () {
    var table = document.getElementById('shop-table');
    if (!table) { return; }
    var csrf = table.getAttribute('data-csrf');
    var area = document.getElementById('toast-area');

    /** 右下に数秒だけ出す通知。保存の失敗理由をここに出す。 */
    function toast(msg, type) {
        var box = document.createElement('div');
        box.className = 'alert-box alert-' + type;
        box.textContent = msg;
        area.appendChild(box);
        setTimeout(function () { box.remove(); }, 6000);
    }

    /** セルを通常の表示状態に戻す。値は data-value が正。 */
    function render(td) {
        var v = td.getAttribute('data-value');
        td.textContent = '';
        if (v === '') {
            var s = document.createElement('span');
            s.className = 'soft';
            s.textContent = '—';
            td.appendChild(s);
        } else {
            td.appendChild(document.createTextNode(v));
        }
    }

    function flash(td, cls) {
        td.classList.add(cls);
        setTimeout(function () { td.classList.remove(cls); }, 2000);
    }

    function beginEdit(td) {
        if (td.classList.contains('is-editing') || td.classList.contains('is-saving')) { return; }
        td.classList.add('is-editing');

        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'meta-input';
        input.value = td.getAttribute('data-value');
        input.maxLength = parseInt(td.getAttribute('data-max'), 10) || 191;
        td.textContent = '';
        td.appendChild(input);
        input.focus();
        input.select();

        var done = false;
        function finish(save) {
            if (done) { return; }
            done = true;
            var next = input.value;
            td.classList.remove('is-editing');
            if (!save || next === td.getAttribute('data-value')) {
                render(td);           // Esc、または変更なし
                return;
            }
            commit(td, next);
        }
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); finish(true); }
            else if (e.key === 'Escape') { e.preventDefault(); finish(false); }
        });
        input.addEventListener('blur', function () { finish(true); });
    }

    function commit(td, value) {
        td.classList.add('is-saving');
        td.textContent = value === '' ? '—' : value;

        var body = new URLSearchParams();
        body.set('_csrf', csrf);
        body.set('user_id', td.getAttribute('data-id'));
        body.set('field', td.getAttribute('data-field'));
        body.set('value', value);

        fetch('api_shop_meta.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (res) {
            return res.text().then(function (text) {
                var data = null;
                try { data = JSON.parse(text); } catch (e) { /* JSON以外はそのまま理由として扱う */ }
                if (!res.ok || !data || !data.ok) {
                    // サーバーが返した理由をそのまま利用者に見せる
                    var err = new Error(
                        (data && data.error) ||
                        text.trim().slice(0, 200) ||
                        ('保存できませんでした（HTTP ' + res.status + '）。')
                    );
                    err.fromServer = true;
                    throw err;
                }
                return data;
            });
        }).then(function (data) {
            td.classList.remove('is-saving');
            td.setAttribute('data-value', data.value);   // 桁数を超えた分はサーバー側で切られる
            render(td);
            flash(td, 'is-saved');
        }).catch(function (err) {
            td.classList.remove('is-saving');
            render(td);                                  // data-value は変えていないので元の値に戻る
            flash(td, 'is-error');
            // 通信そのものが失敗した場合、err.message はブラウザ既定の英文なので出さない
            toast(
                (err && err.fromServer && err.message)
                    ? err.message
                    : '保存できませんでした。通信の状態を確認して、もう一度お試しください。',
                'bad'
            );
        });
    }

    table.addEventListener('dblclick', function (e) {
        var td = e.target.closest('td.meta-cell');
        if (td) { beginEdit(td); }
    });
})();
</script>
<?php endif; ?>

<?php render_foot(); ?>
