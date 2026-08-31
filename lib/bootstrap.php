<?php
declare(strict_types=1);

/**
 * 全ページ共通の読み込み。各ページの先頭で1行 require するだけで済むようにしています。
 */

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tokyo');

// 本番では画面にエラーを出さない。原因追跡はサーバーのエラーログで行う。
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

define('APP_ROOT', dirname(__DIR__));

$configPath = APP_ROOT . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    exit('config.php がありません。config.sample.php をコピーして作成してください。');
}
$GLOBALS['__config'] = require $configPath;

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
