<?php

/**
 * Restaurant reporting day.
 *
 * A business date runs from the configured cutoff hour until one second
 * before the same hour on the following calendar day. For GARBALIA the
 * default is 04:00, so 2026-08-03 means 2026-08-03 04:00 through
 * 2026-08-04 03:59:59 in the configured Asia/Tbilisi timezone.
 */
function garbalia_business_cutoff_hour(): int {
    $hour = function_exists('cfg') ? (int)cfg('business_day_cutoff_hour', 4) : 4;
    return max(0, min(23, $hour));
}

function garbalia_business_date(?DateTimeInterface $moment = null): string {
    $date = $moment
        ? new DateTimeImmutable($moment->format('Y-m-d H:i:s.u'), $moment->getTimezone())
        : new DateTimeImmutable('now');

    return $date
        ->modify('-' . garbalia_business_cutoff_hour() . ' hours')
        ->format('Y-m-d');
}

function garbalia_business_date_shift(int $days): string {
    $base = new DateTimeImmutable(garbalia_business_date() . ' 12:00:00');
    $modifier = ($days >= 0 ? '+' : '') . $days . ' days';
    return $base->modify($modifier)->format('Y-m-d');
}

function garbalia_business_month_start(?string $businessDate = null): string {
    $date = $businessDate ?: garbalia_business_date();
    return (new DateTimeImmutable($date . ' 12:00:00'))->format('Y-m-01');
}

function garbalia_business_range(string $from, string $to): array {
    $valid = static function (string $date): bool {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    };

    $today = garbalia_business_date();
    if (!$valid($from)) $from = $today;
    if (!$valid($to)) $to = $from;
    if ($from > $to) [$from, $to] = [$to, $from];

    $hour = garbalia_business_cutoff_hour();
    $clock = sprintf('%02d:00:00', $hour);
    $start = new DateTimeImmutable($from . ' ' . $clock);
    $end = (new DateTimeImmutable($to . ' ' . $clock))
        ->modify('+1 day')
        ->modify('-1 second');

    return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}

function garbalia_next_business_cutoff_timestamp(): int {
    $hour = garbalia_business_cutoff_hour();
    $now = new DateTimeImmutable('now');
    $next = $now->setTime($hour, 0, 0);
    if ($next <= $now) $next = $next->modify('+1 day');
    return $next->getTimestamp();
}
