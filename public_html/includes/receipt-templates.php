<?php
require_once __DIR__ . '/service-charge.php';

function receipt_template_defaults(): array {
    return [
        'bar' => [
            'type' => 'bar',
            'title' => 'სალარო / ბარი',
            'top_note' => '',
            'bottom_note' => '',
            'show_restaurant_name' => 1,
            'show_english_name' => 1,
            'show_address' => 1,
            'show_phone' => 1,
            'show_table' => 1,
            'show_datetime' => 1,
            'show_receipt_number' => 1,
            'show_prices' => 1,
            'show_comments' => 1,
            'show_totals' => 0,
            'show_payment' => 0,
            'font_size' => 13,
            'line_width' => 32,
        ],
        'kitchen' => [
            'type' => 'kitchen',
            'title' => 'სამზარეულო',
            'top_note' => '',
            'bottom_note' => '',
            'show_restaurant_name' => 1,
            'show_english_name' => 0,
            'show_address' => 0,
            'show_phone' => 0,
            'show_table' => 1,
            'show_datetime' => 1,
            'show_receipt_number' => 1,
            'show_prices' => 0,
            'show_comments' => 1,
            'show_totals' => 0,
            'show_payment' => 0,
            'font_size' => 14,
            'line_width' => 32,
        ],
        'final' => [
            'type' => 'final',
            'title' => 'საბოლოო ანგარიში',
            'top_note' => '',
            'bottom_note' => cfg('thank_you_text', 'გმადლობთ სტუმრობისთვის!'),
            'show_restaurant_name' => 1,
            'show_english_name' => 1,
            'show_address' => 1,
            'show_phone' => 1,
            'show_table' => 1,
            'show_datetime' => 1,
            'show_receipt_number' => 1,
            'show_prices' => 1,
            'show_comments' => 0,
            'show_totals' => 1,
            'show_payment' => 1,
            'font_size' => 13,
            'line_width' => 32,
        ],
    ];
}

function ensure_receipt_templates_table(): void {
    // Installed by database/schema.sql. Runtime requests must never execute DDL.
}

function receipt_template(string $type): array {
    $defaults = receipt_template_defaults();
    $fallback = $defaults[$type] ?? $defaults['bar'];
    $cache = $GLOBALS['garbalia_receipt_template_cache'] ?? [];
    if (isset($cache[$type])) return $cache[$type];

    try {
        ensure_receipt_templates_table();
        $stmt = db()->prepare('SELECT * FROM receipt_templates WHERE type=? LIMIT 1');
        $stmt->execute([$type]);
        $row = $stmt->fetch();
        $template = $row ? array_merge($fallback, $row) : $fallback;
    } catch (Throwable $e) {
        $template = $fallback;
    }
    $GLOBALS['garbalia_receipt_template_cache'][$type] = $template;
    return $template;
}

