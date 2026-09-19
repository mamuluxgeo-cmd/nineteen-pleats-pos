<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/order-workflow.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
require_login();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'არასწორი მოთხოვნა.'], JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    $result = pos_add_item((int)($_POST['table_id'] ?? 0), (int)($_POST['product_id'] ?? 0), (int)($_POST['quantity'] ?? 1), (string)($_POST['comment'] ?? ''));
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $error) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    pos_render_error($error);
}
