<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$configFile = __DIR__ . '/../config.php';
if (!file_exists($configFile)) {
    $configFile = __DIR__ . '/../config.example.php';
}
$config = require $configFile;
date_default_timezone_set($config['timezone'] ?? 'Asia/Tbilisi');
require_once __DIR__ . '/service-charge.php';

// GET requests only need to read the authenticated user. Release PHP's session
// file lock immediately so a slow DB/page request cannot block another click,
// tab or AJAX request from the same POS terminal. Preserve one-time flash data.
// Logout is the exception because it must destroy the active session.
$GLOBALS['garbalia_flash_snapshot'] = null;
$requestPath = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$isLogoutRequest = (($_GET['page'] ?? '') === 'logout') || preg_match('#/logout/?$#', $requestPath);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !$isLogoutRequest && session_status() === PHP_SESSION_ACTIVE) {
    if (!empty($_SESSION['flash'])) {
        $GLOBALS['garbalia_flash_snapshot'] = $_SESSION['flash'];
        unset($_SESSION['flash']);
    }
    session_write_close();
}

function cfg(string $key, $default = null) {
    global $config;
    return $config[$key] ?? $default;
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($value): string {
    return number_format((float)$value, 2) . ' ₾';
}

function qty($value): string {
    $number = (float)$value;
    if (abs($number - round($number)) < 0.001) {
        return (string)(int)round($number);
    }
    return rtrim(rtrim(number_format($number, 2), '0'), '.');
}

function url_for(string $page, array $params = []): string {
    $page = trim($page, '/');
    if ($page === '') {
        $page = 'day';
    }
    if ($page === 'table' && isset($params['id'])) {
        $id = (int)$params['id'];
        unset($params['id']);
        $path = '/table/' . $id;
    } else {
        $path = '/' . $page;
    }
    $query = $params ? ('?' . http_build_query($params)) : '';
    return $path . $query;
}

function redirect_to(string $page, array $params = []): void {
    header('Location: ' . url_for($page, $params));
    exit;
}

function flash(string $message, string $type = 'flash'): void {
    // GET requests release the session lock early. Re-open only when a redirect
    // actually needs to write a flash message.
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = 'mysql:host=' . cfg('db_host', 'localhost') . ';dbname=' . cfg('db_name') . ';charset=' . cfg('db_charset', 'utf8mb4');
    $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
        PDO::ATTR_PERSISTENT => false,
    ]);
    return $pdo;
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool {
    return current_user() !== null;
}

function is_admin(): bool {
    return (current_user()['role'] ?? '') === 'admin';
}

function require_login(): void {
    if (!is_logged_in()) {
        redirect_to('login');
    }
}

function require_admin(): void {
    require_login();
    if (!is_admin()) {
        flash('ამ გვერდზე წვდომა მხოლოდ ადმინისტრატორს აქვს.', 'warn');
        redirect_to('day');
    }
}

function ensure_cash_movements_table(): void {
    // Installed by database/schema.sql. Runtime requests must never execute DDL.
}

function ensure_order_discount_columns(): void {
    // Installed by database/schema.sql. Runtime requests must never execute DDL.
}

function cash_movement_label(string $type): string {
    return ['add' => 'თანხის დამატება', 'remove' => 'თანხის ამოღება', 'expense' => 'ხარჯი'][$type] ?? 'მოძრაობა';
}

function cash_movement_sign(string $type): int {
    return $type === 'add' ? 1 : -1;
}

function cash_movements_for_day(int $dayId): array {
    ensure_cash_movements_table();
    $stmt = db()->prepare('SELECT cm.*, u.name user_name FROM cash_movements cm LEFT JOIN users u ON u.id=cm.user_id WHERE cm.business_day_id=? ORDER BY cm.id DESC');
    $stmt->execute([$dayId]);
    return $stmt->fetchAll();
}

function cash_movements_net(int $dayId): float {
    ensure_cash_movements_table();
    $stmt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN type='add' THEN amount ELSE -amount END),0) FROM cash_movements WHERE business_day_id=?");
    $stmt->execute([$dayId]);
    return (float)$stmt->fetchColumn();
}

function active_day(): ?array {
    $stmt = db()->query("SELECT * FROM business_days WHERE status='open' ORDER BY id DESC LIMIT 1");
    $day = $stmt->fetch();
    return $day ?: null;
}

