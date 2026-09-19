<?php
// Optional synthetic scale check inside the already-guarded integration DB.
if (!isset($appRoot, $pdo) || getenv('POS_TEST_MODE') !== '1' || getenv('POS_TEST_BENCHMARK') !== '1') throw new RuntimeException('Run via isolated integration tests.');
$benchmarkTable = audit_table($pdo);
$pdo->beginTransaction();
$orderInsert = $pdo->prepare("INSERT INTO orders (business_day_id,table_id,user_id,status,subtotal_total,total,cash_amount,closed_at) VALUES (?,?,1,'closed',100,110,110,'2026-06-05 12:00:00')");
$itemInsert = $pdo->prepare('INSERT INTO order_items (order_id,product_id,product_name,quantity,price,product_cost,sent_at) VALUES ' . implode(',', array_fill(0, 10, "(?,?,'Scale product',1,10,3,'2026-06-05 12:00:00')")));
for ($i = 0; $i < 10000; $i++) {
    $orderInsert->execute([$dayId, $benchmarkTable]);
    $id = (int)$pdo->lastInsertId();
    $args = [];
    for ($j = 0; $j < 10; $j++) { $args[] = $id; $args[] = $productId; }
    $itemInsert->execute($args);
}
$pdo->commit();
$updatedRuns = [];
for ($i = 0; $i < 3; $i++) {
    $updated = audit_request('statistics', ['range' => 'year']);
    test_equal($updated['status'], 200, '100,000-line report loads');
    test_assert($updated['peak_mb'] < 32, 'large report uses bounded PHP memory');
    $updatedRuns[] = round($updated['ms'], 1);
}

// Compare the actual pre-audit report implementation against identical data.
// Only report source files are replaced in the disposable app, never config.
$files = ['statistics.php', 'statistics-route.php'];
$current = [];
try {
    foreach ($files as $file) {
        $current[$file] = file_get_contents($appRoot . '/' . $file);
        $process = proc_open(['git', 'show', 'b825ff75ad84253524459dc8b63f50ca8a420454:public_html/' . $file], [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, dirname(__DIR__));
        $source = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException('Fetch the baseline commit before benchmarking: ' . $error);
        file_put_contents($appRoot . '/' . $file, $source);
    }
    $baselineRuns = [];
    for ($i = 0; $i < 3; $i++) {
        $baseline = audit_request('statistics', ['range' => 'year']);
        test_equal($baseline['status'], 200, 'baseline benchmark completes');
        $baselineRuns[] = round($baseline['ms'], 1);
    }
    sort($updatedRuns); sort($baselineRuns);
    echo json_encode(['fixture' => '10,000 extra orders / 100,000 extra items; same DB and report period',
        'baseline' => ['median_ms' => $baselineRuns[1], 'runs_ms' => $baselineRuns, 'peak_mb' => $baseline['peak_mb']],
        'updated' => ['median_ms' => $updatedRuns[1], 'runs_ms' => $updatedRuns, 'peak_mb' => $updated['peak_mb']]], JSON_UNESCAPED_SLASHES) . "\n";
} finally {
    foreach ($current as $file => $source) file_put_contents($appRoot . '/' . $file, $source);
}
