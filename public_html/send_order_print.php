<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/order-workflow.php';
require_once __DIR__ . '/includes/order-numbers.php';
require __DIR__ . '/includes/receipt-templates.php';

require_login();
session_write_close();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function json_fail(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['ok'=>false,'message'=>$message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_fail('არასწორი მოთხოვნა.', 405);

$tableId = (int)($_POST['table_id'] ?? 0);
try {
    $sent = pos_send_order($tableId);
    $order = $sent['order'];
    $table = $sent['table'];
    $items = $sent['items'];
} catch (InvalidArgumentException $error) {
    json_fail($error->getMessage(), 409);
} catch (Throwable $error) {
    pos_render_error($error);
    exit;
}

$receiptNumber = receipt_number_for_order($order);
$barTemplate = receipt_template('bar');
$kitchenTemplate = receipt_template('kitchen');

$result = [
    'ok' => true,
    'message' => 'შეკვეთა გაიგზავნა და მზადაა დასაბეჭდად.',
    'table_id' => $tableId,
    'order_id' => (int)$order['id'],
    'receipt_number' => $receiptNumber,
    'bar' => [
        'text' => build_configurable_bar_receipt($table, $items, $receiptNumber),
        'font_size' => (int)$barTemplate['font_size'],
    ],
    'kitchen' => [
        'text' => build_configurable_kitchen_receipt($table, $items, $receiptNumber),
        'font_size' => (int)$kitchenTemplate['font_size'],
    ],
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
