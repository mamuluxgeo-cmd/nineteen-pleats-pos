<?php
require_once __DIR__ . '/order-numbers.php';
// All order writers take locks in the same order: day (shared), table, order,
// then items. Different tables can proceed in parallel; closing the business
// day takes its exclusive lock and waits for in-flight operations to finish.
function pos_locked_day(PDO $pdo, bool $exclusive = false): array {
    $stmt = $pdo->query("SELECT * FROM business_days WHERE status='open' ORDER BY id DESC LIMIT 1 "
        . ($exclusive ? 'FOR UPDATE' : 'LOCK IN SHARE MODE'));
    $day = $stmt->fetch();
    if (!$day) throw new InvalidArgumentException('სამუშაო დღე დახურულია.');
    return $day;
}

function pos_table_transaction(int $tableId, callable $operation) {
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $day = pos_locked_day($pdo);
        $stmt = $pdo->prepare('SELECT * FROM restaurant_tables WHERE id=? AND is_active=1 FOR UPDATE');
        $stmt->execute([$tableId]);
        $table = $stmt->fetch();
        if (!$table) throw new InvalidArgumentException('მაგიდა ვერ მოიძებნა.');
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE business_day_id=? AND table_id=? AND status='open' ORDER BY id DESC LIMIT 2 FOR UPDATE");
        $stmt->execute([(int)$day['id'], $tableId]);
        $orders = $stmt->fetchAll();
        if (count($orders) > 1) {
            throw new InvalidArgumentException('მაგიდაზე ორი ღია ანგარიშია. ადმინისტრატორმა გადაამოწმოს ორივე ანგარიში.');
        }
        $result = $operation($pdo, $day, $table, $orders[0] ?? null);
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function pos_require_order(?array $order): array {
    if (!$order) throw new InvalidArgumentException('ამ მაგიდაზე ღია შეკვეთა აღარ არის. გადაამოწმე ისტორია.');
    return $order;
}

function pos_add_item(int $tableId, int $productId, int $quantity, string $comment): array {
    $quantity = max(1, min(999, $quantity));
    $comment = mb_substr(trim($comment), 0, 250, 'UTF-8');
    return pos_table_transaction($tableId, static function (PDO $pdo, array $day, array $table, ?array $order) use ($productId, $quantity, $comment): array {
        $stmt = $pdo->prepare('SELECT id, name, price, cost FROM products WHERE id=? AND is_active=1');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) throw new InvalidArgumentException('პროდუქტი ვერ მოიძებნა ან გათიშულია.');
        if (!$order) $order = fetch_order(create_order((int)$day['id'], (int)$table['id']));
        $order = pos_require_order($order);
        $stmt = $pdo->prepare('INSERT INTO order_items (order_id, product_id, product_name, quantity, price, product_cost, comment) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$order['id'], $productId, $product['name'], $quantity, $product['price'], $product['cost'], $comment]);
        $itemId = (int)$pdo->lastInsertId();
        return [
            'ok' => true,
            'item' => ['id' => $itemId, 'name' => $product['name'], 'quantity' => $quantity, 'price' => (float)$product['price'], 'comment' => $comment],
            'order' => ['id' => (int)$order['id'], 'receipt_number' => (int)$order['receipt_number'], 'total' => order_total((int)$order['id'])],
        ];
    });
}

function pos_send_order(int $tableId): array {
    return pos_table_transaction($tableId, static function (PDO $pdo, array $day, array $table, ?array $order): array {
        $order = pos_require_order($order);
        $order['receipt_number'] = receipt_number_for_order($order);
        $stmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id=? AND is_cancelled=0 AND sent_at IS NULL ORDER BY id FOR UPDATE');
        $stmt->execute([$order['id']]);
        $items = $stmt->fetchAll();
        if (!$items) throw new InvalidArgumentException('ახალი გასაგზავნი პროდუქტი არ არის.');
        $stmt = $pdo->prepare('UPDATE order_items SET sent_at=NOW() WHERE order_id=? AND is_cancelled=0 AND sent_at IS NULL');
        $stmt->execute([$order['id']]);
        if ($stmt->rowCount() !== count($items)) throw new RuntimeException('Order items changed during send.');
        return ['table' => $table, 'order' => $order, 'items' => $items];
    });
}

function pos_close_order(int $tableId, array $input): array {
    if (($input['service_charge_version'] ?? '') !== '1') {
        throw new InvalidArgumentException('სისტემა განახლდა. განაახლე გვერდი და ხელახლა დაადასტურე ანგარიში.');
    }
    return pos_table_transaction($tableId, static function (PDO $pdo, array $day, array $table, ?array $order) use ($input): array {
        $order = pos_require_order($order);
        $id = (int)$order['id'];
        if (isset($input['expected_order_id']) && (int)$input['expected_order_id'] !== $id) {
            throw new InvalidArgumentException('მაგიდის შეკვეთა შეიცვალა. განაახლე გვერდი და გადაამოწმე.');
        }
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(quantity * price),0) subtotal, COALESCE(SUM(sent_at IS NULL),0) unsent FROM order_items WHERE order_id=? AND is_cancelled=0');
        $stmt->execute([$id]);
        $sum = $stmt->fetch();
        if ((int)$sum['unsent'] > 0) throw new InvalidArgumentException('ამ მაგიდაზე არის გაუგზავნელი პროდუქცია. ჯერ გაგზავნე შეკვეთა.');
        $subtotal = (float)$sum['subtotal'];
        if ($subtotal <= 0) throw new InvalidArgumentException('ამ მაგიდას ჯამი 0.00 ₾ აქვს. გამოიყენე „ნულით დახურვა“.');
        if (isset($input['expected_subtotal']) && pos_money_tetri($input['expected_subtotal']) !== pos_money_tetri($subtotal)) {
            throw new InvalidArgumentException('შეკვეთის თანხა შეიცვალა. განაახლე გვერდი და გადაამოწმე.');
        }
        $price = pos_price_order($subtotal, pos_is_takeaway($table), $input);
        $order['receipt_number'] = receipt_number_for_order($order);
        $stmt = $pdo->prepare("UPDATE orders SET status='closed', subtotal_total=?, total=?, discount_type=?, discount_value=?, discount_amount=?, payment_type=?, cash_amount=?, card_amount=?, closed_at=NOW() WHERE id=? AND status='open'");
        $stmt->execute([$price['subtotal_total'], $price['total'], $price['discount_type'], $price['discount_value'], $price['discount_amount'], $price['payment_type'], $price['cash_amount'], $price['card_amount'], $id]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Order close was not applied.');
        // Snapshot order/line inputs before releasing the locks. Receipt
        // template loading has a separate fallback in receipt-templates.php.
        return ['table' => $table, 'order' => fetch_order($id), 'items' => order_items($id)];
    });
}

function pos_edit_item(int $tableId, int $itemId, ?int $quantity): void {
    pos_table_transaction($tableId, static function (PDO $pdo, array $day, array $table, ?array $order) use ($itemId, $quantity): void {
        $order = pos_require_order($order);
        $stmt = $pdo->prepare('SELECT id FROM order_items WHERE id=? AND order_id=? AND is_cancelled=0 AND sent_at IS NULL FOR UPDATE');
        $stmt->execute([$itemId, $order['id']]);
        if (!$stmt->fetch()) throw new InvalidArgumentException('პროდუქტი უკვე გაგზავნილია ან აღარ არის ამ შეკვეთაში.');
        if ($quantity !== null) {
            $pdo->prepare('UPDATE order_items SET quantity=? WHERE id=?')->execute([max(1, min(999, $quantity)), $itemId]);
        } else {
            $pdo->prepare('DELETE FROM order_items WHERE id=?')->execute([$itemId]);
            // Do not delete cancelled history, or a concurrent new addition.
            $pdo->prepare("DELETE FROM orders WHERE id=? AND status='open' AND NOT EXISTS (SELECT 1 FROM order_items WHERE order_id=?)")->execute([$order['id'], $order['id']]);
        }
    });
}

function pos_cancel_order(int $tableId, ?string $reason): array {
    return pos_table_transaction($tableId, static function (PDO $pdo, array $day, array $table, ?array $order) use ($reason): array {
        $order = pos_require_order($order);
        $total = order_total((int)$order['id']);
        if ($reason === null && $total > 0) throw new InvalidArgumentException('ნულით დახურვა შეიძლება მხოლოდ მაშინ, როცა ჯამი 0.00 ₾ არის.');
        $userId = current_user()['id'];
        if ($reason !== null) {
            $pdo->prepare('UPDATE order_items SET is_cancelled=1, cancelled_by=?, cancelled_at=NOW(), cancel_reason=? WHERE order_id=? AND is_cancelled=0')->execute([$userId, $reason, $order['id']]);
        }
        $pdo->prepare("UPDATE orders SET status='cancelled', subtotal_total=?, cancelled_total=?, total=0, discount_type='none', discount_value=0, discount_amount=0, payment_type=NULL, cash_amount=0, card_amount=0, cancel_reason=?, cancelled_by=?, cancelled_at=NOW(), closed_at=NOW() WHERE id=? AND status='open'")
            ->execute([$total, $total, $reason, $userId, $order['id']]);
        return ['ok' => true, 'table_id' => (int)$table['id'], 'table_name' => $table['name'], 'order_id' => (int)$order['id'], 'cancelled_total' => $total, 'redirect' => '/tables'];
    });
}

function pos_open_day(float $openingCash, string $note): void {
    $pdo = db();
    // No row exists to lock on the first day. Use one short, connection-scoped
    // mutex only for this rare transition; it is never used for POS item clicks.
    $name = 'pos-day-' . substr(hash('sha256', (string)cfg('db_name')), 0, 40);
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 3)');
    $stmt->execute([$name]);
    if ((int)$stmt->fetchColumn() !== 1) throw new InvalidArgumentException('დღის გახსნა უკვე მიმდინარეობს. გადაამოწმე დღის გვერდი.');
    try {
        if (active_day()) throw new InvalidArgumentException('სამუშაო დღე უკვე გახსნილია.');
        $pdo->prepare("INSERT INTO business_days (opened_by, opening_cash, note, status) VALUES (?, ?, ?, 'open')")
            ->execute([current_user()['id'], max(0, $openingCash), mb_substr($note, 0, 250)]);
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
    }
}

