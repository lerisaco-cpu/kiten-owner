<?php
declare(strict_types=1);

/** PDO接続を1本だけ張って使い回す */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', cfg('db.host'), cfg('db.name'));
    try {
        $pdo = new PDO($dsn, (string)cfg('db.user'), (string)cfg('db.pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('DB connect failed: ' . $e->getMessage());
        http_response_code(500);
        exit('データベースに接続できません。config.php の接続情報を確認してください。');
    }
    return $pdo;
}

/** SELECT して全件配列で返す */
function q(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** SELECT して1行だけ返す */
function q1(string $sql, array $params = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** COUNT など単一値を返す */
function qv(string $sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchColumn();
}

/** INSERT / UPDATE。影響行数を返す */
function ex(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

/** テーブルの存在確認。ログ系テーブル未作成でも画面が落ちないようにするため。 */
function table_exists(string $name): bool
{
    static $cache = [];
    if (isset($cache[$name])) {
        return $cache[$name];
    }
    $found = qv(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
        [cfg('db.name'), $name]
    );
    return $cache[$name] = ((int)$found > 0);
}
