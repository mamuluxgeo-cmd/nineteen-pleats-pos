<?php
require __DIR__ . '/assertions.php';
require __DIR__ . '/../public_html/includes/service-charge.php';

// Refuse arbitrary hosts, databases, config files, or existing databases. The
// workflow creates this empty local service and destroys it after the job.
if (PHP_SAPI !== 'cli' || getenv('POS_TEST_MODE') !== '1') {
    throw new RuntimeException('Explicit POS_TEST_MODE=1 is required.');
}
$host = getenv('POS_TEST_DB_HOST');
$database = getenv('POS_TEST_DB_NAME');
$port = (int)getenv('POS_TEST_DB_PORT');
if ($host !== '127.0.0.1' || $database !== 'pos_test' || $port < 1 || $port > 65535) {
    throw new RuntimeException('Integration tests only accept 127.0.0.1 and the pos_test database.');
}
$testUser = getenv('POS_TEST_DB_USER');
$testPassword = getenv('POS_TEST_DB_PASSWORD');
if ($testUser !== 'pos_test' || $testPassword !== 'pos-test-only') {
    throw new RuntimeException('Use the documented disposable test service credentials.');
}
$pdo = new PDO('mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4', $testUser, $testPassword, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT => 5,
    PDO::ATTR_PERSISTENT => false,
]);
if ($pdo->query('SHOW TABLES')->fetch()) {
    throw new RuntimeException('Refusing to modify a nonempty database. Create a fresh test service.');
}
$pdo->exec(file_get_contents(__DIR__ . '/../database/schema.sql'));
$columnsBefore = $pdo->query('SHOW COLUMNS FROM orders')->fetchAll();
foreach ($columnsBefore as $column) {
    test_assert(!in_array($column['Field'], ['service_rate', 'service_amount'], true), 'test schema does not need new order columns');
}
$tableColumns = $pdo->query('SHOW COLUMNS FROM restaurant_tables')->fetchAll();
foreach ($tableColumns as $column) {
    test_assert($column['Field'] !== 'is_takeaway', 'test schema does not need a table type migration');
}

$appRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/pos-service-test-' . bin2hex(random_bytes(12));
if (!mkdir($appRoot, 0700) || !mkdir($appRoot . '/sessions', 0700)) throw new RuntimeException('Cannot create disposable test app.');
$sourceRoot = realpath(__DIR__ . '/../public_html');
$sourceFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS));
foreach ($sourceFiles as $file) {
    // Never read/copy config.php, even if a developer has one in their checkout.
    if (!$file->isFile() || $file->getExtension() !== 'php' || $file->getBasename() === 'config.php') continue;
    $relative = substr($file->getPathname(), strlen($sourceRoot) + 1);
    $target = $appRoot . '/' . $relative;
    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true)) throw new RuntimeException('Cannot create test directory.');
    if (!copy($file->getPathname(), $target)) throw new RuntimeException('Cannot copy test source.');
}
$testConfig = [
    'timezone' => 'Asia/Tbilisi', 'restaurant_name' => 'Test Restaurant',
    'db_host' => $host . ';port=' . $port, 'db_name' => $database,
    'db_user' => $testUser, 'db_pass' => $testPassword, 'db_charset' => 'utf8mb4',
];
if (file_put_contents($appRoot . '/config.php', "<?php\nreturn " . var_export($testConfig, true) . ";\n") === false) {
    throw new RuntimeException('Cannot write isolated test configuration.');
}

function invoke_close(string $appRoot, string $route, array $input): ?array {
    $command = [PHP_BINARY, __DIR__ . '/endpoint-worker.php', $appRoot, $route, base64_encode(json_encode($input, JSON_THROW_ON_ERROR))];
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start endpoint test.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    test_equal($exit, 0, $route . ' endpoint exits successfully');
    test_equal(trim($error), '', $route . ' endpoint has no PHP warnings/errors');
    if ($route === 'json') {
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        test_assert(is_array($result) && array_key_exists('ok', $result), 'JSON endpoint returns a structured result');
        return $result;
    }
    test_equal($output, '', 'legacy close redirects without corrupt output');
    return null;
}

