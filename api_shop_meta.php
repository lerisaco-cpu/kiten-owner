<?php
/**
 * 店舗一覧のインライン編集から呼ばれる保存API。
 * fetch() から使うので、結果は必ず JSON で返します。
 *
 * POST: _csrf, user_id, field(folder_name|ip_address), value
 */
require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** JSONを返して終了する */
function api_out(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// 未ログインだと require_login() は login.php へ 302 で飛ばします。fetch から呼ぶと
// ログイン画面のHTMLが 200 で返ってしまい、画面側が理由を出せないので先に JSON で返す。
if (current_admin() === null) {
    api_out(401, ['ok' => false, 'error' => 'ログインの有効期限が切れました。画面を再読み込みしてログインし直してください。']);
}
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_out(405, ['ok' => false, 'error' => 'POSTでのみ受け付けます。']);
}

csrf_check();   // 合わなければ 400 とメッセージを返して終了する
require_edit(); // 権限が無ければ 403 とメッセージを返して終了する

// 書き込みを許すのは shop_meta のこの2列だけ。列名はここで確定させ、SQLには埋め込まない。
$limits = ['folder_name' => 191, 'ip_address' => 45];

$id    = (int)($_POST['user_id'] ?? 0);
$field = (string)($_POST['field'] ?? '');
$value = (string)($_POST['value'] ?? '');

if (!isset($limits[$field])) {
    api_out(400, ['ok' => false, 'error' => 'この項目は編集できません。']);
}
if ($id <= 0) {
    api_out(400, ['ok' => false, 'error' => '店舗が指定されていません。']);
}

// 形式チェックはしない。ホスト名や複数IPをメモとして入れられるようにし、桁数だけ守る。
$value = mb_substr(trim($value), 0, $limits[$field]);

try {
    // users は読むだけ。存在しないIDに shop_meta の行を作らないための確認。
    if (q1('SELECT user_id FROM users WHERE user_id = ?', [$id]) === null) {
        api_out(404, ['ok' => false, 'error' => 'この店舗は見つかりませんでした。画面を再読み込みしてください。']);
    }

    // 行が無い店舗は空文字から始める
    $row = q1('SELECT folder_name, ip_address FROM shop_meta WHERE user_id = ?', [$id])
        ?? ['folder_name' => '', 'ip_address' => ''];

    $old = (string)$row[$field];
    if ($old !== $value) {
        $row[$field] = $value;
        // まだ行が無い店舗でも1回の実行で済ませる
        ex(
            'INSERT INTO shop_meta (user_id, folder_name, ip_address, updated_at)
                  VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE folder_name = VALUES(folder_name),
                                     ip_address  = VALUES(ip_address),
                                     updated_at  = NOW()',
            [$id, (string)$row['folder_name'], (string)$row['ip_address']]
        );
        audit('update_shop', 'user', $id, [$field => ['from' => $old, 'to' => $value]]);
    }
} catch (Throwable $e) {
    // 本番は display_errors=0 なので、原因はエラーログに残して画面には一言だけ返す
    error_log('api_shop_meta failed: ' . $e->getMessage());
    api_out(500, ['ok' => false, 'error' => '保存できませんでした。時間をおいてやり直してください。']);
}

api_out(200, ['ok' => true, 'value' => $value, 'changed' => $old !== $value]);
