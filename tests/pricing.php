<?php
require __DIR__ . '/assertions.php';
require __DIR__ . '/../public_html/includes/service-charge.php';

$cases = json_decode(file_get_contents(__DIR__ . '/pricing-cases.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($cases as $case) {
    foreach (['cash', 'card', 'mixed'] as $payment) {
        $cash = round($case['total'] / 2, 2);
        $input = [
            'discount_enabled' => $case['discount_type'] === 'none' ? '0' : '1',
            'discount_type' => $case['discount_type'],
            'discount_value' => $case['discount_value'],
            'payment_type' => $payment,
            'cash_amount' => $cash,
            'card_amount' => round($case['total'] - $cash, 2),
            // Neither a posted waiver nor a posted rate may override the rule.
            'service_rate' => 0,
            'service_enabled' => '0',
        ];
        $result = pos_price_order($case['subtotal'], $case['takeaway'], $input);
        $label = $case['name'] . '/' . $payment;
        test_money($result['subtotal_total'], $case['subtotal'], $label . ': subtotal');
        test_money($result['discount_amount'], $case['discount'], $label . ': discount');
        test_money($result['service_amount'], $case['service'], $label . ': service');
        test_money($result['total'], $case['total'], $label . ': total');
        test_money($result['cash_amount'] + $result['card_amount'], $case['total'], $label . ': payment balances');
        test_equal($result['payment_type'], $payment, $label . ': payment method');
        if ($payment === 'cash') test_money($result['card_amount'], 0, $label . ': no card');
        if ($payment === 'card') test_money($result['cash_amount'], 0, $label . ': no cash');
    }
}

test_throws(function () {
    pos_price_order(100, false, ['payment_type' => 'mixed', 'cash_amount' => 50, 'card_amount' => 50]);
}, 'mixed payment cannot omit mandatory service');
test_throws(function () {
    pos_price_order(100, false, ['payment_type' => 'mixed', 'cash_amount' => 50, 'card_amount' => 59.99]);
}, 'mixed payment must balance to the tetri');

for ($number = 1; $number <= 10; $number++) {
    test_assert(pos_is_takeaway(['name' => 'გატანა ' . $number]), 'takeaway ' . $number . ' has no service');
    test_assert(!pos_is_takeaway(['name' => 'მაგიდა ' . $number]), 'dine-in table ' . $number . ' has service');
}

$old = ['status' => 'closed', 'subtotal_total' => '100.00', 'discount_amount' => '20.00', 'total' => '80.00'];
test_money(pos_service_amount($old), 0, 'old discounted receipt stays unchanged');
test_money(pos_service_amount(['status' => 'closed', 'total' => '100.00']), 0, 'old receipt without subtotal stays unchanged');
test_money(pos_service_amount(array_merge($old, ['total' => '88.00'])), 8, 'saved new fee is derived from existing values');
test_money(pos_service_amount(array_merge($old, ['status' => 'open', 'total' => '88.00'])), 0, 'open order is not retroactively charged');
test_money(pos_service_amount(array_merge($old, ['total' => '83.00'])), 0, 'unrelated historic adjustment is not labeled as service');
test_money(pos_service_amount(array_merge($old, ['status' => 'cancelled', 'total' => '88.00'])), 0, 'cancelled order has no inferred service');
test_equal(pos_money_tetri(10.075), 1008, 'binary-sensitive amount rounds up to exact tetri');
test_throws(function () { pos_price_order(100, false, ['payment_type' => 'invalid']); }, 'unknown payment type is rejected');
test_throws(function () { pos_price_order(100, false, ['payment_type' => 'mixed', 'cash_amount' => -1, 'card_amount' => 111]); }, 'negative mixed split is rejected');
test_throws(function () { pos_money_tetri('1,234.50'); }, 'server only accepts unformatted numeric values');
test_finish('Pricing and legacy compatibility');
