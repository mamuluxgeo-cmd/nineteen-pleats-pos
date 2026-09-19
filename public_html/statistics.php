<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/product-cost.php';

require_admin();

$setupWarnings = [];
try {
    ensure_order_discount_columns();
} catch (Throwable $e) {
    $setupWarnings[] = 'ფასდაკლების მონაცემების ნაწილი დროებით ვერ ჩაიტვირთა.';
    error_log('GARBALIA statistics discount schema: ' . $e->getMessage());
}
try {
    ensure_product_cost_schema();
} catch (Throwable $e) {
    $setupWarnings[] = 'თვითღირებულების სქემის განახლება ვერ დასრულდა; გვერდი ნულოვანი Cost-ით გაგრძელდება.';
    error_log('GARBALIA statistics cost schema: ' . $e->getMessage());
}

function stat_date_range(string $range): array {
    $today = date('Y-m-d');
    switch ($range) {
        case 'today':
            return [$today, $today, 'დღეს'];
        case 'yesterday':
            $day = date('Y-m-d', strtotime('-1 day'));
            return [$day, $day, 'გუშინ'];
        case 'last7':
            return [date('Y-m-d', strtotime('-6 day')), $today, 'ბოლო 7 დღე'];
        case 'prev_month':
            return [date('Y-m-01', strtotime('first day of previous month')), date('Y-m-t', strtotime('last day of previous month')), 'წინა თვე'];
        case 'year':
            return [date('Y-01-01'), $today, 'ამ წელს'];
        default:
            return [date('Y-m-01'), $today, 'ამ თვეში'];
    }
}

function sales_between(string $from, string $to): array {
    $stmt = db()->prepare("SELECT COUNT(*) orders_count, COALESCE(SUM(total),0) total FROM orders WHERE status='closed' AND COALESCE(closed_at,created_at) BETWEEN ? AND ?");
    $stmt->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    $row = $stmt->fetch() ?: [];
    return [
        'orders_count' => (int)($row['orders_count'] ?? 0),
        'total' => (float)($row['total'] ?? 0),
    ];
}

$range = $_GET['range'] ?? 'month';
[$from, $to, $rangeLabel] = stat_date_range($range);
$startDateTime = $from . ' 00:00:00';
$endDateTime = $to . ' 23:59:59';

$hasProductCost = true;
$hasDiscountAmount = true;
$hasCashMovements = true;

$discountSelect = $hasDiscountAmount ? 'COALESCE(discount_amount,0) discount_amount' : '0 discount_amount';
$stmt = db()->prepare("SELECT id, table_id, status, subtotal_total, total, {$discountSelect} FROM orders WHERE status='closed' AND COALESCE(closed_at,created_at) BETWEEN ? AND ?");
$stmt->execute([$startDateTime, $endDateTime]);
$orders = $stmt->fetchAll();

$orderMap = [];
$orderGross = [];
$orderCost = [];
$revenue = 0.0;
$serviceTotal = 0.0;
$discounts = 0.0;
foreach ($orders as $order) {
    $orderId = (int)$order['id'];
    $orderMap[$orderId] = $order;
    $orderMap[$orderId]['service_amount'] = pos_service_amount($order);
    $orderGross[$orderId] = 0.0;
    $orderCost[$orderId] = 0.0;
    $revenue += (float)$order['total'];
    $serviceTotal += $orderMap[$orderId]['service_amount'];
    $discounts += (float)$order['discount_amount'];
}

