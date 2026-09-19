<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/order-workflow.php';

require_login();
session_write_close();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function cancel_table_json(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ensure_order_cancellation_schema(): void {
    // Installed by database/schema.sql. Runtime requests must never execute DDL.
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cancel_table_json(['ok' => false, 'message' => 'არასწორი მოთხოვნა.'], 405);
}

$tableId = (int)($_POST['table_id'] ?? 0);

$preset = trim((string)($_POST['cancel_reason'] ?? ''));
$custom = trim((string)($_POST['cancel_reason_custom'] ?? ''));
$allowedReasons = [
    'კლიენტმა გადაიფიქრა',
    'სტუმარი წავიდა',
    'შეცდომით გაიხსნა',
    'შეკვეთა დუბლირებულია',
    'სხვა',
];
if (!in_array($preset, $allowedReasons, true)) {
    $preset = 'სხვა';
}

if ($preset === 'სხვა') {
    $reason = $custom;
} elseif ($custom !== '') {
    $reason = $preset . ' — ' . $custom;
} else {
    $reason = $preset;
}

if ($reason === '') {
    cancel_table_json(['ok' => false, 'message' => 'მიუთითე გაუქმების მიზეზი.'], 422);
}
if (function_exists('mb_substr')) {
    $reason = mb_substr($reason, 0, 250, 'UTF-8');
} else {
    $reason = substr($reason, 0, 250);
}

try {
    $result = pos_cancel_order($tableId, $reason);
    $result['message'] = 'შეკვეთა გაუქმდა და გაყიდვაში არ ჩაითვლება.';
    cancel_table_json($result);
} catch (InvalidArgumentException $error) {
    cancel_table_json(['ok' => false, 'message' => $error->getMessage()], 409);
} catch (Throwable $error) {
    pos_render_error($error);
}
