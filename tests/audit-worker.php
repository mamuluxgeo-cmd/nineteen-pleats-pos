<?php
if (PHP_SAPI !== 'cli' || getenv('POS_TEST_MODE') !== '1') throw new RuntimeException('Isolated CLI test only.');
$appRoot = realpath($argv[1] ?? '');
$prefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/pos-service-test-';
if (!$appRoot || strpos($appRoot, $prefix) !== 0 || !is_file($appRoot . '/config.php')) throw new RuntimeException('Disposable app required.');
$route = $argv[2] ?? '';
$input = json_decode(base64_decode($argv[3] ?? ''), true, 512, JSON_THROW_ON_ERROR);
$started = microtime(true);
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('session.save_path', $appRoot . '/sessions');
if (!empty($input['_session'])) session_id($input['_session']);
session_start();
$_SESSION['user'] = ['id' => 1, 'name' => 'Test user', 'username' => 'test', 'role' => strpos($route, 'cashier') !== false ? 'cashier' : 'admin'];
$_POST = $input;
$_GET = $input;
$_SERVER['REQUEST_METHOD'] = $input['_method'] ?? (in_array($route, ['add', 'send', 'cancel', 'close'], true) ? 'POST' : 'GET');
$routes = ['add' => 'add-item-fast.php', 'send' => 'send_order_print.php', 'cancel' => 'cancel-table-order.php', 'close' => 'close_order_print.php',
    'day' => 'day.php', 'tables' => 'tables.php', 'table' => 'table.php', 'products' => 'products.php', 'receipts' => 'receipts.php',
    'history-admin' => 'history-route.php', 'history-cashier' => 'history-route.php', 'statistics' => 'statistics-route.php', 'print-final' => 'print_final.php'];
$_SERVER['REQUEST_URI'] = '/' . ($routes[$route] ?? $route);
if (in_array($route, ['add', 'send', 'cancel', 'close', 'stats-data', 'bad-db', 'workflow'], true)) $_SERVER['HTTP_ACCEPT'] = 'application/json';
ob_start();
register_shutdown_function(static function () use ($started): void {
    $body = ob_get_clean();
    echo json_encode(['body' => $body, 'status' => http_response_code() ?: 200, 'ms' => (microtime(true) - $started) * 1000,
        'peak_mb' => memory_get_peak_usage(true) / 1048576], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
});
if ($route === 'stats-data') {
    require $appRoot . '/includes/bootstrap.php';
    require $appRoot . '/includes/reporting.php';
    echo json_encode(pos_statistics($input['start'], $input['end']), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} elseif ($route === 'workflow') {
    require $appRoot . '/includes/bootstrap.php';
    require $appRoot . '/includes/order-workflow.php';
    try {
        switch ($input['action']) {
            case 'edit': pos_edit_item((int)$input['table_id'], (int)$input['item_id'], $input['quantity']); break;
            case 'open-day': pos_open_day((float)$input['amount'], 'Audit'); break;
            case 'close-day': pos_close_day((float)$input['amount'], 'Audit'); break;
            case 'cash': pos_cash_movement('add', (float)$input['amount'], 'Audit'); break;
            default: throw new RuntimeException('Invalid test workflow.');
        }
        echo json_encode(['ok' => true]);
    } catch (InvalidArgumentException $error) {
        http_response_code(409);
        echo json_encode(['ok' => false]);
    }
} elseif ($route === 'bad-db') {
    require $appRoot . '/includes/bootstrap.php';
    $GLOBALS['config']['db_name'] = 'pos_test_missing';
    db();
} elseif (isset($routes[$route])) {
    require $appRoot . '/' . $routes[$route];
} else {
    throw new RuntimeException('Unknown audit test route.');
}
