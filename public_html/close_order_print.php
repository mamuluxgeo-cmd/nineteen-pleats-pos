<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/service-charge.php';
require __DIR__ . '/includes/order-numbers.php';
require __DIR__ . '/includes/receipt-templates.php';

require_login();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function close_print_fail(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') close_print_fail('არასწორი მოთხოვნა.', 405);
if (($_POST['service_charge_version'] ?? '') !== '1') {
    close_print_fail('სისტემა განახლდა — განაახლე გვერდი და ხელახლა დაადასტურე ანგარიში.', 409);
}

$pdo = null;
$savedOrderId = 0;
try {
    $day = active_day();
    if (!$day) close_print_fail('სამუშაო დღე დახურულია.', 409);

    $tableId = (int)($_POST['table_id'] ?? 0);
    $table = fetch_table($tableId);
    if (!$table) close_print_fail('მაგიდა ვერ მოიძებნა.', 404);

    $order = current_open_order((int)$day['id'], $tableId);
    if (!$order) close_print_fail('ამ მაგიდაზე ღია შეკვეთა არ არის. გადაამოწმე ისტორია.', 409);
    $orderId = (int)$order['id'];
    if (isset($_POST['expected_order_id']) && (int)$_POST['expected_order_id'] !== $orderId) {
        close_print_fail('მაგიდის შეკვეთა შეიცვალა. განაახლე გვერდი და გადაამოწმე.', 409);
    }
    if (unsent_items_count($orderId) > 0) {
        close_print_fail('ამ მაგიდაზე არის გაუგზავნელი პროდუქცია — ჯერ გაგზავნე შეკვეთა.', 409);
    }
    $subtotal = order_total($orderId);
    if ($subtotal <= 0) close_print_fail('ამ მაგიდას ჯამი 0.00 ₾ აქვს — გამოიყენე „ნულით დახურვა“.', 409);
    if (isset($_POST['expected_subtotal']) && pos_money_tetri($_POST['expected_subtotal']) !== pos_money_tetri($subtotal)) {
        close_print_fail('შეკვეთის თანხა შეიცვალა. განაახლე გვერდი და თავიდან შეამოწმე გადასახდელი თანხა.', 409);
    }

    $price = pos_price_order($subtotal, pos_is_takeaway($table), $_POST);
    $pdo = db();
    $pdo->beginTransaction();
    // Save service inside total. Existing subtotal/discount columns preserve the
    // breakdown, without changing the database schema or adding a query.
    $stmt = $pdo->prepare("UPDATE orders SET status='closed', subtotal_total=?, total=?, discount_type=?, discount_value=?, discount_amount=?, payment_type=?, cash_amount=?, card_amount=?, closed_at=NOW() WHERE id=? AND status='open'");
    $stmt->execute([$price['subtotal_total'], $price['total'], $price['discount_type'], $price['discount_value'], $price['discount_amount'], $price['payment_type'], $price['cash_amount'], $price['card_amount'], $orderId]);
    if ($stmt->rowCount() !== 1) {
        $pdo->rollBack();
        close_print_fail('მაგიდა უკვე დაიხურა. გადაამოწმე ისტორია.', 409);
    }
    $pdo->commit();
    $savedOrderId = $orderId;

    $closedOrder = fetch_order($orderId);
    if (!$closedOrder) throw new RuntimeException('Saved order not found.');
    $items = order_items($orderId);
    $receiptNumber = receipt_number_for_order($closedOrder);
    $template = receipt_template('final');
    $result = [
        'ok' => true,
        'message' => 'მაგიდა დაიხურა და საბოლოო ქვითარი მზადაა დასაბეჭდად.',
        'order_id' => $orderId,
        'receipt_number' => $receiptNumber,
        'redirect' => '/tables',
        'final' => [
            'text' => build_configurable_final_receipt($table, $closedOrder, $items, $receiptNumber),
            'font_size' => (int)$template['font_size'],
        ],
    ];
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    close_print_fail($e->getMessage(), 422);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('GARBALIA close order: ' . $e->getMessage());
    if ($savedOrderId > 0) {
        close_print_fail('მაგიდა დაიხურა, მაგრამ ქვითრის მომზადება ვერ მოხერხდა. ქვითარი გახსენი ისტორიიდან; მაგიდის ხელახლა დახურვა საჭირო არ არის.', 500);
    }
    close_print_fail('მაგიდის დახურვა ვერ დადასტურდა. ხელახალ დაჭერამდე გადაამოწმე მაგიდა ან ისტორია.', 500);
}
