<?php
function handle_post_action(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $stmt = db()->prepare('SELECT * FROM users WHERE username=? AND is_active=1 LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user'] = [
                'id' => (int)$user['id'],
                'name' => $user['name'],
                'username' => $user['username'],
                'role' => $user['role'],
            ];
            redirect_to('day');
        }
        flash('მომხმარებელი ან პაროლი არასწორია.', 'warn');
        redirect_to('login');
    }

    require_login();

    // Authentication has already been read from $_SESSION. Do not keep PHP's
    // session file lock while MySQL is doing work: another click/AJAX request
    // from the same POS terminal must be able to proceed concurrently. flash()
    // re-opens the session only when a redirect actually needs to store a message.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    if ($action === 'open_day') {
        if (active_day()) {
            flash('სამუშაო დღე უკვე გახსნილია.');
            redirect_to('day');
        }
        $openingCash = max(0, (float)($_POST['opening_cash'] ?? 0));
        $note = trim($_POST['note'] ?? '');
        $stmt = db()->prepare("INSERT INTO business_days (opened_by, opening_cash, note, status) VALUES (?, ?, ?, 'open')");
        $stmt->execute([current_user()['id'], $openingCash, $note]);
        flash('სამუშაო დღე გაიხსნა.');
        redirect_to('day');
    }

    if ($action === 'cash_movement') {
        $day = active_day();
        if (!$day) {
            flash('სალაროს მოძრაობის ჩასაწერად ჯერ გახსენი სამუშაო დღე.', 'warn');
            redirect_to('day');
        }
        ensure_cash_movements_table();
        $type = $_POST['movement_type'] ?? '';
        if (!in_array($type, ['add', 'remove', 'expense'], true)) {
            flash('სალაროს მოძრაობის ტიპი არასწორია.', 'warn');
            redirect_to('day');
        }
        $amount = max(0, (float)($_POST['amount'] ?? 0));
        if ($amount <= 0) {
            flash('თანხა უნდა იყოს 0-ზე მეტი.', 'warn');
            redirect_to('day');
        }
        $note = trim($_POST['note'] ?? '');
        $stmt = db()->prepare('INSERT INTO cash_movements (business_day_id, user_id, type, amount, note) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([(int)$day['id'], current_user()['id'], $type, $amount, $note]);
        flash('სალაროს მოძრაობა ჩაიწერა.');
        redirect_to('day');
    }

    if ($action === 'close_day') {
        $day = active_day();
        if (!$day) {
            flash('გასახსნელი სამუშაო დღე არ არის.', 'warn');
            redirect_to('day');
        }
        if (open_orders_count((int)$day['id']) > 0) {
            flash('დღის დახურვამდე ყველა მაგიდა უნდა დაიხუროს.', 'warn');
            redirect_to('day');
        }
        $summary = day_summary((int)$day['id']);
        $cashNet = cash_movements_net((int)$day['id']);
        $expectedCash = (float)$day['opening_cash'] + (float)$summary['cash_total'] + $cashNet;
        $closingCash = (float)($_POST['closing_cash'] ?? 0);
        $diff = $closingCash - $expectedCash;
        $note = trim($_POST['close_note'] ?? '');
        $stmt = db()->prepare("UPDATE business_days SET status='closed', closed_by=?, closed_at=NOW(), closing_cash=?, expected_cash=?, cash_difference=?, close_note=? WHERE id=?");
        $stmt->execute([current_user()['id'], $closingCash, $expectedCash, $diff, $note, $day['id']]);
        flash('სამუშაო დღე დაიხურა.');
        redirect_to('history', ['from' => date('Y-m-d'), 'to' => date('Y-m-d')]);
    }

    if ($action === 'add_item') {
        $day = active_day();
        if (!$day) {
            flash('ჯერ გახსენი სამუშაო დღე.', 'warn');
            redirect_to('day');
        }
        $tableId = (int)($_POST['table_id'] ?? 0);
        $productId = (int)($_POST['product_id'] ?? 0);
        $quantity = max(1, (int)($_POST['quantity'] ?? 1));
        $comment = trim($_POST['comment'] ?? '');
        $table = fetch_table($tableId);
        if (!$table) {
            flash('მაგიდა ვერ მოიძებნა.', 'warn');
            redirect_to('tables');
        }
        $stmt = db()->prepare('SELECT * FROM products WHERE id=? AND is_active=1 LIMIT 1');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) {
            flash('პროდუქტი ვერ მოიძებნა ან გათიშულია.', 'warn');
            redirect_to('table', ['id' => $tableId]);
        }
        $order = current_open_order((int)$day['id'], $tableId);
        $orderId = $order ? (int)$order['id'] : create_order((int)$day['id'], $tableId);
        $stmt = db()->prepare('INSERT INTO order_items (order_id, product_id, product_name, quantity, price, product_cost, comment) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$orderId, $productId, $product['name'], $quantity, $product['price'], $product['cost'] ?? 0, $comment]);
        flash('პროდუქტი დაემატა.');
        redirect_to('table', ['id' => $tableId]);
    }

    if ($action === 'update_item_quantity') {
        $day = active_day();
        if (!$day) {
            flash('სამუშაო დღე დახურულია.', 'warn');
            redirect_to('day');
        }
        $tableId = (int)($_POST['table_id'] ?? 0);
        $itemId = (int)($_POST['item_id'] ?? 0);
        $quantity = max(1, (int)($_POST['quantity'] ?? 1));
        $stmt = db()->prepare('SELECT oi.*, o.id AS order_id FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.id=? AND o.table_id=? AND o.business_day_id=? AND o.status="open" AND oi.is_cancelled=0 LIMIT 1');
        $stmt->execute([$itemId, $tableId, (int)$day['id']]);
        $item = $stmt->fetch();
        if (!$item) {
            flash('პროდუქტი ვერ მოიძებნა.', 'warn');
            redirect_to('table', ['id' => $tableId]);
        }
        if (!empty($item['sent_at'])) {
            flash('გადაგზავნილ პროდუქტზე რაოდენობა აღარ იცვლება.', 'warn');
            redirect_to('table', ['id' => $tableId]);
        }
        db()->prepare('UPDATE order_items SET quantity=? WHERE id=? AND sent_at IS NULL AND is_cancelled=0')->execute([$quantity, $itemId]);
        flash('რაოდენობა განახლდა.');
        redirect_to('table', ['id' => $tableId]);
    }

    if ($action === 'send_order') {
        $day = active_day();
        if (!$day) {
            flash('სამუშაო დღე დახურულია.', 'warn');
            redirect_to('day');
        }
        $tableId = (int)($_POST['table_id'] ?? 0);
        $order = current_open_order((int)$day['id'], $tableId);
        if (!$order) {
            flash('ამ მაგიდაზე შეკვეთა არ არის.', 'warn');
            redirect_to('table', ['id' => $tableId]);
        }
        $stmt = db()->prepare('SELECT * FROM order_items WHERE order_id=? AND is_cancelled=0 AND sent_at IS NULL ORDER BY id ASC');
        $stmt->execute([$order['id']]);
        $items = $stmt->fetchAll();
        if (!$items) {
            flash('ახალი გასაგზავნი პროდუქტი არ არის.', 'warn');
            redirect_to('table', ['id' => $tableId]);
        }
        $ids = array_map(fn($item) => (int)$item['id'], $items);
        $sql = 'UPDATE order_items SET sent_at=NOW() WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        db()->prepare($sql)->execute($ids);
        redirect_to('print_order', ['order_id' => (int)$order['id'], 'table_id' => $tableId, 'item_ids' => implode(',', $ids)]);
    }

    if ($action === 'cancel_item') {
        $day = active_day();
        if (!$day) {
            flash('სამუშაო დღე დახურულია.', 'warn');
            redirect_to('day');
        }
        $tableId = (int)($_POST['table_id'] ?? 0);
        $itemId = (int)($_POST['item_id'] ?? 0);
        $stmt = db()->prepare('SELECT oi.*, o.id AS order_id, o.table_id, o.business_day_id, o.status AS order_status FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.id=? AND o.table_id=? AND o.business_day_id=? AND o.status="open" LIMIT 1');
        $stmt->execute([$itemId, $tableId, (int)$day['id']]);
        $item = $stmt->fetch();
        if (!$item) {
            flash('პროდუქტი ვერ მოიძებნა.', 'warn');
            redirect_to('table', ['id' => $tableId]);
        }
        if (!empty($item['sent_at'])) {
            flash('გადაგზავნილი პროდუქტი აღარ იშლება.', 'warn');
            redirect_to('table', ['id' => $tableId]);
        }
        db()->prepare('DELETE FROM order_items WHERE id=? AND sent_at IS NULL AND is_cancelled=0')->execute([$itemId]);
        $stmt = db()->prepare('SELECT COUNT(*) FROM order_items WHERE order_id=? AND is_cancelled=0');
        $stmt->execute([(int)$item['order_id']]);
        if ((int)$stmt->fetchColumn() === 0) {
            db()->prepare('DELETE FROM orders WHERE id=? AND status="open"')->execute([(int)$item['order_id']]);
        }
        flash('პროდუქტი სიიდან წაიშალა.');
        redirect_to('table', ['id' => $tableId]);
    }

    if ($action === 'cancel_order') {
        $day = active_day();
        if (!$day) {
            flash('სამუშაო დღე დახურულია.', 'warn');
            redirect_to('day');
        }
        $tableId = (int)($_POST['table_id'] ?? 0);
        $order = current_open_order((int)$day['id'], $tableId);
        if (!$order) {
            flash('ამ მაგიდაზე ღია შეკვეთა არ არის.', 'warn');
            redirect_to('tables');
        }
        $total = order_total((int)$order['id']);
        if ($total > 0) {
            flash('ნულით დახურვა შეიძლება მხოლოდ მაშინ, როცა ჯამი 0.00 ₾ არის.', 'warn');
            redirect_to('table', ['id' => $tableId]);
        }
        db()->prepare("UPDATE orders SET status='cancelled', total=0, payment_type=NULL, cash_amount=0, card_amount=0, closed_at=NOW() WHERE id=? AND status='open'")->execute([$order['id']]);
        flash('მაგიდა დაიხურა ნულით.');
        redirect_to('tables');
    }

    if ($action === 'close_order') {
        require_once __DIR__ . '/service-charge.php';
        $tableId = (int)($_POST['table_id'] ?? 0);
        if (($_POST['service_charge_version'] ?? '') !== '1') {
            flash('სისტემა განახლდა — განაახლე გვერდი და ხელახლა დაადასტურე ანგარიში.', 'warn');
            redirect_to('table', ['id' => $tableId]);
        }
        try {
            $day = active_day();
            if (!$day) {
                flash('სამუშაო დღე დახურულია.', 'warn');
                redirect_to('day');
            }
            $table = fetch_table($tableId);
            if (!$table) {
                flash('მაგიდა ვერ მოიძებნა.', 'warn');
                redirect_to('tables');
            }
            $order = current_open_order((int)$day['id'], $tableId);
            if (!$order) {
                flash('ამ მაგიდაზე ღია შეკვეთა არ არის. გადაამოწმე ისტორია.', 'warn');
                redirect_to('tables');
            }
            if (isset($_POST['expected_order_id']) && (int)$_POST['expected_order_id'] !== (int)$order['id']) {
                throw new InvalidArgumentException('მაგიდის შეკვეთა შეიცვალა. განაახლე გვერდი და გადაამოწმე.');
            }
            if (unsent_items_count((int)$order['id']) > 0) {
                throw new InvalidArgumentException('ამ მაგიდაზე არის გაუგზავნელი პროდუქცია — ჯერ გაგზავნე შეკვეთა და შემდეგ დახურე მაგიდა.');
            }
            $subtotal = order_total((int)$order['id']);
            if ($subtotal <= 0) {
                throw new InvalidArgumentException('ამ მაგიდას ჯამი 0.00 ₾ აქვს — გამოიყენე „ნულით დახურვა“.');
            }
            if (isset($_POST['expected_subtotal']) && pos_money_tetri($_POST['expected_subtotal']) !== pos_money_tetri($subtotal)) {
                throw new InvalidArgumentException('შეკვეთის თანხა შეიცვალა. განაახლე გვერდი და თავიდან შეამოწმე გადასახდელი თანხა.');
            }
            $price = pos_price_order($subtotal, pos_is_takeaway($table), $_POST);
            $stmt = db()->prepare("UPDATE orders SET status='closed', subtotal_total=?, total=?, discount_type=?, discount_value=?, discount_amount=?, payment_type=?, cash_amount=?, card_amount=?, closed_at=NOW() WHERE id=? AND status='open'");
            $stmt->execute([$price['subtotal_total'], $price['total'], $price['discount_type'], $price['discount_value'], $price['discount_amount'], $price['payment_type'], $price['cash_amount'], $price['card_amount'], $order['id']]);
            if ($stmt->rowCount() !== 1) {
                throw new InvalidArgumentException('მაგიდა უკვე დაიხურა. გადაამოწმე ისტორია.');
            }
            redirect_to('print_final', ['order_id' => (int)$order['id']]);
        } catch (InvalidArgumentException $e) {
            flash($e->getMessage(), 'warn');
        } catch (Throwable $e) {
            error_log('GARBALIA legacy close order: ' . $e->getMessage());
            flash('მაგიდის დახურვა ვერ დადასტურდა. ხელახალ დაჭერამდე გადაამოწმე მაგიდა ან ისტორია.', 'warn');
        }
        redirect_to('table', ['id' => $tableId]);
    }

    if ($action === 'save_product') {
        require_admin();
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $price = max(0, (float)($_POST['price'] ?? 0));
        $categoryName = trim($_POST['category_name'] ?? 'სხვა');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if ($name === '') {
            flash('პროდუქტის სახელი აუცილებელია.', 'warn');
            redirect_to('products');
        }
        if ($categoryName === '') {
            $categoryName = 'სხვა';
        }
        $stmt = db()->prepare('SELECT id FROM categories WHERE name=? LIMIT 1');
        $stmt->execute([$categoryName]);
        $categoryId = $stmt->fetchColumn();
        if (!$categoryId) {
            db()->prepare('INSERT INTO categories (name, is_active) VALUES (?, 1)')->execute([$categoryName]);
            $categoryId = db()->lastInsertId();
        }
        if ($id > 0) {
            $stmt = db()->prepare('UPDATE products SET category_id=?, name=?, price=?, is_active=? WHERE id=?');
            $stmt->execute([$categoryId, $name, $price, $isActive, $id]);
            flash('პროდუქტი განახლდა.');
        } else {
            $stmt = db()->prepare('INSERT INTO products (category_id, name, price, is_active) VALUES (?, ?, ?, ?)');
            $stmt->execute([$categoryId, $name, $price, $isActive]);
            flash('პროდუქტი დაემატა.');
        }
        redirect_to('products');
    }

    if ($action === 'toggle_product') {
        require_admin();
        $id = (int)($_POST['id'] ?? 0);
        $active = (int)($_POST['is_active'] ?? 0);
        db()->prepare('UPDATE products SET is_active=? WHERE id=?')->execute([$active, $id]);
        flash($active ? 'პროდუქტი გააქტიურდა.' : 'პროდუქტი გაითიშა.');
        redirect_to('products');
    }

    if ($action === 'delete_product') {
        require_admin();
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash('პროდუქტი ვერ მოიძებნა.', 'warn');
            redirect_to('products');
        }
        $stmt = db()->prepare('SELECT COUNT(*) FROM order_items WHERE product_id=?');
        $stmt->execute([$id]);
        $used = (int)$stmt->fetchColumn();
        if ($used > 0) {
            db()->prepare('UPDATE products SET is_active=0 WHERE id=?')->execute([$id]);
            flash('ეს პროდუქტი უკვე გამოყენებულია შეკვეთებში, ამიტომ ისტორიას არ ვშლით — პროდუქტი მხოლოდ გაითიშა.', 'warn');
            redirect_to('products');
        }
        db()->prepare('DELETE FROM products WHERE id=?')->execute([$id]);
        flash('პროდუქტი წაიშალა.');
        redirect_to('products');
    }
}
