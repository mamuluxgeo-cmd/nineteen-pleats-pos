<?php
// No framework dependency; intentionally compatible with production PHP 7.4.
$GLOBALS['pos_test_assertions'] = 0;

function test_assert(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $GLOBALS['pos_test_assertions']++;
}

function test_equal($actual, $expected, string $label): void {
    test_assert($actual === $expected, $label . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

function test_money($actual, $expected, string $label): void {
    test_assert(is_numeric($actual) && abs((float)$actual - (float)$expected) < 0.000001, $label . ' (expected ' . $expected . ', got ' . var_export($actual, true) . ')');
}

function test_throws(callable $callback, string $label): void {
    $thrown = false;
    try { $callback(); } catch (Throwable $error) { $thrown = true; }
    test_assert($thrown, $label);
}

function test_finish(string $suite): void {
    echo $suite . ': ' . $GLOBALS['pos_test_assertions'] . " assertions passed.\n";
}
