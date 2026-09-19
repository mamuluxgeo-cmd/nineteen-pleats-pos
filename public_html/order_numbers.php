<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/order-numbers.php';

require_login();
session_write_close();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$result = ['orders' => [], 'open_order' => null];

$idsRaw = trim($_GET['ids'] ?? '');
if ($idsRaw !== '') {
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $idsRaw)), function ($id) {
        return $id > 0;
    })));
    $ids = array_slice($ids, 0, 500);

    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare('SELECT id, receipt_number FROM orders WHERE id IN (' . $placeholders . ')');
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            $result['orders'][(string)(int)$row['id']] = (int)$row['receipt_number'];
        }
    }
}

$tableId = (int)($_GET['table_id'] ?? 0);
if ($tableId > 0) {
    $day = active_day();
    if ($day) {
        $order = current_open_order((int)$day['id'], $tableId);
        if ($order) {
            $number = receipt_number_for_order($order);
            $result['open_order'] = [
                'id' => (int)$order['id'],
                'receipt_number' => $number,
            ];
        }
    }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
