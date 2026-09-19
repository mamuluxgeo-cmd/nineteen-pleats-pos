<?php

function ensure_order_numbering(): void {
    // Database structure is installed once through schema/migrations.
    // Never run DDL, metadata scans or named locks during a POS request.
}

function ensure_order_receipt_number(int $orderId): int {
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();

    try {
        if ($ownsTransaction) $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT receipt_number FROM orders WHERE id=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Order not found while assigning receipt number.');
        }

        $number = (int)($row['receipt_number'] ?? 0);
        if ($number > 0) {
            if ($ownsTransaction) $pdo->commit();
            return $number;
        }

        $pdo->exec('INSERT INTO order_number_sequence () VALUES ()');
        $number = (int)$pdo->lastInsertId();
        $update = $pdo->prepare('UPDATE orders SET receipt_number=? WHERE id=? AND (receipt_number IS NULL OR receipt_number=0)');
        $update->execute([$number, $orderId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Receipt number was not assigned.');
        }
        if ($ownsTransaction) $pdo->commit();
        return $number;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function receipt_number_for_order($order): int {
    if (is_array($order)) {
        $number = (int)($order['receipt_number'] ?? 0);
        if ($number > 0) return $number;
        $orderId = (int)($order['id'] ?? 0);
    } else {
        $orderId = (int)$order;
    }
    return $orderId > 0 ? ensure_order_receipt_number($orderId) : 0;
}

function add_receipt_number_to_text(string $text, int $receiptNumber): string {
    if ($receiptNumber <= 0) return $text;
    $line = 'ქვითრის ნომერი: #' . $receiptNumber;
    if (strpos($text, $line) !== false) return $text;

    $updated = preg_replace_callback('/^-{8,}\R/m', function ($match) use ($line) {
        return $line . "\n" . $match[0];
    }, $text, 1);

    return is_string($updated) && $updated !== $text ? $updated : ($line . "\n" . $text);
}
