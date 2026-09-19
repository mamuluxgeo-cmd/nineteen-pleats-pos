<?php
/** Pricing helpers are pure: no SQL, connection, schema change or background work. */
function pos_money_tetri($value): int {
    if (is_float($value)) {
        if (!is_finite($value)) throw new InvalidArgumentException('თანხა არასწორია.');
        $value = number_format($value, 6, '.', '');
    }
    $value = trim((string)$value);
    if (!preg_match('/^([+-]?)(\d+)(?:\.(\d{0,6}))?$/D', $value, $match)) {
        throw new InvalidArgumentException('თანხა არასწორია. განაახლე გვერდი.');
    }
    $whole = (int)$match[2];
    if (strlen(ltrim($match[2], '0')) > 8 || $whole > 99999999) {
        throw new InvalidArgumentException('თანხა დასაშვებ ზღვარს აღემატება.');
    }
    $fraction = str_pad($match[3] ?? '', 3, '0');
    $tetri = $whole * 100 + (int)substr($fraction, 0, 2);
    if ((int)$fraction[2] >= 5) ++$tetri;
    return ($match[1] === '-' ? -1 : 1) * $tetri;
}

function pos_is_takeaway(array $table): bool {
    // Existing table names are authoritative in this schema-preserving release.
    return preg_match('/^გატანა\s*\d+$/u', trim((string)($table['name'] ?? ''))) === 1;
}

function pos_price_order($subtotal, bool $isTakeaway, array $input): array {
    $subtotalTetri = max(0, pos_money_tetri($subtotal));
    $discountType = 'none';
    $discountValue = 0;
    $discountTetri = 0;
    if ((string)($input['discount_enabled'] ?? '') === '1') {
        $discountType = (string)($input['discount_type'] ?? 'percent');
        $value = max(0, pos_money_tetri($input['discount_value'] ?? 0));
        if ($discountType === 'percent') {
            // Two decimal places in the percent are basis points; round once.
            $value = min(10000, $value);
            $discountValue = $value / 100;
            $discountTetri = intdiv($subtotalTetri * $value + 5000, 10000);
        } elseif ($discountType === 'amount') {
            $discountTetri = min($subtotalTetri, $value);
            $discountValue = $discountTetri / 100;
        } else {
            throw new InvalidArgumentException('ფასდაკლების ტიპი არასწორია.');
        }
    }
    $netTetri = max(0, $subtotalTetri - $discountTetri);
    // Mandatory 10% AFTER discount. Ignore any posted service override.
    $serviceTetri = $isTakeaway ? 0 : intdiv($netTetri + 5, 10);
    $totalTetri = $netTetri + $serviceTetri;
    if ($totalTetri > 9999999999) throw new InvalidArgumentException('თანხა დასაშვებ ზღვარს აღემატება.');
    $paymentType = (string)($input['payment_type'] ?? 'cash');
    if (!in_array($paymentType, ['cash', 'card', 'mixed'], true)) {
        throw new InvalidArgumentException('გადახდის ტიპი არასწორია.');
    }
    $cashTetri = $paymentType === 'cash' ? $totalTetri : 0;
    $cardTetri = $paymentType === 'card' ? $totalTetri : 0;
    if ($paymentType === 'mixed') {
        $cashTetri = pos_money_tetri($input['cash_amount'] ?? 0);
        $cardTetri = pos_money_tetri($input['card_amount'] ?? 0);
        if ($cashTetri < 0 || $cardTetri < 0 || $cashTetri + $cardTetri !== $totalTetri) {
            throw new InvalidArgumentException('შერეულ გადახდაში ნაღდი + ბარათი ზუსტად უნდა უდრიდეს საბოლოო ჯამს.');
        }
    }
    return [
        'subtotal_total' => $subtotalTetri / 100,
        'discount_type' => $discountType,
        'discount_value' => $discountValue,
        'discount_amount' => $discountTetri / 100,
        'service_rate' => $isTakeaway ? 0 : 10,
        'service_amount' => $serviceTetri / 100,
        'total' => $totalTetri / 100,
        'payment_type' => $paymentType,
        'cash_amount' => $cashTetri / 100,
        'card_amount' => $cardTetri / 100,
    ];
}

/** Recover the saved fee from existing totals; never recalculate an old sale. */
function pos_service_amount(array $order): float {
    if (($order['status'] ?? '') !== 'closed') return 0.0;
    try {
        $subtotalTetri = pos_money_tetri($order['subtotal_total'] ?? 0);
        if ($subtotalTetri <= 0) return 0.0;
        $discountTetri = max(0, pos_money_tetri($order['discount_amount'] ?? 0));
        $netTetri = max(0, $subtotalTetri - $discountTetri);
        $difference = pos_money_tetri($order['total'] ?? 0) - $netTetri;
        $expectedFee = intdiv($netTetri + 5, 10);
        return $difference > 0 && $difference === $expectedFee ? $difference / 100 : 0.0;
    } catch (InvalidArgumentException $e) {
        // Historical malformed data must not break history/receipt rendering.
        return 0.0;
    }
}

/** Same saved-delta rule for existing aggregate queries; never accepts user SQL. */
function pos_service_amount_sql(string $alias = ''): string {
    if ($alias !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $alias)) {
        throw new InvalidArgumentException('Invalid SQL table alias.');
    }
    $prefix = $alias === '' ? '' : $alias . '.';
    $subtotal = 'COALESCE(' . $prefix . 'subtotal_total,0)';
    $discount = 'GREATEST(0,COALESCE(' . $prefix . 'discount_amount,0))';
    $net = 'GREATEST(0,' . $subtotal . '-' . $discount . ')';
    $difference = 'ROUND(' . $prefix . 'total-' . $net . ',2)';
    return "(CASE WHEN " . $prefix . "status='closed' AND " . $subtotal . '>0 AND '
        . $difference . '=ROUND(' . $net . '*0.10,2) THEN GREATEST(0,' . $difference . ') ELSE 0 END)';
}
