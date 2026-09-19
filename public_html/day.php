<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/actions.php';

try {
    handle_post_action();
    require_login();

    $day = active_day();
    render_header('სამუშაო დღე');

    if (!$day) {
        echo '<section class="card narrow"><h1>სამუშაო დღის გახსნა</h1><p class="muted">შეკვეთების მიღებამდე გახსენი დღე და ჩაწერე სალაროში არსებული საწყისი ნაღდი თანხა.</p><form class="stack" method="post"><input type="hidden" name="action" value="open_day"><label>საწყისი ნაღდი თანხა<input type="number" step="0.01" min="0" name="opening_cash" value="0"></label><label>კომენტარი<input name="note" placeholder="არასავალდებულო"></label><button class="btn success">დღის გახსნა</button></form></section>';
        render_footer();
        exit;
    }

    $dayId = (int)$day['id'];

    // One orders aggregate replaces day_summary() + open_orders_count().
    $serviceAmountSql = pos_service_amount_sql();
    $stmt = db()->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN status='closed' THEN total ELSE 0 END),0) AS sales_total,
            COALESCE(SUM({$serviceAmountSql}),0) AS service_total,
            COALESCE(SUM(CASE WHEN status='closed' THEN cash_amount ELSE 0 END),0) AS cash_total,
            COALESCE(SUM(CASE WHEN status='closed' THEN card_amount ELSE 0 END),0) AS card_total,
            COALESCE(SUM(CASE WHEN status='open' THEN 1 ELSE 0 END),0) AS open_orders
         FROM orders
         WHERE business_day_id=?"
    );
    $stmt->execute([$dayId]);
    $summary = $stmt->fetch() ?: [];

    // Fetch cash movements once and calculate their net in PHP. This removes a
    // second SUM query over the same rows.
    $movements = cash_movements_for_day($dayId);
    $cashNet = 0.0;
    foreach ($movements as $movement) {
        $amount = (float)$movement['amount'];
        $cashNet += cash_movement_sign((string)$movement['type']) > 0 ? $amount : -$amount;
    }

    $salesTotal = (float)($summary['sales_total'] ?? 0);
    $serviceTotal = (float)($summary['service_total'] ?? 0);
    $cashTotal = (float)($summary['cash_total'] ?? 0);
    $cardTotal = (float)($summary['card_total'] ?? 0);
    $openOrders = (int)($summary['open_orders'] ?? 0);
    $expectedCash = (float)$day['opening_cash'] + $cashTotal + $cashNet;

    echo '<div class="page-head"><div><h1>დღე გახსნილია</h1><p class="muted">გახსნა: '.h($day['opened_at']).'</p></div><a class="btn primary" href="'.h(url_for('tables')).'">მაგიდებზე გადასვლა</a></div>';
    echo '<section class="stats"><div><span>გაყიდვები</span><strong>'.money($salesTotal).'</strong></div><div><span>ნაღდი ნავაჭრი</span><strong>'.money($cashTotal).'</strong></div><div><span>ბარათი</span><strong>'.money($cardTotal).'</strong></div><div><span>სალაროს მოძრაობა</span><strong>'.money($cashNet).'</strong></div><div><span>მოსალოდნელი ნაღდი</span><strong>'.money($expectedCash).'</strong></div><div><span>ღია მაგიდები</span><strong>'.$openOrders.'</strong></div></section>';
    echo '<section class="stats"><div><span>პროდუქტები — ფასდაკლების შემდეგ</span><strong>'.money($salesTotal - $serviceTotal).'</strong></div><div><span>მომსახურება</span><strong>'.money($serviceTotal).'</strong></div><div><span>სულ მიღებული</span><strong>'.money($salesTotal).'</strong></div></section>';

    echo '<section class="two-col reports day-cash-layout"><div class="card"><h2>სალაროს მოძრაობა</h2><p class="muted">ჩაწერე თანხის დამატება, ამოღება ან ყოველდღიური ხარჯი — დღის დახურვისას ეს ავტომატურად გაითვალისწინება.</p><form class="stack" method="post"><input type="hidden" name="action" value="cash_movement"><label>ტიპი<select name="movement_type"><option value="add">თანხის დამატება სალაროში</option><option value="remove">თანხის ამოღება სალაროდან</option><option value="expense">ხარჯის ჩაწერა</option></select></label><label>თანხა<input type="number" step="0.01" min="0" name="amount" required></label><label>კომენტარი<input name="note" placeholder="მაგ: პროდუქტის შეძენა, კურიერი..."></label><button class="btn success">ჩაწერა</button></form></div><div class="card"><h2>დღევანდელი ჩანაწერები</h2>';

    if (!$movements) {
        echo '<p class="muted">სალაროს მოძრაობა ჯერ არ არის ჩაწერილი.</p>';
    } else {
        echo '<div class="table-wrap"><table><thead><tr><th>დრო</th><th>ტიპი</th><th>თანხა</th><th>კომენტარი</th></tr></thead><tbody>';
        foreach ($movements as $m) {
            $sign = cash_movement_sign((string)$m['type']);
            $amount = $sign > 0 ? money($m['amount']) : '-' . money($m['amount']);
            echo '<tr><td>'.h(date('H:i', strtotime($m['created_at']))).'</td><td>'.h(cash_movement_label((string)$m['type'])).'</td><td>'.h($amount).'</td><td>'.h($m['note'] ?: '—').'</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    echo '</div></section><section class="card narrow"><h2>დღის დახურვა</h2>';
    if ($openOrders > 0) echo '<div class="warn">დღის დახურვამდე ყველა ღია მაგიდა უნდა დაიხუროს.</div>';
    echo '<form class="stack" method="post"><input type="hidden" name="action" value="close_day"><label>რეალურად დათვლილი ნაღდი<input type="number" step="0.01" min="0" name="closing_cash" value="'.h(number_format($expectedCash,2,'.','')).'"></label><label>დახურვის კომენტარი<input name="close_note" placeholder="არასავალდებულო"></label><button class="btn danger" '.($openOrders > 0 ? 'disabled' : '').'>დღის დახურვა</button></form></section>';

    render_footer();
} catch (Throwable $e) {
    pos_render_error($e);
}