$pdo->exec("INSERT INTO business_days (opened_by,status,opening_cash) VALUES (1,'open',0)");
$dayId = (int)$pdo->lastInsertId();
$productId = (int)$pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
$fixtureNumber = 0;
function order_fixture(PDO $pdo, int $dayId, int $productId, bool $takeaway, $subtotal, bool $sent = true): array {
    $number = ++$GLOBALS['fixtureNumber'];
    $name = $takeaway ? 'გატანა ' . (900 + $number) : 'მაგიდა Test ' . $number;
    $pdo->prepare('INSERT INTO restaurant_tables (name,sort_order) VALUES (?,?)')->execute([$name, 2000 + $number]);
    $tableId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO orders (receipt_number,business_day_id,table_id,user_id,status) VALUES (?,?,?,1,'open')")->execute([10000 + $number, $dayId, $tableId]);
    $orderId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO order_items (order_id,product_id,product_name,quantity,price,sent_at,is_cancelled) VALUES (?,?,?,1,?,?,0)')->execute([$orderId, $productId, 'Test product', $subtotal, $sent ? '2026-09-19 12:00:00' : null]);
    $pdo->prepare('INSERT INTO order_items (order_id,product_id,product_name,quantity,price,sent_at,is_cancelled) VALUES (?,?,?,1,25,NOW(),1)')->execute([$orderId, $productId, 'Cancelled product']);
    return ['table_id' => $tableId, 'order_id' => $orderId];
}
function stored_order(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id=?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

$cases = json_decode(file_get_contents(__DIR__ . '/pricing-cases.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (['json', 'legacy'] as $route) {
    foreach ($cases as $index => $case) {
        // Zero-subtotal tables use the existing dedicated cancellation path.
        if ($case['subtotal'] <= 0) continue;
        $fixture = order_fixture($pdo, $dayId, $productId, $case['takeaway'], $case['subtotal']);
        $payment = ['cash', 'card', 'mixed'][$index % 3];
        $cash = round($case['total'] / 2, 2);
        $input = [
            'action' => 'close_order', 'table_id' => $fixture['table_id'],
            'service_charge_version' => '1', 'expected_order_id' => $fixture['order_id'],
            'expected_subtotal' => $case['subtotal'],
            'discount_enabled' => $case['discount_type'] === 'none' ? '0' : '1',
            'discount_type' => $case['discount_type'], 'discount_value' => $case['discount_value'],
            'payment_type' => $payment, 'cash_amount' => $cash, 'card_amount' => round($case['total'] - $cash, 2),
        ];
        $result = invoke_close($appRoot, $route, $input);
        $saved = stored_order($pdo, $fixture['order_id']);
        $label = $route . '/' . $case['name'];
        test_equal($saved['status'], 'closed', $label . ': closed');
        test_money($saved['subtotal_total'], $case['subtotal'], $label . ': saved subtotal');
        test_money($saved['discount_amount'], $case['discount'], $label . ': saved discount');
        test_money($saved['total'], $case['total'], $label . ': saved total');
        test_money($saved['cash_amount'] + $saved['card_amount'], $case['total'], $label . ': payment balances');
        test_money(pos_service_amount($saved), $case['service'], $label . ': reconstruct service');
        $feeSql = $pdo->prepare('SELECT ' . pos_service_amount_sql('o') . ' FROM orders o WHERE id=?');
        $feeSql->execute([$fixture['order_id']]);
        test_money($feeSql->fetchColumn(), $case['service'], $label . ': report SQL equals receipt helper');
        if ($result !== null) {
            test_equal($result['ok'], true, $label . ': JSON success');
            test_assert(strpos($result['final']['text'], 'საბოლოო ჯამი: ' . number_format($case['total'], 2) . ' GEL') !== false, $label . ': actual final receipt');
            test_equal(strpos($result['final']['text'], 'მომსახურება') !== false, $case['service'] > 0, $label . ': correct receipt fee visibility');
        }
        // Sequential resubmission must not charge the same order a second time.
        invoke_close($appRoot, $route, $input);
        test_equal(stored_order($pdo, $fixture['order_id']), $saved, $label . ': repeat close leaves saved order unchanged');
    }

    foreach (['old-client', 'wrong-total', 'wrong-order', 'mixed-without-fee', 'unsent-items'] as $blocker) {
        $fixture = order_fixture($pdo, $dayId, $productId, false, 100, $blocker !== 'unsent-items');
        $input = ['action' => 'close_order', 'table_id' => $fixture['table_id'], 'service_charge_version' => '1', 'expected_order_id' => $fixture['order_id'], 'expected_subtotal' => 100, 'payment_type' => 'cash'];
        if ($blocker === 'old-client') unset($input['service_charge_version']);
        if ($blocker === 'wrong-total') $input['expected_subtotal'] = 99;
        if ($blocker === 'wrong-order') $input['expected_order_id'] = $fixture['order_id'] + 1000;
        if ($blocker === 'mixed-without-fee') $input = array_merge($input, ['payment_type' => 'mixed', 'cash_amount' => 50, 'card_amount' => 50]);
        $before = stored_order($pdo, $fixture['order_id']);
        $result = invoke_close($appRoot, $route, $input);
        test_equal(stored_order($pdo, $fixture['order_id']), $before, $route . '/' . $blocker . ': rejected without mutation');
        if ($result !== null) test_equal($result['ok'], false, $route . '/' . $blocker . ': structured rejection');
    }
}
test_equal($pdo->query('SHOW COLUMNS FROM orders')->fetchAll(), $columnsBefore, 'endpoints did not alter the orders schema');
test_equal($pdo->query('SHOW COLUMNS FROM restaurant_tables')->fetchAll(), $tableColumns, 'endpoints did not alter the table schema');
test_finish('Original-schema close endpoint integration');

require __DIR__ . '/performance.php';

if (getenv('POS_TEST_BENCHMARK') === '1') require __DIR__ . '/benchmark.php';
