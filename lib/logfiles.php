<?php
declare(strict_types=1);

/**
 * iMacros が店舗フォルダに吐き出すログファイルの読み取り。
 *
 * 置き場所は  {log_root}/{folder_name}/{log_subdir}/  で、営業日ごとに5種類のファイルが出ます。
 * サブディレクトリ名は設定で変えられ、空文字にすると店舗フォルダの直下を見ます。
 * データベースは一切見ません。ファイルだけで完結します。
 *
 * ファイルの共通仕様（実データで確認済み）
 *   - UTF-8 BOM付き（先頭3バイト EF BB BF）
 *   - 改行は CRLF
 *   - ヘッダー行なし
 *   - 1行全体が " で囲まれている。前後の " を外してからカンマで分割する
 *   - 営業日の変わり目は 5:55。ファイル名の日付はカレンダー日ではなく営業日
 */

/** 営業日の切り替わり時刻（5:55） */
const LOG_DAY_START_HOUR = 5;
const LOG_DAY_START_MIN  = 55;

/**
 * 扱うファイルの種類。
 * loginerr だけ日付の前にアンダースコアが無いので、雛形で持ちます。
 */
function log_kinds(): array
{
    return [
        'status'   => ['pattern' => 'status_%s.txt',        'label' => 'status',   'icon' => 'fa-signal'],
        'report'   => ['pattern' => 'report_%s.txt',        'label' => 'report',   'icon' => 'fa-table-list'],
        'mitene'   => ['pattern' => 'mitene_report_%s.txt', 'label' => 'ミテネ',    'icon' => 'fa-eye'],
        'okini'    => ['pattern' => 'okini_report_%s.txt',  'label' => 'オキニ',    'icon' => 'fa-heart'],
        'loginerr' => ['pattern' => 'loginerr%s.txt',       'label' => 'ログイン',  'icon' => 'fa-key'],
    ];
}

/** ログの置き場所のルート */
function log_root(): string
{
    return rtrim((string)cfg('log_root', '/home/lerisa/public_html/kiten'), '/');
}

/**
 * 店舗フォルダのどのサブディレクトリにログが入っているか。
 * 空文字にすると店舗フォルダの直下を見ます。
 */
function log_subdir(): string
{
    return trim((string)cfg('log_subdir', 'log'), '/');
}

/** 「残数があるのに走査数がこれ未満」なら、リストが読めていないと見なす */
function log_scan_min(): int
{
    return (int)cfg('log_scan_min', 5);
}

/** 明細を1ページに何行出すか */
function log_per_page(): int
{
    return max(20, (int)cfg('log_per_page', 200));
}

/**
 * フォルダ名として受け付けてよい文字だけか。
 * パスの区切りや .. を混ぜられないよう、英数字とハイフン・アンダースコアだけ許します。
 */
function log_folder_valid(string $folder): bool
{
    return $folder !== '' && preg_match('/\A[A-Za-z0-9_-]+\z/', $folder) === 1;
}

/** 日付は YYYYMMDD の8桁。実在する日付かどうかも見ます。 */
function log_date_valid(string $date): bool
{
    if (preg_match('/\A\d{8}\z/', $date) !== 1) {
        return false;
    }
    return checkdate((int)substr($date, 4, 2), (int)substr($date, 6, 2), (int)substr($date, 0, 4));
}

/**
 * 店舗フォルダ自体の実パス（{log_root}/{folder}）。ログの置き場所ではありません。
 * フォルダ名の打ち間違いと、log サブディレクトリ未作成とを画面で区別するために使います。
 */
function log_shop_dir(string $folder): ?string
{
    if (!log_folder_valid($folder)) {
        return null;
    }
    $root = realpath(log_root());
    if ($root === false) {
        return null;
    }
    $dir = realpath($root . DIRECTORY_SEPARATOR . $folder);
    if ($dir === false || !is_dir($dir)) {
        return null;
    }
    if ($dir !== $root && strpos($dir, $root . DIRECTORY_SEPARATOR) !== 0) {
        return null;
    }
    return $dir;
}

