<?php
require __DIR__ . '/assertions.php';
require __DIR__ . '/../public_html/includes/business-day.php';
date_default_timezone_set('Asia/Tbilisi');
foreach ([['2026-06-01 03:59:59','2026-05-31'], ['2026-06-01 04:00:00','2026-06-01'], ['2024-03-01 03:30:00','2024-02-29']] as $case) {
    foreach ([new DateTime($case[0]), new DateTimeImmutable($case[0])] as $moment) {
        test_equal(garbalia_business_date($moment), $case[1], 'mutable/immutable cutoff on supported PHP versions');
        test_equal($moment->format('Y-m-d H:i:s'), $case[0], 'input moment is unchanged');
    }
}
test_equal(garbalia_business_range('2026-06-05','2026-06-05'), ['2026-06-05 04:00:00','2026-06-06 03:59:59'], 'operational day window');
test_finish('Business day boundaries');
