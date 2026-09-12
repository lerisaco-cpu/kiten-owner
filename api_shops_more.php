<?php
/**
 * 店舗一覧の続きを読み込むAPI、兼、一覧の共通処理。
 *
 * shops.php からは require されて、条件の解釈・取得・行の描画を共有します。
 * ブラウザから直接呼ばれたときだけ、続きの行を JSON で返します。
 *
 * 追加読み込みでは、必要な範囲だけを読みます。SQL 側の読み出し位置を
 * db_offset として受け取り、そこから必要な件数が揃うまでだけ進めるので、
 * 1回の追加で全店舗のログを読み直すことはありません。
 */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/logfiles.php';

// 契約状況の「すべて」。空文字だと url_with() で落ちてしまうので明示的な値を持つ。
const STATUS_ALL = 'all';

// 媒体の判定。数値比較だと 'eki123' = 0 が真になってしまうので、文字の形を見る。
const FOLDER_IS_DIGITS = "COALESCE(m.folder_name, '') REGEXP '^[0-9]+$'";

/** システム異常の絞り込みの選択肢 */
function shops_issue_options(): array
{
    return [
        ''         => 'すべて',
        'any'      => '異常あり（いずれか）',
        'timeout'  => '時間切れ',
        'badcast'  => '異常キャスト',
        'mismatch' => '件数の不一致',
        'loginerr' => 'ログイン失敗あり',
        'none'     => '異常なし',
    ];
}

/** 媒体の絞り込みの選択肢 */
function shops_media_options(): array
{
    return ['' => 'すべて', 'heaven' => 'ヘブン', 'eki' => '駅チカ'];
}

function shops_sort_map(): array
{
    return [
        'id'     => 'u.user_id',
        'name'   => 'u.username',
        'status' => 'u.status',
        'girls'  => 'girl_active',
    ];
}

/**
 * 画面と API で同じ検証を通すため、条件の解釈はここ1か所にまとめます。
 * 受け取った値が選択肢に無ければ既定に戻すので、勝手な値は入り込みません。
 */
function shops_params(array $get): array
{
    $activeValue = (int)cfg('status_active_value', 1);

    // 営業日。既定は「前日」。営業日の変わり目は 5:55。
    $date = (string)($get['date'] ?? '');
    if (!log_date_valid($date)) {
        $date = log_date_shift(log_business_date(), -1);
    }

    $issue = (string)($get['issue'] ?? '');
    if (!isset(shops_issue_options()[$issue])) {
        $issue = '';
    }

    $media = (string)($get['media'] ?? '');
    if (!isset(shops_media_options()[$media])) {
        $media = '';
    }

    $statusParam = (string)($get['status'] ?? '');
    $status = $statusParam === '' ? (string)$activeValue : $statusParam;

    $sort = (string)($get['sort'] ?? 'id');
    if (!isset(shops_sort_map()[$sort])) {
        $sort = 'id';
    }

    return [
        'date'   => $date,
        'kw'     => trim((string)($get['kw'] ?? '')),
        'status' => $status,
        'media'  => $media,
        'issue'  => $issue,
        'sort'   => $sort,
        'dir'    => ((string)($get['dir'] ?? 'asc') === 'desc') ? 'desc' : 'asc',
    ];
}

/** 条件から WHERE 句とプレースホルダの値を組み立てる */
function shops_where(array $p): array
{
    $activeValue = (int)cfg('status_active_value', 1);
    $where  = [];
    $params = [];

    if ($p['kw'] !== '') {
        $where[] = '(u.username LIKE ? OR u.email LIKE ? OR u.tantou LIKE ? OR u.tel LIKE ?'
                 . ' OR m.folder_name LIKE ? OR m.ip_address LIKE ? OR u.user_id = ?)';
        $like = '%' . $p['kw'] . '%';
        array_push($params, $like, $like, $like, $like, $like, $like, (int)$p['kw']);
    }
    if ($p['status'] !== STATUS_ALL) {
        $where[] = 'u.status = ?';
        $params[] = (int)$p['status'];
    }
    if ($p['media'] === 'heaven') {
        $where[] = FOLDER_IS_DIGITS;
    } elseif ($p['media'] === 'eki') {
        $where[] = 'NOT (' . FOLDER_IS_DIGITS . ')';
    }

    return [$where ? ('WHERE ' . implode(' AND ', $where)) : '', $params];
}