/**
 * ログが入っているディレクトリの実パス（{log_root}/{folder}/{log_subdir}）。
 * 読めない場合や、ルートの外を指した場合は null。
 *
 * 文字種のチェックを通っていても、シンボリックリンクで外に出られる可能性があるので
 * realpath() で「ログのルート配下」に収まっていることを必ず確かめます。
 * 確認の基準はあくまで log_root であって、店舗フォルダやサブディレクトリではありません。
 */
function log_dir(string $folder): ?string
{
    if (!log_folder_valid($folder)) {
        return null;
    }
    $root = realpath(log_root());
    if ($root === false) {
        return null;
    }

    $path = $root . DIRECTORY_SEPARATOR . $folder;
    $sub  = log_subdir();
    if ($sub !== '') {
        $path .= DIRECTORY_SEPARATOR . $sub;
    }

    $dir = realpath($path);
    if ($dir === false || !is_dir($dir)) {
        return null;
    }
    // ルート自身、またはルート配下であること
    if ($dir !== $root && strpos($dir, $root . DIRECTORY_SEPARATOR) !== 0) {
        return null;
    }
    return $dir;
}

/** ログファイルの実パス。無ければ null。 */
function log_file_path(string $folder, string $kind, string $date): ?string
{
    $kinds = log_kinds();
    if (!isset($kinds[$kind]) || !log_date_valid($date)) {
        return null;
    }
    $dir = log_dir($folder);
    if ($dir === null) {
        return null;
    }
    $path = $dir . DIRECTORY_SEPARATOR . sprintf($kinds[$kind]['pattern'], $date);
    return (is_file($path) && is_readable($path)) ? $path : null;
}

/**
 * 指定時刻が属する営業日（YYYYMMDD）。
 * 5:55 より前は前日の営業日として扱います。
 */
function log_business_date(?int $ts = null): string
{
    $now = new DateTimeImmutable('@' . ($ts ?? time()));
    $now = $now->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $h = (int)$now->format('G');
    $m = (int)$now->format('i');
    if ($h < LOG_DAY_START_HOUR || ($h === LOG_DAY_START_HOUR && $m < LOG_DAY_START_MIN)) {
        $now = $now->modify('-1 day');
    }
    return $now->format('Ymd');
}

/** YYYYMMDD を n 日ずらす。不正な日付はそのまま返します。 */
function log_date_shift(string $date, int $days): string
{
    if (!log_date_valid($date)) {
        return $date;
    }
    $d = DateTimeImmutable::createFromFormat('Ymd', $date);
    return $d === false ? $date : $d->modify(sprintf('%+d day', $days))->format('Ymd');
}

/** YYYYMMDD を画面用に整形（2026/09/10） */
function log_date_label(string $date): string
{
    return log_date_valid($date)
        ? substr($date, 0, 4) . '/' . substr($date, 4, 2) . '/' . substr($date, 6, 2)
        : $date;
}

/**
 * そのフォルダに存在する営業日の一覧（新しい順）。
 * ファイル名から日付を拾うだけなので、中身は読みません。
 */
function log_available_dates(string $folder): array
{
    $dir = log_dir($folder);
    if ($dir === null) {
        return [];
    }
    $names = @scandir($dir);
    if ($names === false) {
        return [];
    }
    $dates = [];
    foreach ($names as $name) {
        if (preg_match('/\A(?:status_|report_|mitene_report_|okini_report_|loginerr)(\d{8})\.txt\z/', $name, $m)
            && log_date_valid($m[1])) {
            $dates[$m[1]] = true;
        }
    }
    // 日付は数字だけなので、配列のキーにすると int になってしまう。
    // 画面側は文字列で厳密比較するため、文字列に戻してから返す。
    $dates = array_map('strval', array_keys($dates));
    rsort($dates);
    return $dates;
}

/**
 * 1行を項目の配列にする。
 * 行全体を囲っている " を外してからカンマで分割します（RFC のCSVではありません）。
 */