function pos_cash_movement(string $type, float $amount, string $note): void {
    if (!in_array($type, ['add', 'remove', 'expense'], true) || $amount <= 0) throw new InvalidArgumentException('მიუთითე მოძრაობის ტიპი და დადებითი თანხა.');
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $day = pos_locked_day($pdo);
        $pdo->prepare('INSERT INTO cash_movements (business_day_id, user_id, type, amount, note) VALUES (?, ?, ?, ?, ?)')
            ->execute([$day['id'], current_user()['id'], $type, $amount, mb_substr($note, 0, 250)]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function pos_close_day(float $closingCash, string $note): void {
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $day = pos_locked_day($pdo, true);
        if (open_orders_count((int)$day['id']) > 0) throw new InvalidArgumentException('დღის დახურვამდე ყველა მაგიდა უნდა დაიხუროს.');
        $summary = day_summary((int)$day['id']);
        $expected = (float)$day['opening_cash'] + $summary['cash_total'] + cash_movements_net((int)$day['id']);
        $pdo->prepare("UPDATE business_days SET status='closed', closed_by=?, closed_at=NOW(), closing_cash=?, expected_cash=?, cash_difference=?, close_note=? WHERE id=? AND status='open'")
            ->execute([current_user()['id'], $closingCash, $expected, $closingCash - $expected, mb_substr($note, 0, 250), $day['id']]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
