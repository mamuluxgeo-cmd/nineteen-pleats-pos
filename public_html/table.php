<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/actions.php';

try {
    handle_post_action();
    require_login();

    $day = active_day();
    if (!$day) {
        flash('ჯერ გახსენი სამუშაო დღე.', 'warn');
        redirect_to('day');
    }

    $tableId = (int)($_GET['id'] ?? 0);
    $table = fetch_table($tableId);
    if (!$table) redirect_to('tables');

    $order = current_open_order((int)$day['id'], $tableId);
    $items = [];
    $total = 0.0;
    $unsentCount = 0;

    if ($order) {
        $items = order_items((int)$order['id']);
        foreach ($items as $item) {
            if ((int)$item['is_cancelled'] === 1) continue;
            $total += (float)$item['quantity'] * (float)$item['price'];
            if (empty($item['sent_at'])) $unsentCount++;
        }
    }

    // Only active fields needed by the POS are selected, reducing transfer and hydration work.
    $products = db()->query(
        'SELECT p.id, p.name, p.price, c.name category_name '
        . 'FROM products p LEFT JOIN categories c ON c.id=p.category_id '
        . 'WHERE p.is_active=1 '
        . 'ORDER BY CASE WHEN c.name LIKE "%ხინკ%" THEN 0 WHEN c.name LIKE "%სასმ%" THEN 1 ELSE 2 END, '
        . 'c.sort_order, c.name, p.sort_order, p.name'
    )->fetchAll();

    render_header($table['name']);

    $receiptBadge = '';
    if ($order && (int)($order['receipt_number'] ?? 0) > 0) {
        $receiptBadge = '<div class="pill" data-open-receipt-number="1" style="background:#2b1b10;color:#fff;font-weight:950">ქვითარი #'.(int)$order['receipt_number'].'</div>';
    }

    $orderId = (int)($order['id'] ?? 0);
    $serviceRate = pos_is_takeaway($table) ? 0 : 10;
    echo '<div class="page-head" data-order-id="'.$orderId.'" data-service-rate="'.$serviceRate.'"><h1>'.h($table['name']).'</h1>'.$receiptBadge.'<div class="total-box" data-subtotal="'.number_format($total, 2, '.', '').'">'.money($total).'</div></div>';
    echo '<section class="pos-grid"><div class="card"><h2>პროდუქტის დამატება</h2>';

    if (!$products) echo '<p class="muted">პროდუქტები ჯერ არ არის დამატებული.</p>';
    $cat = null;
    foreach ($products as $product) {
        if ($cat !== $product['category_name']) {
            $cat = $product['category_name'];
            echo '<h3 class="category-title">'.h($cat ?: 'სხვა').'</h3>';
        }
        echo '<form class="product-row" method="post">'
            . '<input type="hidden" name="action" value="add_item">'
            . '<input type="hidden" name="table_id" value="'.$tableId.'">'
            . '<input type="hidden" name="product_id" value="'.(int)$product['id'].'">'
            . '<div class="product-name"><strong>'.h($product['name']).'</strong><small>'.money($product['price']).'</small></div>'
            . '<input class="qty-input" name="quantity" type="number" min="1" step="1" value="1">'
            . '<input name="comment" placeholder="კომენტარი">'
            . '<button class="btn">დამატება</button></form>';
    }

    echo '</div><div class="card current-order-card"><h2>მიმდინარე შეკვეთა</h2>';
    if (!$items) echo '<p class="muted">შეკვეთა ჯერ ცარიელია.</p>';

    foreach ($items as $item) {
        $cancelled = (int)$item['is_cancelled'] === 1;
        $sent = !empty($item['sent_at']);
        $statusClass = $cancelled ? 'cancelled' : ($sent ? 'sent-item' : 'unsent-item');
        echo '<div class="order-item '.$statusClass.'"><div><strong>'.h(qty($item['quantity']).' x '.$item['product_name']).'</strong>'
            . '<small>'.money($item['price']).' / ჯამი: '.money((float)$item['price']*(float)$item['quantity']).'</small>';
        if ($item['comment']) echo '<em>'.h($item['comment']).'</em>';
        if ($sent && !$cancelled) echo '<small class="sent">გაგზავნილია</small>';
        if (!$sent && !$cancelled) echo '<small class="unsent-text">გასაგზავნია</small>';
        if ($cancelled) echo '<small class="danger-text">გაუქმებულია: '.h($item['cancel_reason']).'</small>';
        echo '</div>';

        if (!$cancelled) {
            echo '<form class="cancel-form" method="post">'
                . '<input type="hidden" name="action" value="cancel_item">'
                . '<input type="hidden" name="table_id" value="'.$tableId.'">'
                . '<input type="hidden" name="item_id" value="'.(int)$item['id'].'">'
                . '<select name="cancel_reason"><option>შეცდომით დაემატა</option><option>კლიენტმა გადაიფიქრა</option><option>პროდუქტი აღარ არის</option><option>სხვა</option></select>'
                . '<input name="cancel_reason_custom" placeholder="დამატებით">'
                . '<button class="btn danger">გაუქმება</button></form>';
        }
        echo '</div>';
    }

    echo '<div class="actions"><form class="send-order-form" method="post">'
        . '<input type="hidden" name="action" value="send_order">'
        . '<input type="hidden" name="table_id" value="'.$tableId.'">'
        . '<button class="btn primary" '.($unsentCount <= 0 ? 'disabled' : '').'>შეკვეთის გაგზავნა / ბეჭდვა</button>'
        . '</form></div>';

    if ($order) {
        echo '<hr><h2>მაგიდის დახურვა</h2><form class="close-form" method="post" data-service-rate="'.$serviceRate.'">'
            . '<input type="hidden" name="action" value="close_order">'
            . '<input type="hidden" name="table_id" value="'.$tableId.'">'
            . '<input type="hidden" name="expected_order_id" value="'.$orderId.'">'
            . '<label>გადახდის ტიპი<select id="payment_type" name="payment_type"><option value="cash">ნაღდი</option><option value="card">ბარათი</option><option value="mixed">შერეული</option></select></label>'
            . '<div id="mixed_fields" class="mixed-fields"><label>ნაღდი<input name="cash_amount" type="number" step="0.01" min="0"></label><label>ბარათი<input name="card_amount" type="number" step="0.01" min="0"></label></div>'
            . '<button class="btn success">საბოლოო ანგარიში</button></form>';
    }

    echo '</div></section>';
    echo '<script defer src="/assets/table-fast-actions.js?v=3"></script>';
    render_footer();
} catch (Throwable $e) {
    render_header('შეცდომა');
    echo '<section class="card narrow error"><h1>შეცდომა</h1><p>მაგიდის ჩატვირთვა ვერ მოხერხდა.</p><pre>'.h($e->getMessage()).'</pre></section>';
    render_footer();
}