function log_split_line(string $line): array
{
    $line = rtrim($line, "\r\n");
    if (strlen($line) >= 2 && $line[0] === '"' && substr($line, -1) === '"') {
        $line = substr($line, 1, -1);
    }
    return explode(',', $line);
}

/**
 * ファイルを1行ずつ流しながら項目配列を返します。
 * 全部をメモリに載せないので、1,300行を超えるミテネログでも重くなりません。
 *
 * @return Generator<int, array<int, string>>
 */
function log_rows(string $path): Generator
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return;
    }
    try {
        $first = true;
        while (($line = fgets($fh)) !== false) {
            if ($first) {
                $first = false;
                // UTF-8 BOM を落とす
                if (strncmp($line, "\xEF\xBB\xBF", 3) === 0) {
                    $line = substr($line, 3);
                }
            }
            if (trim($line) === '') {
                continue;
            }
            yield log_split_line($line);
        }
    } finally {
        fclose($fh);
    }
}

/** status の1行を意味のあるキーに割り当てる（日時 / 状態 / 詳細） */
function log_row_status(array $f): array
{
    return [
        'at'     => trim($f[0] ?? ''),
        'state'  => strtoupper(trim($f[1] ?? '')),
        'detail' => trim($f[2] ?? ''),
    ];
}

/** report の1行（login_ok, ID, キャスト名, 残数, タップ数, 走査数, オキニ送信数, 日時） */
function log_row_report(array $f): array
{
    return [
        'login'  => trim($f[0] ?? ''),
        'id'     => trim($f[1] ?? ''),
        'name'   => trim($f[2] ?? ''),
        'remain' => (int)trim($f[3] ?? '0'),
        'taps'   => (int)trim($f[4] ?? '0'),
        'scans'  => (int)trim($f[5] ?? '0'),
        'okini'  => (int)trim($f[6] ?? '0'),
        'at'     => trim($f[7] ?? ''),
    ];
}

/**
 * ミテネの1行（キャスト名, 会員名, ミテネ履歴日, 結果）。
 * 会員名にカンマが入ると5項目以上になるので、前1つと後ろ2つを固定し、
 * 残りを会員名として連結し直します。単純な explode では壊れます。
 */
function log_row_mitene(array $f): array
{
    $n = count($f);
    if ($n < 4) {
        return ['name' => trim($f[0] ?? ''), 'member' => trim($f[1] ?? ''), 'day' => '', 'result' => trim($f[2] ?? '')];
    }
    return [
        'name'   => trim($f[0]),
        // 会員名の中の「, 」は本来の表記なので、連結した内側は触らない
        'member' => trim(implode(',', array_slice($f, 1, $n - 3))),
        'day'    => trim($f[$n - 2]),
        'result' => trim($f[$n - 1]),
    ];
}

/**
 * オキニの1行（キャスト名, 会員名, 結果）。
 * こちらの会員名にもカンマが入りうるので、ミテネと同じ考え方で真ん中を連結します。
 */
function log_row_okini(array $f): array
{
    $n = count($f);
    if ($n < 3) {
        return ['name' => trim($f[0] ?? ''), 'member' => '', 'result' => trim($f[1] ?? '')];
    }
    return [
        'name'   => trim($f[0]),
        'member' => trim(implode(',', array_slice($f, 1, $n - 2))),
        'result' => trim($f[$n - 1]),
    ];
}

/**
 * ログインエラーの1行（エラー種別, ID, パスワード, キャスト名, 日時）。
 * パスワードにカンマが入っても壊れないよう、真ん中を連結します。
 */
function log_row_loginerr(array $f): array
{
    $n = count($f);
    if ($n < 5) {
        return ['kind' => trim($f[0] ?? ''), 'id' => trim($f[1] ?? ''), 'pw' => trim($f[2] ?? ''), 'name' => trim($f[3] ?? ''), 'at' => ''];
    }
    return [
        'kind' => trim($f[0]),
        'id'   => trim($f[1]),
        // パスワードは中身をそのまま見せたいので、連結した内側は触らない
        'pw'   => implode(',', array_slice($f, 2, $n - 4)),
        'name' => trim($f[$n - 2]),
        'at'   => trim($f[$n - 1]),
    ];
}

