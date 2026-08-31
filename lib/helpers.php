<?php
declare(strict_types=1);

/** 設定値の取得。'db.host' のようにドットで階層指定できます。 */
function cfg(string $key, $default = null)
{
    $node = $GLOBALS['__config'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return $default;
        }
        $node = $node[$part];
    }
    return $node;
}

/** HTMLエスケープ。出力は必ずこれを通します。 */
function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 設定のラベル表を引く。未定義の値はそのまま数字で見せる。 */
function label_of(string $cfgKey, $value): string
{
    $map = cfg($cfgKey, []);
    $key = (int)$value;
    return $map[$key] ?? ('不明(' . $key . ')');
}

/** GETパラメータを引き継いだURLを組み立てる（ページング・並び替え用） */
function url_with(array $overrides): string
{
    $params = array_merge($_GET, $overrides);
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) {
            unset($params[$k]);
        }
    }
    return '?' . http_build_query($params);
}

/** 日時の相対表示。監視画面では「いつから止まっているか」が知りたいので。 */
function ago(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return '—';
    }
    $diff = time() - $ts;
    if ($diff < 60)    return (int)$diff . '秒前';
    if ($diff < 3600)  return (int)($diff / 60) . '分前';
    if ($diff < 86400) return (int)($diff / 3600) . '時間前';
    return (int)($diff / 86400) . '日前';
}

/** ページャの描画 */
function render_pager(int $page, int $total, int $perPage): void
{
    $last = max(1, (int)ceil($total / $perPage));
    if ($last <= 1) {
        return;
    }
    $from = max(1, $page - 3);
    $to   = min($last, $page + 3);

    echo '<nav class="pager"><ul class="pagination pagination-sm mb-0">';
    if ($page > 1) {
        echo '<li class="page-item"><a class="page-link" href="' . h(url_with(['page' => $page - 1])) . '">前へ</a></li>';
    }
    if ($from > 1) {
        echo '<li class="page-item"><a class="page-link" href="' . h(url_with(['page' => 1])) . '">1</a></li>';
        if ($from > 2) {
            echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
    }
    for ($i = $from; $i <= $to; $i++) {
        $active = $i === $page ? ' active' : '';
        echo '<li class="page-item' . $active . '"><a class="page-link" href="' . h(url_with(['page' => $i])) . '">' . $i . '</a></li>';
    }
    if ($to < $last) {
        if ($to < $last - 1) {
            echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
        echo '<li class="page-item"><a class="page-link" href="' . h(url_with(['page' => $last])) . '">' . $last . '</a></li>';
    }
    if ($page < $last) {
        echo '<li class="page-item"><a class="page-link" href="' . h(url_with(['page' => $page + 1])) . '">次へ</a></li>';
    }
    echo '</ul><span class="pager-count">' . number_format($total) . '件</span></nav>';
}

/** 認証情報のマスク表示。伏せ字にして、長さだけ分かるようにする。 */
function mask(?string $s): string
{
    $s = (string)$s;
    if ($s === '') {
        return '—';
    }
    return str_repeat('•', min(mb_strlen($s), 12));
}
