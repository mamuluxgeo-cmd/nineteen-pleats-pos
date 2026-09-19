<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/service-charge.php';
require __DIR__ . '/includes/order-workflow.php';
require_once __DIR__ . '/includes/order-numbers.php';
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
    $closed = pos_close_order((int)($_POST['table_id'] ?? 0), $_POST);
    $closedOrder = $closed['order'];
    $table = $closed['table'];
    $items = $closed['items'];
    $orderId = (int)$closedOrder['id'];
    $savedOrderId = $orderId;
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
    pos_record_error($e);
    if ($savedOrderId > 0) {
        close_print_fail('მაგიდა დაიხურა, მაგრამ ქვითრის მომზადება ვერ მოხერხდა. ქვითარი გახსენი ისტორიიდან; მაგიდის ხელახლა დახურვა საჭირო არ არის.', 500);
    }
    close_print_fail('მაგიდის დახურვა ვერ დადასტურდა. ხელახალ დაჭერამდე გადაამოწმე მაგიდა ან ისტორია.', 500);
}
