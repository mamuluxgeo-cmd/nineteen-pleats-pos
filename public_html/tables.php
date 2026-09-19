<?php
require __DIR__ . '/includes/bootstrap.php';

try {
    require_login();
    $day = active_day();
    if (!$day) {
        flash('ჯერ გახსენი სამუშაო დღე.', 'warn');
        redirect_to('day');
    }

    render_header('მაგიდები');
    echo '<div class="page-head"><h1>მაგიდები</h1><span class="pill">დღე #'.(int)$day['id'].'</span></div><div class="tables-grid">';

    $stmt = db()->prepare(
        "SELECT t.id, t.name, t.sort_order, o.id AS order_id,
                COALESCE(SUM(CASE WHEN oi.is_cancelled=0 THEN oi.quantity * oi.price ELSE 0 END), 0) AS order_total,
                COALESCE(SUM(CASE WHEN oi.is_cancelled=0 AND oi.sent_at IS NULL THEN 1 ELSE 0 END), 0) AS unsent_count
         FROM restaurant_tables t
         LEFT JOIN (
           SELECT table_id, MAX(id) AS id
           FROM orders
           WHERE business_day_id=? AND status='open'
           GROUP BY table_id
         ) current_orders ON current_orders.table_id=t.id
         LEFT JOIN orders o ON o.id=current_orders.id
         LEFT JOIN order_items oi ON oi.order_id=o.id
         WHERE t.is_active=1
         GROUP BY t.id, t.name, t.sort_order, o.id
         ORDER BY t.sort_order, t.id"
    );
    $stmt->execute([(int)$day['id']]);

    foreach ($stmt->fetchAll() as $table) {
        $hasOrder = !empty($table['order_id']);
        $total = (float)$table['order_total'];
        $unsent = (int)$table['unsent_count'];
        $class = !$hasOrder ? 'free' : ($unsent > 0 ? 'pending' : 'occupied');
        $status = !$hasOrder ? 'თავისუფალი' : ($unsent > 0 ? 'გასაგზავნია · '.money($total) : money($total));
        echo '<a class="table-card '.$class.'" href="'.h(url_for('table', ['id'=>(int)$table['id']])).'"><span>'.h($table['name']).'</span><strong>'.h($status).'</strong></a>';
    }

    echo '</div>';
    render_footer();
} catch (Throwable $e) {
    pos_render_error($e);
}
