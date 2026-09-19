<?php
require_once __DIR__ . '/business-day.php';

function pos_report_period(string $alias = 'o'): string {
    // Keep legacy closed rows with NULL closed_at, without wrapping the indexed
    // column in COALESCE(). The first two values are repeated for the fallback.
    if (!preg_match('/^[a-z]+$/', $alias)) throw new InvalidArgumentException('Invalid SQL alias.');
    return "({$alias}.closed_at BETWEEN ? AND ? OR ({$alias}.closed_at IS NULL AND {$alias}.created_at BETWEEN ? AND ?))";
}

function pos_report_params(string $start, string $end): array {
    return [$start, $end, $start, $end];
}

function pos_statistics(string $start, string $end): array {
    $pdo = db();
    $period = pos_report_period();
    $params = pos_report_params($start, $end);
    $service = pos_service_amount_sql('o');
    $totals = ['revenue' => 0.0, 'serviceTotal' => 0.0, 'discounts' => 0.0, 'costTotal' => 0.0, 'ordersCount' => 0, 'zeroCostLines' => 0];

    // Retain product/table aggregates and one order at a time, never every
    // historical order and line. All reads use the same InnoDB snapshot.
    try {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT o.table_id, COUNT(*) orders_count, SUM(o.total) total, SUM(o.discount_amount) discounts, SUM({$service}) service_total
            FROM orders o WHERE o.status='closed' AND {$period} GROUP BY o.table_id");
        $stmt->execute($params);
        $salesByTable = [];
        foreach ($stmt->fetchAll() as $row) {
            $salesByTable[(int)$row['table_id']] = $row;
            $totals['revenue'] += (float)$row['total'];
            $totals['serviceTotal'] += (float)$row['service_total'];
            $totals['discounts'] += (float)$row['discounts'];
            $totals['ordersCount'] += (int)$row['orders_count'];
        }
        $costByTable = [];
        $products = [];
        $orderId = null;
        $orderGross = 0.0;
        $orderRevenue = 0.0;
        $orderProducts = [];
        $finishOrder = static function () use (&$products, &$orderProducts, &$orderGross, &$orderRevenue): void {
            $factor = $orderGross > 0 ? $orderRevenue / $orderGross : 0.0;
            foreach ($orderProducts as $name => $gross) $products[$name]['net_sales'] += $gross * $factor;
            $orderProducts = [];
            $orderGross = 0.0;
        };
        $buffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $stmt = null;
        try {
            $stmt = $pdo->prepare("SELECT o.id order_id, o.table_id, o.status, o.total, o.subtotal_total, o.discount_amount,
                    oi.product_name, oi.quantity, oi.price, oi.product_cost
                FROM orders o STRAIGHT_JOIN order_items oi ON oi.order_id=o.id AND oi.is_cancelled=0
                WHERE o.status='closed' AND {$period} ORDER BY o.id");
            $stmt->execute($params);
            while ($row = $stmt->fetch()) {
                if ($orderId !== (int)$row['order_id']) {
                    $finishOrder();
                    $orderId = (int)$row['order_id'];
                    $orderRevenue = (float)$row['total'] - pos_service_amount($row);
                }
                $name = trim((string)$row['product_name']);
                if ($name === '') $name = 'პროდუქტი';
                if (!isset($products[$name])) $products[$name] = ['product_name' => $name, 'qty' => 0.0, 'net_sales' => 0.0, 'cost_total' => 0.0];
                $quantity = (float)$row['quantity'];
                $gross = $quantity * (float)$row['price'];
                $cost = $quantity * (float)$row['product_cost'];
                $orderGross += $gross;
                $orderProducts[$name] = ($orderProducts[$name] ?? 0.0) + $gross;
                $products[$name]['qty'] += $quantity;
                $products[$name]['cost_total'] += $cost;
                $tableId = (int)$row['table_id'];
                $costByTable[$tableId] = ($costByTable[$tableId] ?? 0.0) + $cost;
                $totals['costTotal'] += $cost;
                if ((float)$row['product_cost'] <= 0) $totals['zeroCostLines']++;
            }
            $finishOrder();
        } finally {
            if ($stmt) $stmt->closeCursor();
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        }
        $topProducts = array_values($products);
        usort($topProducts, static function (array $a, array $b): int {
            $profitA = $a['net_sales'] - $a['cost_total'];
            $profitB = $b['net_sales'] - $b['cost_total'];
            if (abs($profitA - $profitB) > 0.0001) return $profitA < $profitB ? 1 : -1;
            if (abs($a['qty'] - $b['qty']) > 0.0001) return $a['qty'] < $b['qty'] ? 1 : -1;
            return strcmp($a['product_name'], $b['product_name']);
        });
        $topProducts = array_slice($topProducts, 0, 20);

        $tables = $pdo->query('SELECT id,name,sort_order FROM restaurant_tables WHERE is_active=1 ORDER BY sort_order,id')->fetchAll();
        $tableStats = [];
        foreach ($tables as $table) {
            $id = (int)$table['id'];
            $sales = $salesByTable[$id] ?? [];
            $total = (float)($sales['total'] ?? 0);
            $cost = $costByTable[$id] ?? 0.0;
            $tableStats[] = ['table_name' => $table['name'], 'sort_order' => (int)$table['sort_order'],
                'orders_count' => (int)($sales['orders_count'] ?? 0), 'total' => $total, 'cost_total' => $cost, 'gross_profit' => $total - $cost];
        }
        usort($tableStats, static function (array $a, array $b): int {
            return ($b['orders_count'] <=> $a['orders_count']) ?: (($b['total'] <=> $a['total']) ?: ($a['sort_order'] <=> $b['sort_order']));
        });
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_movements WHERE type='expense' AND created_at BETWEEN ? AND ?");
        $stmt->execute([$start, $end]);
        $expenses = (float)$stmt->fetchColumn();
        $pdo->commit();
        return array_merge($totals, ['topProducts' => $topProducts, 'tableStats' => $tableStats, 'expenses' => $expenses]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function pos_quick_sales(): array {
    $today = garbalia_business_date();
    $base = new DateTimeImmutable($today . ' 12:00:00');
    $previous = $base->modify('first day of previous month');
    $ranges = [
        'today' => [$today, $today],
        'yesterday' => [garbalia_business_date_shift(-1), garbalia_business_date_shift(-1)],
        'month' => [$base->format('Y-m-01'), $today],
        'prev_month' => [$previous->format('Y-m-01'), $previous->format('Y-m-t')],
    ];
    $select = [];
    $params = [];
    $bounds = [];
    foreach ($ranges as $name => $range) {
        [$start, $end] = garbalia_business_range($range[0], $range[1]);
        $bounds[] = $start;
        $bounds[] = $end;
        $select[] = 'COALESCE(SUM(CASE WHEN ' . pos_report_period() . ' THEN o.total ELSE 0 END),0) ' . $name;
        $params = array_merge($params, pos_report_params($start, $end));
    }
    $stmt = db()->prepare("SELECT " . implode(',', $select) . " FROM orders o WHERE o.status='closed' AND " . pos_report_period());
    $stmt->execute(array_merge($params, pos_report_params(min($bounds), max($bounds))));
    return $stmt->fetch();
}

function pos_history_pagination(int $page, int $pages): void {
    if ($pages <= 1) return;
    $params = $_GET;
    unset($params['export'], $params['order_id']);
    echo '<nav class="actions" aria-label="ისტორიის გვერდები">';
    if ($page > 1) echo '<a class="btn" href="' . h(url_for('history', array_merge($params, ['p' => $page - 1]))) . '">წინა</a>';
    echo '<span class="pill">გვერდი ' . $page . ' / ' . $pages . '</span>';
    if ($page < $pages) echo '<a class="btn" href="' . h(url_for('history', array_merge($params, ['p' => $page + 1]))) . '">შემდეგი</a>';
    echo '</nav>';
}