/** 一覧の SELECT。並び順まで込みで、LIMIT だけを呼び出し側が足す。 */
function shops_select_sql(array $p, string $whereSql): string
{
    $col = shops_sort_map()[$p['sort']] ?? 'u.user_id';
    $dir = $p['dir'] === 'desc' ? 'DESC' : 'ASC';

    return "SELECT u.user_id, u.username, u.email, u.status,
                   m.folder_name,
                   COALESCE(g.total, 0)  AS girl_total,
                   COALESCE(g.active, 0) AS girl_active
              FROM users u
              LEFT JOIN (
                   SELECT shopr_id, COUNT(*) AS total, SUM(kitengirl_status = 1) AS active
                     FROM kiten_girl GROUP BY shopr_id
              ) g ON g.shopr_id = u.user_id
              LEFT JOIN shop_meta m ON m.user_id = u.user_id
              $whereSql
             ORDER BY $col $dir, u.user_id ASC";
}

/** 選んだ種類に当てはまるか。条件は log_day_summary() の結果だけで判定する。 */
function issue_matches(string $issue, array $sum): bool
{
    switch ($issue) {
        case 'any':      return log_has_any_issue($sum);
        case 'timeout':  return $sum['verdict'] === 'timeout';
        case 'badcast':  return $sum['bad_count'] > 0;
        case 'mismatch': return $sum['mismatch'];
        case 'loginerr': return $sum['loginerr'] > 0;
        case 'none':     return !log_has_system_issue($sum);
    }
    return true;   // すべて
}

/**
 * ログイン失敗のキャストを停止する SQL。1行1文、改行区切りで返します。
 * 作るのは貼り付け用のテキストだけで、この画面から実行はしません。
 *
 * shopr_id を条件に入れているのは、himedeco_id だけだと他店舗の同じ ID まで
 * 巻き込んで更新してしまうためです。必ず両方で絞ります。
 */
function stop_sql_for(int $userId, array $sum): string
{
    $path = $sum['files']['loginerr'] ?? null;
    if ($path === null) {
        return '';
    }
    $lines = [];
    foreach (log_rows($path) as $f) {
        $r = log_row_loginerr($f);
        // ID が数字だけでない行は飛ばす
        if (preg_match('/\A[0-9]+\z/', $r['id']) !== 1) {
            continue;
        }
        // 種別はログの値をそのまま使うので、SQL に埋め込む前にエスケープする。
        // MySQL の既定では \ も引用符の中で意味を持つので、あわせて処理する。
        $kind = str_replace(['\\', "'"], ['\\\\', "''"], $r['kind']);
        $lines[] = "UPDATE kiten_girl SET memo = '" . $kind
                 . "', kitengirl_status ='1' WHERE shopr_id = " . $userId
                 . ' AND himedeco_id = ' . $r['id'] . ';';
    }
    return implode("\n", $lines);
}

/**
 * SQL の読み出し位置 $dbOffset から、表示できる行が $want 件たまるまでだけ進めます。
 *
 * 戻り値の consumed は「SQL から何行読んだか」で、次の呼び出しの起点になります。
 * システム異常で絞っているときは読んだ行の一部しか残らないので、表示件数ではなく
 * この値を持ち回ることで、同じ行を二度読むことも、全件を読み直すこともありません。
 *
 * 1店舗あたり読むログは status / report / loginerr の3つだけです。
 * mitene_report と okini_report は開きません。
 */
function shops_fetch(array $p, int $dbOffset, int $want): array
{
    [$whereSql, $params] = shops_where($p);
    $sql   = shops_select_sql($p, $whereSql);
    $chunk = max($want, 50);

    $rows      = [];
    $consumed  = 0;
    $exhausted = false;

    while (count($rows) < $want) {
        $batch = q($sql . ' LIMIT ' . $chunk . ' OFFSET ' . ($dbOffset + $consumed), $params);
        $n     = count($batch);
        if ($n === 0) {
            $exhausted = true;
            break;
        }

        $seen = 0;
        foreach ($batch as $r) {
            $seen++;
            $consumed++;
            $r['sum'] = log_day_summary((string)($r['folder_name'] ?? ''), $p['date']);
            if ($p['issue'] !== '' && !issue_matches($p['issue'], $r['sum'])) {
                continue;
            }
            // 表示する行だけ SQL を組み立てる
            $r['stop_sql'] = $r['sum']['loginerr'] > 0 ? stop_sql_for((int)$r['user_id'], $r['sum']) : '';
            $rows[] = $r;
            if (count($rows) >= $want) {
                break;
            }
        }

        // 取り切ったところで表の終わりに達していれば、これ以上は無い
        if ($n < $chunk && $seen === $n) {
            $exhausted = true;
            break;
        }
        if (count($rows) >= $want) {
            break;
        }
    }

    return ['rows' => $rows, 'consumed' => $consumed, 'exhausted' => $exhausted];
}

/**
 * 条件に合う総件数。
 *
 * システム異常で絞らないときは COUNT(*) で足ります。
 * 絞るときはログを読まないと分からないので全店舗を1度だけ数えますが、
 * これは画面を開いたときの1回だけで、追加読み込みでは呼びません。
 */