function fetch_day(int $dayId): ?array {
    $stmt = db()->prepare('SELECT * FROM business_days WHERE id=?');
    $stmt->execute([$dayId]);
    $day = $stmt->fetch();
    return $day ?: null;
}

function open_orders_count(int $dayId): int {
    $stmt = db()->prepare("SELECT COUNT(*) FROM orders WHERE business_day_id=? AND status='open'");
    $stmt->execute([$dayId]);
    return (int)$stmt->fetchColumn();
}

function unsent_items_count(int $orderId): int {
    $stmt = db()->prepare('SELECT COUNT(*) FROM order_items WHERE order_id=? AND is_cancelled=0 AND sent_at IS NULL');
    $stmt->execute([$orderId]);
    return (int)$stmt->fetchColumn();
}

function fetch_table(int $tableId): ?array {
    $stmt = db()->prepare('SELECT * FROM restaurant_tables WHERE id=? AND is_active=1');
    $stmt->execute([$tableId]);
    $table = $stmt->fetch();
    return $table ?: null;
}

function current_open_order(int $dayId, int $tableId): ?array {
    $stmt = db()->prepare("SELECT * FROM orders WHERE business_day_id=? AND table_id=? AND status='open' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$dayId, $tableId]);
    $order = $stmt->fetch();
    return $order ?: null;
}

function create_order(int $dayId, int $tableId): int {
    $uid = current_user()['id'] ?? null;
    $pdo = db();

    try {
        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO order_number_sequence () VALUES ()');
        $receiptNumber = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("INSERT INTO orders (receipt_number, business_day_id, table_id, user_id, status) VALUES (?, ?, ?, ?, 'open')");
        $stmt->execute([$receiptNumber, $dayId, $tableId, $uid]);
        $orderId = (int)$pdo->lastInsertId();
        $pdo->commit();
        return $orderId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function fetch_order(int $orderId): ?array {
    $stmt = db()->prepare('SELECT * FROM orders WHERE id=?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    return $order ?: null;
}

function order_items(int $orderId): array {
    $stmt = db()->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id ASC');
    $stmt->execute([$orderId]);
    return $stmt->fetchAll();
}

function order_total(int $orderId): float {
    $stmt = db()->prepare('SELECT COALESCE(SUM(quantity * price),0) FROM order_items WHERE order_id=? AND is_cancelled=0');
    $stmt->execute([$orderId]);
    return (float)$stmt->fetchColumn();
}

function day_summary(int $dayId): array {
    $summary = [
        'orders_count' => 0,
        'sales_total' => 0,
        'cash_total' => 0,
        'card_total' => 0,
        'service_total' => 0,
        'cancelled_count' => 0,
    ];
    $serviceSql = pos_service_amount_sql();
    $stmt = db()->prepare("SELECT COUNT(*) c, COALESCE(SUM(total),0) total, COALESCE(SUM(cash_amount),0) cash_total, COALESCE(SUM(card_amount),0) card_total, COALESCE(SUM({$serviceSql}),0) service_total FROM orders WHERE business_day_id=? AND status='closed'");
    $stmt->execute([$dayId]);
    $row = $stmt->fetch() ?: [];
    $summary['orders_count'] = (int)($row['c'] ?? 0);
    $summary['sales_total'] = (float)($row['total'] ?? 0);
    $summary['cash_total'] = (float)($row['cash_total'] ?? 0);
    $summary['card_total'] = (float)($row['card_total'] ?? 0);
    $summary['service_total'] = (float)($row['service_total'] ?? 0);
    $stmt = db()->prepare('SELECT COUNT(*) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.business_day_id=? AND oi.is_cancelled=1');
    $stmt->execute([$dayId]);
    $summary['cancelled_count'] = (int)$stmt->fetchColumn();
    return $summary;
}

function payment_label(?string $type): string {
    return ['cash' => 'ნაღდი', 'card' => 'ბარათი', 'mixed' => 'შერეული'][$type] ?? '—';
}

function discount_label(?string $type, $value): string {
    if ($type === 'percent') return qty($value) . '%';
    if ($type === 'amount') return money($value);
    return '—';
}

function role_label(string $role): string {
    return $role === 'admin' ? 'ადმინისტრატორი' : 'მოლარე';
}

function receipt_header(string $title, array $table): array {
    $lines = [];
    $lines[] = cfg('restaurant_name', 'ცხრამეტი ნაოჭი');
    $lines[] = cfg('restaurant_name_en', 'Nineteen Pleats');
    if (cfg('restaurant_address', '') !== '') {
        $lines[] = cfg('restaurant_address');
    }
    if (cfg('restaurant_phone', '') !== '') {
        $lines[] = 'ტელ: ' . cfg('restaurant_phone');
    }
    $lines[] = $title;
    $lines[] = 'მაგიდა: ' . $table['name'];
    $lines[] = 'დრო: ' . date('Y-m-d H:i');
    $lines[] = str_repeat('-', 32);
    return $lines;
}

function build_kitchen_receipt(array $table, array $items): string {
    $lines = receipt_header('სამზარეულო', $table);
    foreach ($items as $item) {
        if ((int)$item['is_cancelled'] === 1) continue;
        $lines[] = qty($item['quantity']) . ' x ' . $item['product_name'];
        if (!empty($item['comment'])) {
            $lines[] = 'კომენტარი: ' . $item['comment'];
        }
    }
    $lines[] = str_repeat('-', 32);
    return implode("\n", $lines);
}

function build_cashier_receipt(array $table, array $items): string {
    $lines = receipt_header('სალარო / ბარი', $table);
    foreach ($items as $item) {
        if ((int)$item['is_cancelled'] === 1) continue;
        $sum = (float)$item['quantity'] * (float)$item['price'];
        $lines[] = qty($item['quantity']) . ' x ' . $item['product_name'];
        $lines[] = number_format((float)$item['price'], 2) . ' x ' . qty($item['quantity']) . ' = ' . number_format($sum, 2) . ' GEL';
        if (!empty($item['comment'])) {
            $lines[] = 'კომენტარი: ' . $item['comment'];
        }
    }
    $lines[] = str_repeat('-', 32);
    return implode("\n", $lines);
}

function build_final_receipt(array $table, array $order, array $items): string {
    $lines = receipt_header('საბოლოო ანგარიში', $table);
    foreach ($items as $item) {
        if ((int)$item['is_cancelled'] === 1) continue;
        $sum = (float)$item['quantity'] * (float)$item['price'];
        $lines[] = qty($item['quantity']) . ' x ' . $item['product_name'];
        $lines[] = number_format((float)$item['price'], 2) . ' x ' . qty($item['quantity']) . ' = ' . number_format($sum, 2) . ' GEL';
    }
    $subtotal = (float)($order['subtotal_total'] ?? 0);
    if ($subtotal <= 0) {
        foreach ($items as $item) {
            if ((int)$item['is_cancelled'] === 1) continue;
            $subtotal += (float)$item['quantity'] * (float)$item['price'];
        }
    }
    $discountAmount = (float)($order['discount_amount'] ?? 0);
    $discountType = $order['discount_type'] ?? 'none';
    $discountValue = (float)($order['discount_value'] ?? 0);
    $serviceAmount = pos_service_amount($order);
    $lines[] = str_repeat('-', 32);
    if ($discountAmount > 0 || $serviceAmount > 0) {
        $lines[] = 'ქვეჯამი: ' . number_format($subtotal, 2) . ' GEL';
    }
    if ($discountAmount > 0) {
        $lines[] = 'ფასდაკლება (' . discount_label($discountType, $discountValue) . '): -' . number_format($discountAmount, 2) . ' GEL';
    }
    if ($serviceAmount > 0) {
        $lines[] = 'მომსახურება (10%): ' . number_format($serviceAmount, 2) . ' GEL';
    }
    $lines[] = ($serviceAmount > 0 ? 'საბოლოო ჯამი: ' : 'ჯამი: ') . number_format((float)$order['total'], 2) . ' GEL';
    $lines[] = 'გადახდა: ' . payment_label($order['payment_type']);
    if ($order['payment_type'] === 'mixed') {
        $lines[] = 'ნაღდი: ' . number_format((float)$order['cash_amount'], 2) . ' GEL';
        $lines[] = 'ბარათი: ' . number_format((float)$order['card_amount'], 2) . ' GEL';
    }
    $lines[] = cfg('thank_you_text', 'გმადლობთ სტუმრობისთვის!');
    return implode("\n", $lines);
}

function garbalia_mark_svg(): string {
    return '<svg class="garbalia-bird" viewBox="0 0 120 82" aria-hidden="true"><path d="M8 24 L50 9 L86 35 L45 37 Z"/><path d="M50 9 L58 64 L86 35"/><path d="M50 9 L50 37 L8 24"/><path d="M45 37 L58 64 L60 80 L72 48"/><path d="M86 35 L105 14 L116 20 L104 22"/><path d="M86 35 L105 14 L80 3 L50 9"/><path d="M8 24 L33 48 L45 37"/><path d="M33 48 L8 41 L8 24"/></svg>';
}

function render_header(string $title): void {
    $sub = is_logged_in() ? role_label(current_user()['role']) : 'Restaurant Management System';
    echo '<!doctype html><html lang="ka"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>GARBALIA</title><link rel="icon" type="image/png" href="/Logo.png"><link rel="shortcut icon" type="image/png" href="/Logo.png"><link rel="apple-touch-icon" href="/Logo.png"><link rel="stylesheet" href="/assets/style.css?v=26"><link rel="stylesheet" href="/assets/mobile-polish.css?v=2"></head><body class="app-shell">';
    echo '<header class="topbar"><a class="brand garbalia-brand" href="' . h(url_for('day')) . '"><span class="garbalia-mark">' . garbalia_mark_svg() . '</span><span class="brand-text"><strong class="garbalia-word">GARBALIA POS</strong><small>' . h($sub) . '</small></span></a>';
    if (is_logged_in()) {
        echo '<nav class="nav"><a href="' . h(url_for('day')) . '">დღე</a><a href="' . h(url_for('tables')) . '">მაგიდები</a>';
        if (is_admin()) {
            echo '<a href="' . h(url_for('products')) . '">პროდუქტები</a><a href="' . h(url_for('statistics')) . '">სტატისტიკა</a><a href="' . h(url_for('history')) . '">ისტორია</a>';
        }
        echo '<a href="' . h(url_for('logout')) . '">გასვლა</a></nav>';
    }
    echo '</header><main class="wrap">';
    $flash = $GLOBALS['garbalia_flash_snapshot'] ?? null;
    if ($flash) {
        echo '<div class="' . h($flash['type'] ?? 'flash') . '">' . h($flash['message'] ?? '') . '</div>';
    }
}

function garbalia_request_route(): string {
    $page = trim((string)($_GET['page'] ?? ''));
    if ($page !== '') return $page;
    $path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    $first = explode('/', trim($path, '/'))[0] ?? '';
    if ($first === '' || $first === 'index.php') return 'day';
    return $first;
}

function render_footer(): void {
    $route = garbalia_request_route();
    $scripts = [
        '/assets/app.js?v=26',
        '/assets/app-loader.js?v=27',
        '/assets/pwa-install.js?v=4',
    ];

    if ($route === 'day') {
        $scripts[] = '/assets/close-confirm.js?v=25';
        $scripts[] = '/assets/cash-movement-polish.js?v=3';
    } elseif ($route === 'tables') {
        $scripts[] = '/assets/tables-12.js?v=8';
    } elseif ($route === 'table') {
        $scripts[] = '/assets/close-confirm.js?v=25';
        $scripts[] = '/assets/direct-print.js?v=5';
        $scripts[] = '/assets/table-cancel.js?v=3';
        $scripts[] = '/assets/table-page-flow.js?v=4';
    } elseif ($route === 'history') {
        $scripts[] = '/assets/close-confirm.js?v=25';
        $scripts[] = '/assets/direct-print.js?v=5';
    } elseif ($route === 'receipts') {
        $scripts[] = '/assets/close-confirm.js?v=25';
    }

    $scriptHtml = '';
    foreach ($scripts as $src) {
        $scriptHtml .= '<script defer src="' . h($src) . '"></script>';
    }

    echo '</main><footer class="app-footer"><div class="footer-inner"><div class="footer-brand"><span class="footer-mark">' . garbalia_mark_svg() . '</span><div><strong>© GARBALIA POS</strong><small>Restaurant management software</small></div></div><div class="footer-credit"><span>Developed by <b>Giorgi Katamadze</b></span><a class="whatsapp-link" href="https://wa.me/995577785078" target="_blank" rel="noopener">WhatsApp</a></div></div></footer>'
        . $scriptHtml
        . '</body></html>';
}

function receipt_card(string $id, string $title, string $text): string {
    return '<section class="receipt-card"><h2>' . h($title) . '</h2><pre id="' . h($id) . '">' . h($text) . '</pre><button class="btn primary" data-print="' . h($id) . '">Print / ბეჭდვა</button></section>';
}
