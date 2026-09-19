<?php
require_once __DIR__ . '/order-workflow.php';
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
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => (int)$user['id'],
                'name' => $user['name'],
                'username' => $user['username'],
                'role' => $user['role'],
            ];
            session_write_close();
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

    $workflowActions = ['open_day', 'close_day', 'cash_movement', 'add_item', 'update_item_quantity', 'cancel_item', 'send_order', 'cancel_order', 'close_order'];
    if (in_array($action, $workflowActions, true)) {
        $tableId = (int)($_POST['table_id'] ?? 0);
        try {
            switch ($action) {
                case 'open_day':
                    pos_open_day((float)($_POST['opening_cash'] ?? 0), trim($_POST['note'] ?? ''));
                    flash('სამუშაო დღე გაიხსნა.');
                    redirect_to('day');
                case 'cash_movement':
                    pos_cash_movement($_POST['movement_type'] ?? '', (float)($_POST['amount'] ?? 0), trim($_POST['note'] ?? ''));
                    flash('სალაროს მოძრაობა ჩაიწერა.');
                    redirect_to('day');
                case 'close_day':
                    pos_close_day((float)($_POST['closing_cash'] ?? 0), trim($_POST['close_note'] ?? ''));
                    flash('სამუშაო დღე დაიხურა.');
                    redirect_to('history');
                case 'add_item':
                    pos_add_item($tableId, (int)($_POST['product_id'] ?? 0), (int)($_POST['quantity'] ?? 1), (string)($_POST['comment'] ?? ''));
                    flash('პროდუქტი დაემატა.');
                    break;
                case 'update_item_quantity':
                    pos_edit_item($tableId, (int)($_POST['item_id'] ?? 0), (int)($_POST['quantity'] ?? 1));
                    flash('რაოდენობა განახლდა.');
                    break;
                case 'cancel_item':
                    pos_edit_item($tableId, (int)($_POST['item_id'] ?? 0), null);
                    flash('პროდუქტი სიიდან წაიშალა.');
                    break;
                case 'send_order':
                    $sent = pos_send_order($tableId);
                    $ids = array_column($sent['items'], 'id');
                    redirect_to('print_order', ['order_id' => (int)$sent['order']['id'], 'table_id' => $tableId, 'item_ids' => implode(',', $ids)]);
                case 'cancel_order':
                    pos_cancel_order($tableId, null);
                    flash('მაგიდა დაიხურა ნულით.');
                    redirect_to('tables');
                case 'close_order':
                    $closed = pos_close_order($tableId, $_POST);
                    redirect_to('print_final', ['order_id' => (int)$closed['order']['id']]);
            }
        } catch (InvalidArgumentException $error) {
            flash($error->getMessage(), 'warn');
        } catch (Throwable $error) {
            pos_record_error($error);
            flash('ოპერაცია ვერ დადასტურდა. ხელახალ მოქმედებამდე გადაამოწმე მაგიდა ან ისტორია.', 'warn');
        }
        redirect_to($tableId > 0 ? 'table' : 'day', $tableId > 0 ? ['id' => $tableId] : []);
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
