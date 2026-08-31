<?php
declare(strict_types=1);

/** セッション開始。Cookieは可能な範囲で固くしておく。 */
function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('OWNERSESSID');
    session_start();
}

/** ログイン中の管理者。未ログインなら null。 */
function current_admin(): ?array
{
    start_session();
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    static $admin = null;
    if ($admin === null) {
        $admin = q1('SELECT * FROM admin_users WHERE admin_id = ? AND is_active = 1', [$_SESSION['admin_id']]);
        if ($admin === null) {
            // 無効化されたアカウントのセッションは即座に切る
            session_destroy();
        }
    }
    return $admin;
}

/** 未ログインならログイン画面へ飛ばす */
function require_login(): array
{
    $admin = current_admin();
    if ($admin === null) {
        header('Location: login.php');
        exit;
    }
    return $admin;
}

/** 編集権限があるか。role=3（閲覧のみ）と allow_edit=false は書き込み不可。 */
function can_edit(): bool
{
    if (!cfg('allow_edit', false)) {
        return false;
    }
    $admin = current_admin();
    return $admin !== null && (int)$admin['role'] <= 2;
}

/** 編集権限が無ければ処理を止める */
function require_edit(): void
{
    if (!can_edit()) {
        http_response_code(403);
        exit('この操作を行う権限がありません。');
    }
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

/** POSTのトークンを検証。合わなければ処理しない。 */
function csrf_check(): void
{
    start_session();
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || $sent === '' || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(400);
        exit('フォームの有効期限が切れています。前の画面に戻ってやり直してください。');
    }
}

/** 操作履歴を残す。誰が何を変えたかを後から追えるようにする。 */
function audit(string $action, string $targetType = '', ?int $targetId = null, $detail = null): void
{
    $admin = current_admin();
    if ($admin === null) {
        return;
    }
    if (!table_exists('admin_audit_log')) {
        return;
    }
    if ($detail !== null && !is_string($detail)) {
        $detail = json_encode($detail, JSON_UNESCAPED_UNICODE);
    }
    ex(
        'INSERT INTO admin_audit_log (admin_id, action, target_type, target_id, detail, ip)
         VALUES (?, ?, ?, ?, ?, ?)',
        [
            $admin['admin_id'],
            $action,
            $targetType,
            $targetId,
            $detail,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ]
    );
}
