<?php
// Included only after integration.php has verified an empty, disposable DB.
if (!isset($appRoot, $pdo, $dayId, $productId) || getenv('POS_TEST_MODE') !== '1') throw new RuntimeException('Run through integration.php.');

function audit_start(string $route, array $input = []): array {
    $command = [PHP_BINARY, __DIR__ . '/audit-worker.php', $GLOBALS['appRoot'], $route, base64_encode(json_encode($input, JSON_THROW_ON_ERROR))];
    $process = proc_open($command, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start audit worker.');
    fclose($pipes[0]);
    return [$process, $pipes, $route];
}
function audit_finish(array $worker, bool $expectDatabaseError = false): array {
    [$process, $pipes, $route] = $worker;
    $out = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    test_equal(proc_close($process), 0, $route . ': process succeeded');
    $logs = [];
    foreach (array_filter(explode("\n", trim($error))) as $line) {
        test_assert(strpos($line, 'GARBALIA {') === 0, $route . ': no PHP warnings: ' . $line);
        $entry = json_decode(substr($line, 9), true, 512, JSON_THROW_ON_ERROR);
        if (!$expectDatabaseError) test_equal($entry['errors'], [], $route . ': no SQL errors');
        $logs[] = $entry;
    }
    $result = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    test_assert(strpos($result['body'], 'Warning:') === false && strpos($result['body'], 'Fatal error:') === false, $route . ': clean output');
    $result['logs'] = $logs;
    return $result;
}
function audit_request(string $route, array $input = []): array { return audit_finish(audit_start($route, $input)); }
function audit_payload(array $result): array { return json_decode($result['body'], true, 512, JSON_THROW_ON_ERROR); }
function audit_table(PDO $pdo): int {
    $pdo->prepare('INSERT INTO restaurant_tables (name) VALUES (?)')->execute(['Audit ' . bin2hex(random_bytes(6))]);
    return (int)$pdo->lastInsertId();
}
function audit_wait_for_table_lock(PDO $pdo): void {
    $deadline = microtime(true) + 2;
    do {
        foreach ($pdo->query('SHOW PROCESSLIST')->fetchAll() as $process) {
            if (strpos((string)($process['Info'] ?? ''), 'SELECT * FROM restaurant_tables WHERE') === 0) return;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Worker did not reach the intended table lock.');
}

// Eight terminals adding to an empty table must create one order, not eight.
$tableId = audit_table($pdo);
$workers = [];
for ($i = 0; $i < 8; $i++) $workers[] = audit_start('add', ['table_id' => $tableId, 'product_id' => $productId, 'quantity' => 1]);
$orderIds = [];
foreach ($workers as $worker) {
    $result = audit_payload(audit_finish($worker));
    test_equal($result['ok'], true, 'concurrent add succeeds');
    $orderIds[] = $result['order']['id'];
}
test_equal(count(array_unique($orderIds)), 1, 'concurrent empty-table adds share one order');
$orderId = $orderIds[0];
test_equal((int)$pdo->query('SELECT COUNT(*) FROM order_items WHERE order_id=' . $orderId)->fetchColumn(), 8, 'all eight intentional additions persist');

// Two concurrent kitchen sends must claim the same batch only once.
$first = audit_start('send', ['table_id' => $tableId]);
$second = audit_start('send', ['table_id' => $tableId]);
$sent = [audit_payload(audit_finish($first)), audit_payload(audit_finish($second))];
test_equal((int)$sent[0]['ok'] + (int)$sent[1]['ok'], 1, 'only one send claims the batch');

// Hold the table row to make the add/close overlap deterministic. Either close
// wins and the add opens a new order, or add wins and close rejects unsent lines.
$pdo->beginTransaction();
$pdo->query('SELECT id FROM restaurant_tables WHERE id=' . $tableId . ' FOR UPDATE')->fetch();
$close = audit_start('close', ['table_id' => $tableId, 'service_charge_version' => '1', 'expected_order_id' => $orderId, 'expected_subtotal' => 13.6, 'payment_type' => 'cash']);
audit_wait_for_table_lock($pdo);
$add = audit_start('add', ['table_id' => $tableId, 'product_id' => $productId]);
$pdo->commit();
$closedResult = audit_payload(audit_finish($close));
$addedResult = audit_payload(audit_finish($add));
test_equal($addedResult['ok'], true, 'racing add succeeds');
$stored = stored_order($pdo, $orderId);
if ($stored['status'] === 'closed') {
    test_equal($closedResult['ok'], true, 'close winner confirmed');
    test_money($stored['subtotal_total'], 13.6, 'closed subtotal matches sent items');
    test_assert($addedResult['order']['id'] !== $orderId, 'later add cannot change a closed order');
} else {
    test_equal($closedResult['ok'], false, 'new unsent line prevents close');
}

// Deliberate lock contention times out quickly and releases the PHP session,
// so a read in the same session remains responsive while the write waits.
$blockedTable = audit_table($pdo);
$pdo->beginTransaction();
$pdo->query('SELECT id FROM restaurant_tables WHERE id=' . $blockedTable . ' FOR UPDATE')->fetch();
$blocked = audit_start('add', ['table_id' => $blockedTable, 'product_id' => $productId, '_session' => 'audit-shared-session']);
audit_wait_for_table_lock($pdo);
$read = audit_request('tables', ['_session' => 'audit-shared-session']);
test_equal($read['status'], 200, 'same-session read succeeds during blocked write');
test_assert($read['ms'] < 2000, 'same-session read does not wait for DB lock');
$blockedResult = audit_finish($blocked, true);
$pdo->rollBack();
test_equal($blockedResult['status'], 503, 'lock timeout has an actual failure status');
test_assert($blockedResult['ms'] >= 2500 && $blockedResult['ms'] < 6000, 'row lock timeout is bounded near 3 seconds');
test_assert(!empty($blockedResult['logs'][0]['errors']), 'lock error is recorded');
test_assert(strpos($blockedResult['body'], 'SQLSTATE') === false, 'raw MySQL error is not exposed');
test_equal((int)$pdo->query('SELECT COUNT(*) FROM orders WHERE table_id=' . $blockedTable)->fetchColumn(), 0, 'timed-out add leaves no partial order');

// One reporting fixture: 600 closed orders with discounts/service, >500-row
// history, saved costs, and a legacy NULL closed_at row inside the same day.
$reportTable = audit_table($pdo);
$pdo->beginTransaction();
$insertOrder = $pdo->prepare("INSERT INTO orders (business_day_id,table_id,user_id,status,subtotal_total,discount_amount,total,cash_amount,closed_at,created_at) VALUES (?,?,1,'closed',20,2,19.8,19.8,?,'2026-06-05 12:00:00')");
$insertItem = $pdo->prepare("INSERT INTO order_items (order_id,product_id,product_name,quantity,price,product_cost,sent_at) VALUES (?,?,'Audit product',2,10,3,'2026-06-05 12:00:00')");
for ($i = 0; $i < 600; $i++) {
    $insertOrder->execute([$dayId, $reportTable, $i === 0 ? null : '2026-06-05 12:00:00']);
    $id = (int)$pdo->lastInsertId();
    $insertItem->execute([$id, $productId]);
}
$pdo->commit();
$report = audit_request('stats-data', ['start' => '2026-06-05 04:00:00', 'end' => '2026-06-06 03:59:59']);
$stats = audit_payload($report);
test_equal($stats['ordersCount'], 600, 'report includes every order and legacy NULL closing dates');
test_money($stats['revenue'], 11880, 'full report revenue');
test_money($stats['serviceTotal'], 1080, 'service separated from product revenue');
test_money($stats['costTotal'], 3600, 'saved cost preserved');
test_money($stats['discounts'], 1200, 'all discounts preserved');
test_money($stats['topProducts'][0]['net_sales'], 10800, 'product revenue excludes service');
test_money($stats['topProducts'][0]['qty'], 1200, 'quantities aggregate across every order');

$history = audit_request('history-admin', ['from' => '2026-06-05', 'to' => '2026-06-05', 'table_id' => $reportTable]);
test_equal($history['status'], 200, 'admin history loads with valid DB configuration');
test_assert(strpos($history['body'], '11,880.00') !== false, 'history total includes rows beyond the first page');
test_assert(strpos($history['body'], 'გვერდი 1 / 6') !== false, 'history is paginated');
$csv = audit_request('history-admin', ['from' => '2026-06-05', 'to' => '2026-06-05', 'table_id' => $reportTable, 'export' => 'excel']);
test_equal(count(explode("\n", trim($csv['body']))), 601, 'CSV includes all 600 orders plus header');
foreach (['history-cashier', 'statistics', 'day', 'tables', 'products', 'receipts'] as $route) {
    test_equal(audit_request($route, ['range' => 'year'])['status'], 200, $route . ': real page loads without runtime patching');
}
$badDb = audit_finish(audit_start('bad-db'), true);
test_equal($badDb['status'], 503, 'connection error returns 503 JSON');
test_equal(audit_payload($badDb)['ok'], false, 'connection error is structured');
test_assert(strpos($badDb['body'], 'pos_test_missing') === false, 'database identity is not exposed');
test_assert(!glob($appRoot . '/.runtime-*'), 'pages do not write runtime PHP files');

// A past "to" must not move the lower bound outside a cashier's seven days.
$oldTable = audit_table($pdo);
$pdo->prepare("INSERT INTO orders (business_day_id,table_id,user_id,status,total,closed_at) VALUES (?,?,1,'closed',123.45,'2000-01-01 12:00:00')")->execute([$dayId, $oldTable]);
$oldOrder = (int)$pdo->lastInsertId();
$oldHistory = audit_request('history-cashier', ['from' => '2000-01-01', 'to' => '2000-01-01', 'table_id' => $oldTable]);
test_equal($oldHistory['status'], 200, 'out-of-window cashier filters are clamped');
test_assert(strpos($oldHistory['body'], 'order_id=' . $oldOrder) === false, 'past end date cannot expose an old order');

// Editing/deleting is limited to unsent lines; full cancellation keeps history.
$editTable = audit_table($pdo);
$added = audit_payload(audit_request('add', ['table_id' => $editTable, 'product_id' => $productId]));
$edit = ['action' => 'edit', 'table_id' => $editTable, 'item_id' => $added['item']['id'], 'quantity' => 3];
test_equal(audit_payload(audit_request('workflow', $edit))['ok'], true, 'unsent quantity is editable');
test_money($pdo->query('SELECT quantity FROM order_items WHERE id=' . $added['item']['id'])->fetchColumn(), 3, 'quantity persisted');
$edit['quantity'] = null;
test_equal(audit_payload(audit_request('workflow', $edit))['ok'], true, 'unsent line can be removed');
test_equal((int)$pdo->query('SELECT COUNT(*) FROM orders WHERE id=' . $added['order']['id'])->fetchColumn(), 0, 'empty order is removed');
$added = audit_payload(audit_request('add', ['table_id' => $editTable, 'product_id' => $productId]));
audit_request('send', ['table_id' => $editTable]);
$edit['item_id'] = $added['item']['id'];
test_equal(audit_request('workflow', $edit)['status'], 409, 'sent item cannot be deleted');
test_equal(audit_payload(audit_request('cancel', ['table_id' => $editTable, 'cancel_reason' => 'შეკვეთა დუბლირებულია']))['ok'], true, 'full cancellation succeeds');
$cancelled = stored_order($pdo, $added['order']['id']);
test_equal($cancelled['status'], 'cancelled', 'cancelled order stays in history');
test_money($cancelled['cancelled_total'], 1.7, 'cancellation keeps the former amount');
test_equal((int)$pdo->query('SELECT is_cancelled FROM order_items WHERE id=' . $added['item']['id'])->fetchColumn(), 1, 'cancelled lines stay in history');

// Begin a separate day fixture only inside this disposable database.
$pdo->exec("UPDATE business_days SET status='closed',closed_at=NOW() WHERE status='open'");
$openA = audit_start('workflow', ['action' => 'open-day', 'amount' => 100]);
$openB = audit_start('workflow', ['action' => 'open-day', 'amount' => 100]);
test_equal((int)audit_payload(audit_finish($openA))['ok'] + (int)audit_payload(audit_finish($openB))['ok'], 1, 'concurrent openings create one day');
$closingDay = (int)$pdo->query("SELECT id FROM business_days WHERE status='open'")->fetchColumn();
$dayTable = audit_table($pdo);
audit_request('add', ['table_id' => $dayTable, 'product_id' => $productId]);
test_equal(audit_request('workflow', ['action' => 'close-day', 'amount' => 100])['status'], 409, 'day cannot close over an open order');
audit_request('cancel', ['table_id' => $dayTable, 'cancel_reason' => 'სხვა', 'cancel_reason_custom' => 'Audit']);
test_equal(audit_payload(audit_request('workflow', ['action' => 'cash', 'amount' => 25]))['ok'], true, 'cash movement succeeds before close');
test_equal(audit_payload(audit_request('workflow', ['action' => 'close-day', 'amount' => 125]))['ok'], true, 'day close succeeds after orders finish');
$closedDay = $pdo->query('SELECT * FROM business_days WHERE id=' . $closingDay)->fetch();
test_money($closedDay['expected_cash'], 125, 'day close includes opening cash and committed movement');
test_money($closedDay['cash_difference'], 0, 'cash reconciliation remains exact');
test_equal(audit_request('workflow', ['action' => 'cash', 'amount' => 10])['status'], 409, 'cash cannot be appended to a closed day');
test_equal(audit_request('add', ['table_id' => $dayTable, 'product_id' => $productId])['status'], 409, 'items cannot be appended to a closed day');

// Index migration is idempotent, and endpoints do not perform schema changes.
$pdo->exec('ALTER TABLE orders DROP INDEX idx_orders_day_status_table');
$pdo->exec('ALTER TABLE cash_movements DROP INDEX idx_cash_type_created');
$pdo->exec('ALTER TABLE orders DROP INDEX idx_orders_day_table_status, DROP INDEX idx_orders_status_closed');
$pdo->exec('ALTER TABLE order_items DROP INDEX idx_items_order_active_sent');
for ($i = 0; $i < 2; $i++) {
    $pdo->exec(file_get_contents(__DIR__ . '/../database/migrations/2026-09-19-audit-indexes.sql'));
    $pdo->exec(file_get_contents(__DIR__ . '/../database/migrations/2026-09-20-existing-schema-indexes.sql'));
}
test_equal((int)$pdo->query("SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema=DATABASE() AND index_name IN ('idx_orders_day_status_table','idx_cash_type_created','idx_orders_day_table_status','idx_orders_status_closed','idx_items_order_active_sent')")->fetchColumn(), 5, 'all five indexes installed exactly once');
echo 'Report fixture: 600 orders, ' . round($report['ms'], 1) . ' ms, ' . $report['peak_mb'] . " MiB PHP peak.\n";
test_finish('Concurrency, report, timeout and page regressions');
