<?php
// Subprocess adapter for the real application endpoints in a disposable copy.
// No HTTP server, real login credentials, or production config is involved.
if (getenv('POS_TEST_MODE') !== '1' || PHP_SAPI !== 'cli') {
    throw new RuntimeException('This adapter is only for isolated CLI tests.');
}
$appRoot = realpath($argv[1] ?? '');
$prefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'pos-service-test-';
if (!$appRoot || strpos($appRoot, $prefix) !== 0 || !is_file($appRoot . '/config.php')) {
    throw new RuntimeException('The endpoint adapter requires a disposable test app.');
}
$route = $argv[2] ?? '';
if (!in_array($route, ['json', 'legacy'], true)) throw new RuntimeException('Unknown test route.');
$post = json_decode(base64_decode($argv[3] ?? '', true), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($post)) throw new RuntimeException('Invalid test input.');

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('session.save_path', $appRoot . '/sessions');
session_start();
$_SESSION['user'] = ['id' => 1, 'name' => 'Test cashier', 'username' => 'test', 'role' => 'admin'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = $route === 'json' ? '/close_order_print.php' : '/table/1';
$_POST = $post;
if ($route === 'json') {
    require $appRoot . '/close_order_print.php';
} else {
    require $appRoot . '/includes/bootstrap.php';
    require $appRoot . '/includes/actions.php';
    handle_post_action();
}