$items = [];
if ($orders) {
    $costSelect = $hasProductCost ? 'COALESCE(oi.product_cost,0)' : '0';
    $stmt = db()->prepare("SELECT oi.order_id, oi.product_name, oi.quantity, oi.price, {$costSelect} product_cost
        FROM order_items oi
        JOIN orders o ON o.id=oi.order_id
        WHERE o.status='closed' AND oi.is_cancelled=0
          AND COALESCE(o.closed_at,o.created_at) BETWEEN ? AND ?
        ORDER BY oi.order_id, oi.id");
    $stmt->execute([$startDateTime, $endDateTime]);
    $items = $stmt->fetchAll();
}

$costTotal = 0.0;
$zeroCostLines = 0;
foreach ($items as $item) {
    $orderId = (int)$item['order_id'];
    $quantity = (float)$item['quantity'];
    $gross = $quantity * (float)$item['price'];
    $cost = $quantity * (float)$item['product_cost'];
    $orderGross[$orderId] = ($orderGross[$orderId] ?? 0) + $gross;
    $orderCost[$orderId] = ($orderCost[$orderId] ?? 0) + $cost;
    $costTotal += $cost;
    if ((float)$item['product_cost'] <= 0) $zeroCostLines++;
}

if (!$hasDiscountAmount) {
    $discounts = 0.0;
    foreach ($orders as $order) {
        $orderId = (int)$order['id'];
        $discounts += max(0, ($orderGross[$orderId] ?? 0) - (float)$order['total']);
    }
}

$expenses = 0.0;
if ($hasCashMovements) {
    try {
        $stmt = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_movements WHERE type='expense' AND created_at BETWEEN ? AND ?");
        $stmt->execute([$startDateTime, $endDateTime]);
        $expenses = (float)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $setupWarnings[] = 'ხარჯების მონაცემები დროებით ვერ ჩაიტვირთა.';
        error_log('GARBALIA statistics expenses: ' . $e->getMessage());
    }
}

$productStats = [];
foreach ($items as $item) {
    $orderId = (int)$item['order_id'];
    if (!isset($orderMap[$orderId])) continue;
    $name = trim((string)$item['product_name']);
    if ($name === '') $name = 'პროდუქტი';
    if (!isset($productStats[$name])) {
        $productStats[$name] = ['product_name' => $name, 'qty' => 0.0, 'net_sales' => 0.0, 'cost_total' => 0.0];
    }
    $quantity = (float)$item['quantity'];
    $gross = $quantity * (float)$item['price'];
    $orderGrossTotal = (float)($orderGross[$orderId] ?? 0);
    // Service is not a product sale and must not inflate product profitability.
    $orderRevenue = (float)$orderMap[$orderId]['total'] - (float)$orderMap[$orderId]['service_amount'];
    $factor = $orderGrossTotal > 0 ? ($orderRevenue / $orderGrossTotal) : 0;
    $productStats[$name]['qty'] += $quantity;
    $productStats[$name]['net_sales'] += $gross * $factor;
    $productStats[$name]['cost_total'] += $quantity * (float)$item['product_cost'];
}
$topProducts = array_values($productStats);
usort($topProducts, function (array $a, array $b): int {
    $profitA = (float)$a['net_sales'] - (float)$a['cost_total'];
    $profitB = (float)$b['net_sales'] - (float)$b['cost_total'];
    if (abs($profitA - $profitB) > 0.0001) return $profitA < $profitB ? 1 : -1;
    if (abs((float)$a['qty'] - (float)$b['qty']) > 0.0001) return (float)$a['qty'] < (float)$b['qty'] ? 1 : -1;
    return strcmp((string)$a['product_name'], (string)$b['product_name']);
});
$topProducts = array_slice($topProducts, 0, 20);

$stmt = db()->query('SELECT id, name, sort_order FROM restaurant_tables WHERE is_active=1 ORDER BY sort_order,id');
$tableStats = [];
$tableIndex = [];
foreach ($stmt->fetchAll() as $table) {
    $tableId = (int)$table['id'];
    $tableIndex[$tableId] = count($tableStats);
    $tableStats[] = [
        'table_name' => $table['name'],
        'sort_order' => (int)$table['sort_order'],
        'orders_count' => 0,
        'total' => 0.0,
        'cost_total' => 0.0,
        'gross_profit' => 0.0,
    ];
}
foreach ($orders as $order) {
    $tableId = (int)$order['table_id'];
    if (!isset($tableIndex[$tableId])) continue;
    $index = $tableIndex[$tableId];
    $orderId = (int)$order['id'];
    $orderRevenue = (float)$order['total'];
    $currentCost = (float)($orderCost[$orderId] ?? 0);
    $tableStats[$index]['orders_count']++;
    $tableStats[$index]['total'] += $orderRevenue;
    $tableStats[$index]['cost_total'] += $currentCost;
    $tableStats[$index]['gross_profit'] += $orderRevenue - $currentCost;
}
usort($tableStats, function (array $a, array $b): int {
    if ((int)$a['orders_count'] !== (int)$b['orders_count']) return (int)$a['orders_count'] < (int)$b['orders_count'] ? 1 : -1;
    if (abs((float)$a['total'] - (float)$b['total']) > 0.0001) return (float)$a['total'] < (float)$b['total'] ? 1 : -1;
    return (int)$a['sort_order'] <=> (int)$b['sort_order'];
});

$ordersCount = count($orders);
$grossProfit = $revenue - $costTotal;
$grossMargin = $revenue > 0 ? ($grossProfit / $revenue * 100) : 0.0;
$netResult = $grossProfit - $expenses;
$averageCheck = $ordersCount > 0 ? $revenue / $ordersCount : 0.0;
$marginText = number_format($grossMargin, 1) . '%';

$todaySales = sales_between(date('Y-m-d'), date('Y-m-d'));
$yesterdaySales = sales_between(date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day')));
$monthSales = sales_between(date('Y-m-01'), date('Y-m-d'));
$prevMonthSales = sales_between(date('Y-m-01', strtotime('first day of previous month')), date('Y-m-t', strtotime('last day of previous month')));

$rangeUrl = function (string $value): string {
    return h(url_for('statistics', ['range' => $value]));
};

render_header('სტატისტიკა');
?>
<style>
.statistics-page{display:grid;gap:clamp(14px,1.7vw,22px);width:min(1440px,100%);margin:0 auto}.statistics-page *{min-width:0}.statistics-head{display:flex;align-items:flex-end;justify-content:space-between;gap:14px;flex-wrap:wrap}.statistics-head h1{margin:0}.statistics-sub{margin:6px 0 0;color:var(--muted);font-size:.88rem;font-weight:750;max-width:760px;line-height:1.45}.stats-range{display:flex;gap:7px;flex-wrap:wrap;align-items:center}.stats-range .btn{min-height:36px;padding:7px 10px;border-radius:11px;font-size:.82rem;line-height:1;white-space:nowrap}.stats-range .active{background:var(--green)!important}.finance-formula{display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;padding:11px 14px;border:1px solid rgba(43,27,16,.10);border-radius:15px;background:linear-gradient(135deg,rgba(255,255,255,.72),rgba(241,226,206,.58));color:#6d5140;font-size:.79rem;font-weight:850;text-align:center}.finance-formula b{color:#2b1b10}.profit-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.profit-kpi{position:relative;overflow:hidden;min-height:116px;padding:15px 16px;border-radius:19px;border:1px solid rgba(43,27,16,.08);background:linear-gradient(150deg,#382317,#25160e);color:#fff;box-shadow:0 14px 30px rgba(43,27,16,.12)}.profit-kpi:after{content:"";position:absolute;right:-28px;top:-30px;width:110px;height:100px;border-radius:50%;background:rgba(255,255,255,.045)}.profit-kpi span{display:block;position:relative;z-index:1;opacity:.72;font-size:.74rem;font-weight:850;line-height:1.25}.profit-kpi strong{display:block;position:relative;z-index:1;margin-top:7px;font-size:clamp(1.16rem,2vw,1.55rem);line-height:1.08;font-weight:950;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.profit-kpi small{display:block;position:relative;z-index:1;margin-top:6px;opacity:.62;font-size:.69rem;font-weight:750;line-height:1.3}.profit-kpi.cost{background:linear-gradient(150deg,#70451f,#4d2b13)}.profit-kpi.profit{background:linear-gradient(150deg,#2c8747,#1e6334)}.profit-kpi.result{background:linear-gradient(150deg,#2357a5,#183c72)}.profit-kpi.result.negative{background:linear-gradient(150deg,#bd3b31,#83241e)}.profit-kpi.light{background:linear-gradient(150deg,#fffaf2,#f0ddc3);color:#2b1b10;border-color:#e3c7a3}.profit-kpi.light:after{background:rgba(43,27,16,.035)}.profit-kpi.light span,.profit-kpi.light small{color:#7a6657;opacity:1}.cost-warning,.stats-warning{padding:11px 13px;border-radius:14px;border:1px solid #e6bd62;background:#fff5d9;color:#775000;font-size:.79rem;font-weight:850;line-height:1.45}.quick-sales-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px}.quick-sale{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 13px;border:1px solid var(--line);border-radius:15px;background:rgba(255,250,242,.86)}.quick-sale span{color:var(--muted);font-size:.75rem;font-weight:850}.quick-sale strong{font-size:.95rem;font-weight:950;white-space:nowrap}.statistics-stack{display:grid;gap:14px}.stat-card{padding:clamp(14px,1.7vw,20px)!important;border-radius:21px!important}.stat-card h2{font-size:1.03rem;margin:0 0 12px;text-align:center}.stat-card-note{margin:-5px auto 12px;max-width:760px;color:var(--muted);font-size:.75rem;font-weight:750;text-align:center;line-height:1.4}.stat-table{width:100%;border:0!important;overflow:auto!important;border-radius:15px}.stat-table table{width:100%;min-width:720px!important;table-layout:auto;border-collapse:separate;border-spacing:0;background:#fff;border:1px solid var(--line);border-radius:15px;overflow:hidden}.stat-table th,.stat-table td{padding:9px 10px;font-size:.82rem;line-height:1.25;vertical-align:middle;border-bottom:1px solid var(--line);white-space:normal;overflow-wrap:anywhere}.stat-table th{background:#ead9c4;font-size:.76rem}.stat-table tr:last-child td{border-bottom:0}.stat-rank{width:46px;color:var(--muted);font-weight:950}.stat-num{text-align:right;font-weight:950;white-space:nowrap!important}.profit-positive{color:#24733c}.profit-negative{color:#b9332a}.empty-stat{border:1px dashed var(--line);border-radius:15px;padding:14px;color:var(--muted);font-size:.88rem;font-weight:800;background:#fffaf2;text-align:center}@media(max-width:1050px){.profit-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.statistics-head{align-items:flex-start}.stats-range{width:100%}.stats-range .btn{flex:1 1 auto}}@media(max-width:760px){.quick-sales-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.stat-table table{min-width:680px!important}}@media(max-width:520px){.profit-grid,.quick-sales-grid{grid-template-columns:1fr}.profit-kpi{min-height:104px}.stats-range .btn{width:100%;font-size:.82rem}.statistics-sub{font-size:.82rem}.finance-formula{font-size:.75rem}.stat-card{padding:12px!important}.stat-table th,.stat-table td{font-size:.78rem;padding:8px}.stat-rank{width:34px}}
</style>
<section class="statistics-page">
  <div class="statistics-head">
    <div>
      <h1>სტატისტიკა</h1>
      <p class="statistics-sub">გაყიდვა, პროდუქტის თვითღირებულება და რეალური ფინანსური შედეგი არჩეული პერიოდის მიხედვით.</p>
    </div>
    <div class="stats-range">
      <a class="btn <?= $range==='today'?'active':'' ?>" href="<?= $rangeUrl('today') ?>">დღეს</a>
      <a class="btn <?= $range==='yesterday'?'active':'' ?>" href="<?= $rangeUrl('yesterday') ?>">გუშინ</a>
      <a class="btn <?= $range==='last7'?'active':'' ?>" href="<?= $rangeUrl('last7') ?>">ბოლო 7 დღე</a>
      <a class="btn <?= $range==='month'?'active':'' ?>" href="<?= $rangeUrl('month') ?>">ამ თვეში</a>
      <a class="btn <?= $range==='prev_month'?'active':'' ?>" href="<?= $rangeUrl('prev_month') ?>">წინა თვე</a>
      <a class="btn <?= $range==='year'?'active':'' ?>" href="<?= $rangeUrl('year') ?>">ამ წელს</a>
    </div>
  </div>

  <?php if ($setupWarnings): ?>
    <div class="stats-warning"><?= h(implode(' ', array_unique($setupWarnings))) ?></div>
  <?php endif; ?>

  <div class="finance-formula">
    <span><b>მთლიანი მოგება</b> = გაყიდვა − თვითღირებულება</span>
    <span>•</span>
    <span><b>საბოლოო შედეგი</b> = მთლიანი მოგება − დაფიქსირებული ხარჯები</span>
  </div>

  <section class="profit-grid">
    <div class="profit-kpi"><span>სულ მიღებული — <?= h($rangeLabel) ?></span><strong><?= money($revenue) ?></strong><small>ფასდაკლების შემდეგ, მომსახურების ჩათვლით</small></div>
    <div class="profit-kpi light"><span>პროდუქტების გაყიდვა</span><strong><?= money($revenue - $serviceTotal) ?></strong><small>ფასდაკლების შემდეგ, მომსახურების გარეშე</small></div>
    <div class="profit-kpi light"><span>მომსახურება</span><strong><?= money($serviceTotal) ?></strong><small>არჩეულ პერიოდში მიღებული მომსახურების თანხა</small></div>
    <div class="profit-kpi cost"><span>პროდუქტების თვითღირებულება</span><strong><?= money($costTotal) ?></strong><small>გაყიდული რაოდენობა × შენახული Cost</small></div>
    <div class="profit-kpi profit"><span>მთლიანი მოგება</span><strong><?= money($grossProfit) ?></strong><small>მარჟა: <?= h($marginText) ?></small></div>
    <div class="profit-kpi result <?= $netResult < 0 ? 'negative' : '' ?>"><span>საბოლოო შედეგი</span><strong><?= money($netResult) ?></strong><small>მოგება − სალაროში დაფიქსირებული ხარჯები</small></div>
    <div class="profit-kpi light"><span>დაფიქსირებული ხარჯები</span><strong><?= money($expenses) ?></strong><small>მხოლოდ „ხარჯი“ ტიპის სალაროს მოძრაობა</small></div>
    <div class="profit-kpi light"><span>ფასდაკლება</span><strong><?= money($discounts) ?></strong><small>არჩეულ პერიოდში დაკლებული თანხა</small></div>
    <div class="profit-kpi light"><span>დახურული ანგარიშები</span><strong><?= $ordersCount ?></strong><small>საშუალო ჩეკი: <?= money($averageCheck) ?></small></div>
    <div class="profit-kpi light"><span>მთლიანი მარჟა</span><strong><?= h($marginText) ?></strong><small>მთლიანი მოგება ÷ გაყიდვა</small></div>
  </section>

  <?php if ($zeroCostLines > 0): ?>
    <div class="cost-warning">⚠ არჩეულ პერიოდში <?= $zeroCostLines ?> გაყიდულ ჩანაწერს თვითღირებულება 0.00 ₾ აქვს. სანამ ყველა პროდუქტს Cost არ ექნება შევსებული, მოგება შეიძლება რეალურზე მეტი გამოჩნდეს.</div>
  <?php endif; ?>

  <section class="quick-sales-grid" aria-label="გაყიდვების სწრაფი შედარება">
    <div class="quick-sale"><span>დღეს</span><strong><?= money($todaySales['total']) ?></strong></div>
    <div class="quick-sale"><span>გუშინ</span><strong><?= money($yesterdaySales['total']) ?></strong></div>
    <div class="quick-sale"><span>ამ თვეში</span><strong><?= money($monthSales['total']) ?></strong></div>
    <div class="quick-sale"><span>წინა თვეში</span><strong><?= money($prevMonthSales['total']) ?></strong></div>
  </section>

  <section class="statistics-stack">
    <div class="card stat-card">
      <h2>პროდუქტების მოგება — <?= h($rangeLabel) ?></h2>
      <p class="stat-card-note">ფასდაკლება პროდუქტებზე ნაწილდება მათი გაყიდვის წილის მიხედვით. მომსახურება პროდუქტის გაყიდვასა და მოგებაში არ შედის.</p>
      <?php if (!$topProducts): ?>
        <div class="empty-stat">ამ პერიოდზე პროდუქტის გაყიდვა ჯერ არ არის.</div>
      <?php else: ?>
        <div class="stat-table"><table><thead><tr><th class="stat-rank">#</th><th>პროდუქტი</th><th class="stat-num">რაოდ.</th><th class="stat-num">გაყიდვა</th><th class="stat-num">Cost</th><th class="stat-num">მოგება</th><th class="stat-num">მარჟა</th></tr></thead><tbody>
        <?php foreach ($topProducts as $i => $p):
          $netSales = (float)$p['net_sales'];
          $productCost = (float)$p['cost_total'];
          $productProfit = $netSales - $productCost;
          $productMargin = $netSales > 0 ? ($productProfit / $netSales * 100) : 0;
        ?>
          <tr><td class="stat-rank">#<?= $i + 1 ?></td><td><?= h($p['product_name']) ?></td><td class="stat-num"><?= h(qty($p['qty'])) ?></td><td class="stat-num"><?= money($netSales) ?></td><td class="stat-num"><?= money($productCost) ?></td><td class="stat-num <?= $productProfit < 0 ? 'profit-negative' : 'profit-positive' ?>"><?= money($productProfit) ?></td><td class="stat-num <?= $productMargin < 0 ? 'profit-negative' : 'profit-positive' ?>"><?= h(number_format($productMargin, 1)) ?>%</td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>
    </div>

    <div class="card stat-card">
      <h2>მაგიდების შედეგი — <?= h($rangeLabel) ?></h2>
      <p class="stat-card-note">თითო მაგიდაზე ნაჩვენებია მიღებული თანხა მომსახურების ჩათვლით, პროდუქტის თვითღირებულება და მთლიანი მოგება.</p>
      <?php if (!$tableStats): ?>
        <div class="empty-stat">მაგიდები ვერ მოიძებნა.</div>
      <?php else: ?>
        <div class="stat-table"><table><thead><tr><th>მაგიდა</th><th class="stat-num">ანგარიში</th><th class="stat-num">გაყიდვა</th><th class="stat-num">Cost</th><th class="stat-num">მოგება</th><th class="stat-num">მარჟა</th></tr></thead><tbody>
        <?php foreach ($tableStats as $table):
          $tableRevenue = (float)$table['total'];
          $tableProfit = (float)$table['gross_profit'];
          $tableMargin = $tableRevenue > 0 ? ($tableProfit / $tableRevenue * 100) : 0;
        ?>
          <tr><td><?= h($table['table_name']) ?></td><td class="stat-num"><?= (int)$table['orders_count'] ?></td><td class="stat-num"><?= money($tableRevenue) ?></td><td class="stat-num"><?= money($table['cost_total']) ?></td><td class="stat-num <?= $tableProfit < 0 ? 'profit-negative' : 'profit-positive' ?>"><?= money($tableProfit) ?></td><td class="stat-num <?= $tableMargin < 0 ? 'profit-negative' : 'profit-positive' ?>"><?= h(number_format($tableMargin, 1)) ?>%</td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>
    </div>
  </section>
</section>
<?php render_footer();