function save_receipt_template(string $type, array $data): void {
    $defaults = receipt_template_defaults();
    if (!isset($defaults[$type])) return;
    ensure_receipt_templates_table();

    $boolKeys = [
        'show_restaurant_name','show_english_name','show_address','show_phone','show_table','show_datetime',
        'show_receipt_number','show_prices','show_comments','show_totals','show_payment'
    ];
    $template = $defaults[$type];
    $template['title'] = trim((string)($data['title'] ?? $template['title']));
    if ($template['title'] === '') $template['title'] = $defaults[$type]['title'];
    $template['top_note'] = trim((string)($data['top_note'] ?? ''));
    $template['bottom_note'] = trim((string)($data['bottom_note'] ?? ''));
    foreach ($boolKeys as $key) $template[$key] = !empty($data[$key]) ? 1 : 0;
    $template['font_size'] = max(10, min(18, (int)($data['font_size'] ?? $template['font_size'])));
    $template['line_width'] = max(24, min(48, (int)($data['line_width'] ?? $template['line_width'])));

    $stmt = db()->prepare("INSERT INTO receipt_templates
        (type,title,top_note,bottom_note,show_restaurant_name,show_english_name,show_address,show_phone,show_table,show_datetime,show_receipt_number,show_prices,show_comments,show_totals,show_payment,font_size,line_width)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE title=VALUES(title),top_note=VALUES(top_note),bottom_note=VALUES(bottom_note),
        show_restaurant_name=VALUES(show_restaurant_name),show_english_name=VALUES(show_english_name),show_address=VALUES(show_address),
        show_phone=VALUES(show_phone),show_table=VALUES(show_table),show_datetime=VALUES(show_datetime),
        show_receipt_number=VALUES(show_receipt_number),show_prices=VALUES(show_prices),show_comments=VALUES(show_comments),
        show_totals=VALUES(show_totals),show_payment=VALUES(show_payment),font_size=VALUES(font_size),line_width=VALUES(line_width)");
    $stmt->execute([
        $type, $template['title'], $template['top_note'], $template['bottom_note'],
        $template['show_restaurant_name'], $template['show_english_name'], $template['show_address'], $template['show_phone'],
        $template['show_table'], $template['show_datetime'], $template['show_receipt_number'], $template['show_prices'],
        $template['show_comments'], $template['show_totals'], $template['show_payment'], $template['font_size'], $template['line_width'],
    ]);
    $GLOBALS['garbalia_receipt_template_cache'][$type] = $template;
}

function reset_receipt_templates(): void {
    ensure_receipt_templates_table();
    db()->exec('DELETE FROM receipt_templates');
    $GLOBALS['garbalia_receipt_template_cache'] = [];
    $defaults = receipt_template_defaults();
    foreach ($defaults as $type => $template) save_receipt_template($type, $template);
}

function receipt_note_lines(string $text): array {
    $text = trim($text);
    if ($text === '') return [];
    return preg_split('/\R/u', $text) ?: [];
}

function configurable_receipt_header(string $type, array $table, int $receiptNumber, ?string $issuedAt = null): array {
    $t = receipt_template($type);
    $lines = [];
    if ((int)$t['show_restaurant_name'] === 1) $lines[] = cfg('restaurant_name', 'ცხრამეტი ნაოჭი');
    if ((int)$t['show_english_name'] === 1 && cfg('restaurant_name_en', '') !== '') $lines[] = cfg('restaurant_name_en');
    if ((int)$t['show_address'] === 1 && cfg('restaurant_address', '') !== '') $lines[] = cfg('restaurant_address');
    if ((int)$t['show_phone'] === 1 && cfg('restaurant_phone', '') !== '') $lines[] = 'ტელ: ' . cfg('restaurant_phone');
    $lines[] = $t['title'];
    foreach (receipt_note_lines($t['top_note']) as $line) $lines[] = $line;
    if ((int)$t['show_table'] === 1) $lines[] = 'მაგიდა: ' . $table['name'];
    if ((int)$t['show_receipt_number'] === 1 && $receiptNumber > 0) $lines[] = 'ქვითრის ნომერი: #' . $receiptNumber;
    if ((int)$t['show_datetime'] === 1) {
        $timestamp = $issuedAt !== null ? strtotime($issuedAt) : false;
        $lines[] = 'დრო: ' . date('Y-m-d H:i', $timestamp !== false ? $timestamp : time());
    }
    $lines[] = str_repeat('-', (int)$t['line_width']);
    return $lines;
}

function configurable_receipt_footer(array $template): array {
    $lines = [];
    foreach (receipt_note_lines((string)$template['bottom_note']) as $line) $lines[] = $line;
    return $lines;
}

function build_configurable_bar_receipt(array $table, array $items, int $receiptNumber): string {
    $t = receipt_template('bar');
    $lines = configurable_receipt_header('bar', $table, $receiptNumber);
    foreach ($items as $item) {
        if ((int)$item['is_cancelled'] === 1) continue;
        $sum = (float)$item['quantity'] * (float)$item['price'];
        $lines[] = qty($item['quantity']) . ' x ' . $item['product_name'];
        if ((int)$t['show_prices'] === 1) {
            $lines[] = number_format((float)$item['price'], 2) . ' x ' . qty($item['quantity']) . ' = ' . number_format($sum, 2) . ' GEL';
        }
        if ((int)$t['show_comments'] === 1 && !empty($item['comment'])) $lines[] = 'კომენტარი: ' . $item['comment'];
    }
    $lines[] = str_repeat('-', (int)$t['line_width']);
    return implode("\n", array_merge($lines, configurable_receipt_footer($t)));
}

function build_configurable_kitchen_receipt(array $table, array $items, int $receiptNumber): string {
    $t = receipt_template('kitchen');
    $lines = configurable_receipt_header('kitchen', $table, $receiptNumber);
    foreach ($items as $item) {
        if ((int)$item['is_cancelled'] === 1) continue;
        $sum = (float)$item['quantity'] * (float)$item['price'];
        $lines[] = qty($item['quantity']) . ' x ' . $item['product_name'];
        if ((int)$t['show_prices'] === 1) {
            $lines[] = number_format((float)$item['price'], 2) . ' x ' . qty($item['quantity']) . ' = ' . number_format($sum, 2) . ' GEL';
        }
        if ((int)$t['show_comments'] === 1 && !empty($item['comment'])) $lines[] = 'კომენტარი: ' . $item['comment'];
    }
    $lines[] = str_repeat('-', (int)$t['line_width']);
    return implode("\n", array_merge($lines, configurable_receipt_footer($t)));
}

function build_configurable_final_receipt(array $table, array $order, array $items, int $receiptNumber): string {
    $t = receipt_template('final');
    // A reprint retains the original closing time; bar/kitchen sends stay current.
    $lines = configurable_receipt_header('final', $table, $receiptNumber, $order['closed_at'] ?? null);
    $subtotal = 0.0;
    foreach ($items as $item) {
        if ((int)$item['is_cancelled'] === 1) continue;
        $sum = (float)$item['quantity'] * (float)$item['price'];
        $subtotal += $sum;
        $lines[] = qty($item['quantity']) . ' x ' . $item['product_name'];
        if ((int)$t['show_prices'] === 1) $lines[] = number_format((float)$item['price'], 2) . ' x ' . qty($item['quantity']) . ' = ' . number_format($sum, 2) . ' GEL';
        if ((int)$t['show_comments'] === 1 && !empty($item['comment'])) $lines[] = 'კომენტარი: ' . $item['comment'];
    }
    $lines[] = str_repeat('-', (int)$t['line_width']);
    $serviceAmount = pos_service_amount($order);
    // A mandatory surcharge must remain visible even when a custom template
    // hides totals. Zero-fee and historical receipts keep that preference.
    if ((int)$t['show_totals'] === 1 || $serviceAmount > 0) {
        $storedSubtotal = (float)($order['subtotal_total'] ?? 0);
        if ($storedSubtotal > 0) $subtotal = $storedSubtotal;
        $discount = (float)($order['discount_amount'] ?? 0);
        $lines[] = 'ქვეჯამი: ' . number_format($subtotal, 2) . ' GEL';
        if ($discount > 0) {
            $lines[] = 'ფასდაკლება: -' . number_format($discount, 2) . ' GEL';
        }
        if ($serviceAmount > 0) {
            $lines[] = 'მომსახურება (10%): ' . number_format($serviceAmount, 2) . ' GEL';
        }
        $lines[] = 'საბოლოო ჯამი: ' . number_format((float)$order['total'], 2) . ' GEL';
    }
    if ((int)$t['show_payment'] === 1) {
        $lines[] = 'გადახდა: ' . payment_label($order['payment_type'] ?? null);
        if (($order['payment_type'] ?? '') === 'mixed') {
            $lines[] = 'ნაღდი: ' . number_format((float)$order['cash_amount'], 2) . ' GEL';
            $lines[] = 'ბარათი: ' . number_format((float)$order['card_amount'], 2) . ' GEL';
        }
    }
    return implode("\n", array_merge($lines, configurable_receipt_footer($t)));
}
