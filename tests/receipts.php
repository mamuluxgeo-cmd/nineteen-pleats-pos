<?php
require __DIR__ . '/assertions.php';
require __DIR__ . '/../public_html/includes/service-charge.php';
date_default_timezone_set('Asia/Tbilisi');

// No application bootstrap/config and absolutely no database access in this
// suite. Only printer formatting dependencies are supplied as test fixtures.
function cfg(string $key, $default = null) { return $default; }
function db(): PDO { throw new RuntimeException('Receipt unit tests must not use a database.'); }
function qty($value): string { return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.'); }
function payment_label(?string $type): string { return ['cash' => 'ნაღდი', 'card' => 'ბარათი', 'mixed' => 'შერეული'][$type] ?? '—'; }
require __DIR__ . '/../public_html/includes/receipt-templates.php';
$GLOBALS['garbalia_receipt_template_cache'] = receipt_template_defaults();

$items = [
    ['product_name' => 'პროდუქტი', 'quantity' => '2.00', 'price' => '50.00', 'is_cancelled' => 0, 'comment' => ''],
    ['product_name' => 'გაუქმებული პროდუქტი', 'quantity' => '1.00', 'price' => '30.00', 'is_cancelled' => 1, 'comment' => ''],
];
$table = ['name' => 'მაგიდა 1'];
$order = ['status' => 'closed', 'subtotal_total' => '100.00', 'discount_amount' => '20.00', 'total' => '88.00', 'payment_type' => 'mixed', 'cash_amount' => '30.00', 'card_amount' => '58.00', 'closed_at' => '2026-08-31 14:20:00'];
$receipt = build_configurable_final_receipt($table, $order, $items, 1001);
foreach (['ქვეჯამი: 100.00 GEL', 'ფასდაკლება: -20.00 GEL', 'მომსახურება (10%): 8.00 GEL', 'საბოლოო ჯამი: 88.00 GEL', 'ნაღდი: 30.00 GEL', 'ბარათი: 58.00 GEL', 'დრო: 2026-08-31 14:20', '#1001'] as $expected) {
    test_assert(strpos($receipt, $expected) !== false, 'receipt includes ' . $expected);
}
test_assert(strpos($receipt, 'გაუქმებული პროდუქტი') === false, 'cancelled item is not printed');
test_assert(strpos($receipt, 'ქვეჯამი:') < strpos($receipt, 'ფასდაკლება:') && strpos($receipt, 'ფასდაკლება:') < strpos($receipt, 'მომსახურება (') && strpos($receipt, 'მომსახურება (') < strpos($receipt, 'საბოლოო ჯამი:'), 'receipt rows follow subtotal, discount, fee, final total');

$oldOrder = array_merge($order, ['total' => '80.00', 'cash_amount' => '30.00', 'card_amount' => '50.00']);
$old = build_configurable_final_receipt($table, $oldOrder, $items, 1002);
test_assert(strpos($old, 'მომსახურება') === false, 'old closed receipt never gains service');
test_assert(strpos($old, 'საბოლოო ჯამი: 80.00 GEL') !== false, 'old receipt keeps saved total');

$legacyOrder = $oldOrder;
unset($legacyOrder['subtotal_total']);
$legacy = build_configurable_final_receipt($table, $legacyOrder, $items, 1002);
test_assert(strpos($legacy, 'ქვეჯამი: 100.00 GEL') !== false, 'legacy missing subtotal falls back to active items');
test_assert(strpos($legacy, 'მომსახურება') === false, 'legacy missing subtotal is not guessed as service');

$takeaway = build_configurable_final_receipt(['name' => 'გატანა 10'], $oldOrder, $items, 1003);
test_assert(strpos($takeaway, 'მომსახურება') === false, 'takeaway receipt has no fee line');
$renamed = build_configurable_final_receipt(['name' => 'გატანა 10'], $order, $items, 1004);
test_assert(strpos($renamed, 'მომსახურება (10%): 8.00 GEL') !== false, 'reprint reads saved accounting values, not the current table name');

$GLOBALS['garbalia_receipt_template_cache']['final']['show_totals'] = 0;
$mandatory = build_configurable_final_receipt($table, $order, $items, 1006);
test_assert(strpos($mandatory, 'მომსახურება (10%): 8.00 GEL') !== false, 'mandatory service cannot be hidden by receipt template settings');
test_assert(strpos($mandatory, 'საბოლოო ჯამი: 88.00 GEL') !== false, 'payable total remains visible with mandatory service');
$optionalOld = build_configurable_final_receipt($table, $oldOrder, $items, 1007);
test_assert(strpos($optionalOld, 'საბოლოო ჯამი:') === false, 'legacy zero-fee receipt keeps its template setting');
$GLOBALS['garbalia_receipt_template_cache']['final']['show_totals'] = 1;

foreach (['bar', 'kitchen'] as $type) {
    $function = 'build_configurable_' . $type . '_receipt';
    $ticket = $function($table, $items, 1005);
    test_assert(strpos($ticket, 'მომსახურება') === false, $type . ' ticket has no service');
    test_assert(strpos($ticket, '2026-08-31 14:20') === false, $type . ' ticket does not use old final close time');
}
test_finish('Final receipts and legacy reprints');