/** 種類ごとの行変換 */
function log_row(string $kind, array $f): array
{
    switch ($kind) {
        case 'status':   return log_row_status($f);
        case 'report':   return log_row_report($f);
        case 'mitene':   return log_row_mitene($f);
        case 'okini':    return log_row_okini($f);
        case 'loginerr': return log_row_loginerr($f);
    }
    return [];
}

/**
 * status を1回流して、一覧に要る分だけ拾います。
 * 全行を配列に貯めないので、行数が増えても頭打ちになりません。
 */
function log_scan_status(string $path): array
{
    $out = ['start' => 0, 'finish' => false, 'last_at' => '', 'lines' => 0];
    foreach (log_rows($path) as $f) {
        $r = log_row_status($f);
        $out['lines']++;
        if ($r['state'] === 'START') {
            // START の詳細は CSV のキャスト件数
            $out['start'] = (int)$r['detail'];
        } elseif ($r['state'] === 'FINISH') {
            $out['finish'] = true;
        }
        if ($r['at'] !== '') {
            $out['last_at'] = $r['at'];
        }
    }
    return $out;
}

/**
 * report を1回流して、行数と合計、異常キャストを拾います。
 *
 * 異常キャスト = 残数 > 0 なのに走査数が足りない行。
 * 残数0でタップ0・走査0は、本人が使い切っているだけなので異常には数えません。
 */
function log_scan_report(string $path, ?int $scanMin = null): array
{
    $scanMin = $scanMin ?? log_scan_min();
    $out = ['lines' => 0, 'taps' => 0, 'okini' => 0, 'bad' => []];
    foreach (log_rows($path) as $f) {
        $r = log_row_report($f);
        $out['lines']++;
        $out['taps']  += $r['taps'];
        $out['okini'] += $r['okini'];
        if ($r['remain'] > 0 && $r['scans'] < $scanMin) {
            $out['bad'][] = $r;
        }
    }
    return $out;
}

/** 行数を数えるだけ（loginerr など） */
function log_count_rows(string $path): int
{
    $n = 0;
    foreach (log_rows($path) as $_) {
        $n++;
    }
    return $n;
}

/**
 * 1店舗・1営業日のまとめ。一覧・詳細のどちらもこれを使います。
 *
 * verdict は
 *   nofolder … フォルダ未設定、または名前が不正で読めない
 *   nofile   … その日のファイルが無い
 *   timeout  … FINISH が無い（件数に対して処理時間が足りず完走できていない）
 *   done     … FINISH あり
 */
function log_day_summary(string $folder, string $date): array
{
    $out = [
        'verdict'   => 'nofolder',
        'start'     => 0,
        'report'    => 0,
        'loginerr'  => 0,
        'taps'      => 0,
        'okini'     => 0,
        'bad'       => [],
        'bad_count' => 0,
        'last_at'   => '',
        'mismatch'  => false,
        'files'     => [],
    ];

    // フォルダ名そのものが未設定、または受け付けられない文字を含む
    if ($folder === '' || !log_folder_valid($folder)) {
        return $out;
    }
    // 名前は妥当だが、ログのディレクトリがまだ無い（log サブディレクトリ未作成など）。
    // 設定漏れではないので「ファイルなし」として扱う。
    if (log_dir($folder) === null) {
        $out['verdict'] = 'nofile';
        return $out;
    }

    foreach (array_keys(log_kinds()) as $kind) {
        $out['files'][$kind] = log_file_path($folder, $kind, $date);
    }

    $statusPath = $out['files']['status'] ?? null;
    if ($statusPath === null) {
        // status が無ければ、その日は動いていないものとして扱う
        $out['verdict'] = 'nofile';
        return $out;
    }

    $st = log_scan_status($statusPath);
    $out['verdict'] = $st['finish'] ? 'done' : 'timeout';
    $out['start']   = $st['start'];
    $out['last_at'] = $st['last_at'];

    if (!empty($out['files']['report'])) {
        $rep = log_scan_report($out['files']['report']);
        $out['report'] = $rep['lines'];
        $out['taps']   = $rep['taps'];
        $out['okini']  = $rep['okini'];
        $out['bad']    = $rep['bad'];
    }
    $out['bad_count'] = count($out['bad']);

    if (!empty($out['files']['loginerr'])) {
        $out['loginerr'] = log_count_rows($out['files']['loginerr']);
    }

    // START の件数と、処理できた件数（report + loginerr）が合わなければ取りこぼし
    $out['mismatch'] = ($out['start'] !== $out['report'] + $out['loginerr']);

    return $out;
}