function shops_total(array $p): int
{
    [$whereSql, $params] = shops_where($p);

    if ($p['issue'] === '') {
        return (int)qv(
            "SELECT COUNT(*) FROM users u LEFT JOIN shop_meta m ON m.user_id = u.user_id $whereSql",
            $params
        );
    }

    $n = 0;
    foreach (q(shops_select_sql($p, $whereSql), $params) as $r) {
        if (issue_matches($p['issue'], log_day_summary((string)($r['folder_name'] ?? ''), $p['date']))) {
            $n++;
        }
    }
    return $n;
}

/**
 * フォルダ名のセル。編集できるときは、JS が使う値を data-* に持たせる。
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

/**
 * 一覧の行を出力します。最初の表示も追加読み込みも同じものを使うので、
 * 2ページ目以降だけ見た目や属性が変わることはありません。
 */
function shops_render_rows(array $rows, string $date, bool $canEdit): void
{
    $activeValue = (int)cfg('status_active_value', 1);

    foreach ($rows as $r) {
        $isActive = (int)$r['status'] === $activeValue;
        $sum      = $r['sum'];
        $logUrl   = 'shop_logs.php?id=' . (int)$r['user_id'] . '&date=' . urlencode($date);
        $folder   = (string)($r['folder_name'] ?? '');
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
            <?= meta_cell((int)$r['user_id'], 'folder_name', $folder, 191, $canEdit) ?>
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
            <td class="num login-cell">
                <span class="login-slot">
                <?php if ($r['stop_sql'] !== ''):
                    $stopCount = substr_count($r['stop_sql'], "\n") + 1;
                    // ID が数字でない行は飛ばすので、ログの件数と文の数がずれることがある。
                    // 気づかずに少ない件数をコピーしてしまわないよう、印と説明を添える。
                    $partial = $stopCount < (int)$sum['loginerr'];
                    ?>
                    <button type="button" class="badge badge-warn sql-copy-badge"
                            data-sql="<?= h($r['stop_sql']) ?>"
                            title="<?= h($partial
                                ? 'ログイン失敗 ' . (int)$sum['loginerr'] . ' 件のうち、ID が数字の ' . $stopCount . ' 件ぶんだけコピーします。残りは ID が数字でないため対象外です。'
                                : 'クリックすると、停止する SQL ' . $stopCount . ' 文をコピーします') ?>">
                        <?= number_format((int)$sum['loginerr']) ?> 件<?= $partial ? '<span class="badge-partial">*</span>' : '' ?>
                    </button>
                <?php else: ?>
                    <?= log_login_badge($sum) ?>
                <?php endif; ?>
                </span>
            </td>
            <td class="act">
                <?php if ($sum['verdict'] !== 'nofolder'): ?>
                    <a class="btn btn-outline btn-sm" href="<?= h($logUrl) ?>"><i class="fa-solid fa-list-check"></i>ログ</a>
                <?php endif; ?>
                <a class="btn btn-link btn-sm" href="shop_edit.php?id=<?= (int)$r['user_id'] ?>"><i class="fa-solid fa-pen-to-square"></i>詳細</a>
            </td>
        </tr>
        <?php
    }
}

// ---------------------------------------------------------------------------
// ここから下は、ブラウザから直接呼ばれたときだけ動きます。
// shops.php は SHOPS_LIST_INCLUDE を定義してから読み込むので、関数の定義だけで止まります。
// ---------------------------------------------------------------------------
if (defined('SHOPS_LIST_INCLUDE')) {
    return;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** JSONを返して終了する */
function shops_more_out(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// 未ログインだと require_login() は login.php へ 302 で飛ばします。fetch から呼ぶと
// ログイン画面のHTMLが 200 で返ってしまい、画面側が理由を出せないので先に JSON で返す。
if (current_admin() === null) {
    shops_more_out(401, ['ok' => false, 'error' => 'ログインの有効期限が切れました。画面を再読み込みしてログインし直してください。']);
}
require_login();

$p        = shops_params($_GET);
$perPage  = (int)cfg('per_page', 50);
$dbOffset = max(0, (int)($_GET['db_offset'] ?? 0));

try {
    $got = shops_fetch($p, $dbOffset, $perPage);

    ob_start();
    shops_render_rows($got['rows'], $p['date'], can_edit());
    $html = (string)ob_get_clean();
} catch (Throwable $e) {
    // 本番は display_errors=0 なので、原因はエラーログに残して画面には一言だけ返す
    error_log('api_shops_more failed: ' . $e->getMessage());
    shops_more_out(500, ['ok' => false, 'error' => '続きを読み込めませんでした。時間をおいてやり直してください。']);
}

shops_more_out(200, [
    'ok'        => true,
    'html'      => $html,
    'count'     => count($got['rows']),
    'db_offset' => $dbOffset + $got['consumed'],
    'more'      => !$got['exhausted'],
]);