/** システム異常があるか（ログイン失敗は別枠なので含めません） */
function log_has_system_issue(array $s): bool
{
    return $s['verdict'] === 'timeout' || $s['bad_count'] > 0 || $s['mismatch'];
}

/** 判定の表示名とバッジの色 */
function log_verdict_badge(string $verdict): string
{
    $map = [
        'done'     => ['完了', 'badge-active'],
        'timeout'  => ['時間切れ', 'badge-danger'],
        'nofile'   => ['ファイルなし', 'badge-inactive'],
        'nofolder' => ['フォルダ未設定', 'badge-inactive'],
    ];
    [$label, $cls] = $map[$verdict] ?? ['不明', 'badge-inactive'];
    return '<span class="badge ' . $cls . '">' . h($label) . '</span>';
}

/**
 * 一覧用に、システム異常の中身を短いバッジで表します。
 * 判定そのものは log_day_summary() と log_has_system_issue() に任せているので、
 * 画面ごとに条件を書き直すことはありません。
 */
function log_issue_badges(array $sum): string
{
    if ($sum['verdict'] === 'nofolder') {
        return '<span class="badge badge-inactive">未設定</span>';
    }
    if ($sum['verdict'] === 'nofile') {
        return '<span class="badge badge-inactive">ファイルなし</span>';
    }
    if (!log_has_system_issue($sum)) {
        return '<span class="badge badge-active">正常</span>';
    }

    $out = [];
    if ($sum['verdict'] === 'timeout') {
        $out[] = '<span class="badge badge-danger" title="FINISH が記録されていません。完走できていません。">時間切れ</span>';
    }
    if ($sum['bad_count'] > 0) {
        $out[] = '<span class="badge badge-danger" title="残数があるのに走査数が足りないキャスト。リストが読めていません。">'
               . '異常キャスト ' . (int)$sum['bad_count'] . '</span>';
    }
    if ($sum['mismatch']) {
        $out[] = '<span class="badge badge-danger" title="START件数と report行数 + ログイン失敗行数が合いません。">取りこぼし</span>';
    }
    return implode(' ', $out);
}

/** ログイン失敗の件数。店舗側のアカウント問題なので、システム異常とは色を分けます。 */
function log_login_badge(array $sum): string
{
    if ($sum['verdict'] === 'nofolder' || $sum['verdict'] === 'nofile') {
        return '<span class="soft">—</span>';
    }
    if ($sum['loginerr'] === 0) {
        return '<span class="soft">0</span>';
    }
    return '<span class="badge badge-warn">' . (int)$sum['loginerr'] . ' 件</span>';
}

/** システム異常とログイン失敗のどちらかがあるか（一覧の「異常のみ表示」用） */
function log_has_any_issue(array $sum): bool
{
    return log_has_system_issue($sum) || $sum['loginerr'] > 0;
}

/** status の状態ごとのバッジ */
function log_state_badge(string $state): string
{
    $map = [
        'START'   => 'badge-info',
        'WAIT'    => 'badge-inactive',
        'BUSY'    => 'badge-warn',
        'ENDCAST' => 'badge-active',
        'FINISH'  => 'badge-active',
    ];
    $cls = $map[$state] ?? 'badge-inactive';
    return '<span class="badge ' . $cls . '">' . h($state !== '' ? $state : '—') . '</span>';
}
