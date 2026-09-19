<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Keep the administrator history page exactly as it already works.
if (($_SESSION['user']['role'] ?? '') === 'admin') {
    $_GET['page'] = 'history';
    require __DIR__ . '/index.php';
    exit;
}

require __DIR__ . '/includes/bootstrap.php';
require_login();

$today = date('Y-m-d');
$limitFrom = date('Y-m-d', strtotime('-6 days'));
$from = $_GET['from'] ?? $limitFrom;
$to = $_GET['to'] ?? $today;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $limitFrom;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = $today;
if ($from < $limitFrom) $from = $limitFrom;
if ($to > $today) $to = $today;
if ($from > $to) $from = $to;

$tableId = (int)($_GET['table_id'] ?? 0);
$productSearch = trim($_GET['product'] ?? '');
$viewOrderId = (int)($_GET['order_id'] ?? 0);
$sel = function ($a, $b): string { return (string)$a === (string)$b ? 'selected' : ''; };

$tables = db()->query('SELECT * FROM restaurant_tables WHERE is_active=1 ORDER BY sort_order, id')->fetchAll();
$where = ['o.status IN ("closed", "cancelled")', 'COALESCE(o.closed_at,o.created_at) BETWEEN ? AND ?'];
$params = [$from . ' 00:00:00', $to . ' 23:59:59'];
if ($tableId > 0) {
    $where[] = 'o.table_id=?';
    $params[] = $tableId;
}
if ($productSearch !== '') {
    $where[] = 'EXISTS (SELECT 1 FROM order_items pi WHERE pi.order_id=o.id AND pi.product_name LIKE ?)';
    $params[] = '%' . $productSearch . '%';
}

$sql = 'SELECT o.*, t.name table_name, u.name user_name FROM orders o JOIN restaurant_tables t ON t.id=o.table_id LEFT JOIN users u ON u.id=o.user_id WHERE ' . implode(' AND ', $where) . ' ORDER BY COALESCE(o.closed_at,o.created_at) DESC, o.id DESC LIMIT 300';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$detail = null;
if ($viewOrderId > 0) {
    $stmt = db()->prepare('SELECT o.*, t.name table_name, u.name user_name FROM orders o JOIN restaurant_tables t ON t.id=o.table_id LEFT JOIN users u ON u.id=o.user_id WHERE o.id=? AND o.status IN ("closed","cancelled") AND COALESCE(o.closed_at,o.created_at) BETWEEN ? AND ? LIMIT 1');
    $stmt->execute([$viewOrderId, $limitFrom . ' 00:00:00', $today . ' 23:59:59']);
    $detail = $stmt->fetch() ?: null;
}

render_header('ისტორია');
?>
<style>
.cashier-history{display:grid;gap:16px}.cashier-history *{min-width:0}.cashier-history-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap}.cashier-history-head h1{margin:0}.cashier-history-note{margin:6px 0 0;color:var(--muted);font-size:.88rem;font-weight:800}.cashier-history-limit{display:inline-flex;align-items:center;min-height:40px;padding:8px 12px;border-radius:999px;background:#fff3cd;color:#7a5000;font-size:.82rem;font-weight:950}.cashier-history-filters{padding:16px!important}.cashier-history-grid{display:grid;grid-template-columns:1fr 1fr minmax(150px,1fr) minmax(190px,1.2fr) auto;gap:10px;align-items:end}.cashier-history-grid label{margin:0}.cashier-history-grid .btn{min-height:46px}.cashier-history-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.cashier-history-actions .btn{min-height:38px;padding:8px 11px;font-size:.84rem}.cashier-history-table{overflow-x:auto;max-width:100%}.cashier-history-table table{min-width:720px}.cashier-history-table th,.cashier-history-table td{font-size:.86rem;white-space:normal;overflow-wrap:anywhere}.cashier-history-table .btn{padding:8px 10px;min-height:36px;font-size:.82rem;white-space:nowrap}.cashier-status{display:inline-flex;padding:5px 8px;border-radius:999px;font-size:.76rem;font-weight:950;white-space:nowrap}.cashier-status.paid{background:#e9ffe4;color:#24733c}.cashier-status.zero{background:#ffe5e2;color:#8b1d15}.cashier-history-detail{margin-bottom:0}.cashier-history-detail .page-head{align-items:center}.cashier-history-detail-actions{display:flex;gap:8px;flex-wrap:wrap}.cashier-reprint-button{background:#24733c!important;color:#fff!important;box-shadow:0 8px 18px rgba(36,115,60,.18)}.cashier-history-detail-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:9px}.cashier-history-detail-grid div{padding:10px;border:1px solid var(--line);border-radius:14px;background:#fff;overflow:hidden}.cashier-history-detail-grid span{display:block;color:var(--muted);font-size:.76rem;font-weight:800}.cashier-history-detail-grid strong{display:block;margin-top:4px;font-size:.88rem;line-height:1.2;overflow-wrap:anywhere}.cashier-history-empty{padding:18px;text-align:center;color:var(--muted);font-weight:850}@media(max-width:980px){.cashier-history-grid{grid-template-columns:1fr 1fr}.cashier-history-grid .btn{grid-column:1/-1}.cashier-history-detail-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:580px){.cashier-history-grid{grid-template-columns:1fr}.cashier-history-grid .btn{grid-column:auto}.cashier-history-actions .btn{flex:1 1 100%}.cashier-history-detail-grid{grid-template-columns:1fr 1fr}.cashier-history-detail-actions{width:100%}.cashier-history-detail-actions .btn{width:100%}.cashier-history-table table{min-width:620px}.cashier-history-table th,.cashier-history-table td{font-size:.78rem;padding:8px}}
</style>
<section class="cashier-history">
  <div class="cashier-history-head">
    <div>
      <h1>მაგიდების ისტორია</h1>
      <p class="cashier-history-note">მოლარის წვდომა — ბოლო 7 დღის ანგარიშების ნახვა და ქვითრის ხელახლა დაბეჭდვა.</p>
    </div>
    <span class="cashier-history-limit"><?= h($limitFrom) ?> — <?= h($today) ?></span>
  </div>

  <section class="card cashier-history-filters">
    <form method="get">
      <input type="hidden" name="page" value="history">
      <div class="cashier-history-grid">
        <label>თარიღიდან<input type="date" name="from" min="<?= h($limitFrom) ?>" max="<?= h($today) ?>" value="<?= h($from) ?>"></label>
        <label>თარიღამდე<input type="date" name="to" min="<?= h($limitFrom) ?>" max="<?= h($today) ?>" value="<?= h($to) ?>"></label>
        <label>მაგიდა<select name="table_id"><option value="0">ყველა მაგიდა</option><?php foreach ($tables as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $sel($tableId, $t['id']) ?>><?= h($t['name']) ?></option><?php endforeach; ?></select></label>
        <label>პროდუქტის ძებნა<input name="product" value="<?= h($productSearch) ?>" placeholder="მაგ: ხინკალი"></label>
        <button class="btn primary">ძებნა</button>
      </div>
    </form>
    <div class="cashier-history-actions">
      <a class="btn" href="<?= h(url_for('history', ['from'=>$today,'to'=>$today])) ?>">დღეს</a>
      <a class="btn" href="<?= h(url_for('history', ['from'=>date('Y-m-d', strtotime('-1 day')),'to'=>date('Y-m-d', strtotime('-1 day'))])) ?>">გუშინ</a>
      <a class="btn" href="<?= h(url_for('history', ['from'=>$limitFrom,'to'=>$today])) ?>">ბოლო 7 დღე</a>
    </div>
  </section>

<?php if ($detail):
    $items = order_items((int)$detail['id']);
    $receiptNumber = (int)($detail['receipt_number'] ?? 0) ?: (int)$detail['id'];
    $backParams = ['from'=>$from,'to'=>$to,'table_id'=>$tableId,'product'=>$productSearch];
?>
  <section class="card history-detail cashier-history-detail">
    <div class="page-head">
      <h2>ქვითარი #<?= $receiptNumber ?></h2>
      <div class="cashier-history-detail-actions">
        <?php if ($detail['status'] === 'closed'): ?><a class="btn success cashier-reprint-button" data-reprint-order="1" href="<?= h(url_for('print_final', ['order_id'=>(int)$detail['id'],'reprint'=>1])) ?>">ქვითრის ბეჭდვა</a><?php endif; ?>
        <a class="btn" href="<?= h(url_for('history', $backParams)) ?>">დახურვა</a>
      </div>
    </div>
    <div class="cashier-history-detail-grid">
      <div data-receipt-number-card="1"><span>ქვითრის ნომერი</span><strong>#<?= $receiptNumber ?></strong></div>
      <div><span>მაგიდა</span><strong><?= h($detail['table_name']) ?></strong></div>
      <div><span>სტატუსი</span><strong><?= h($detail['status']==='cancelled'?'ნულით დახურული':'დახურული') ?></strong></div>
      <div><span>მოლარე</span><strong><?= h($detail['user_name'] ?: '—') ?></strong></div>
      <div><span>გადახდა</span><strong><?= h($detail['status']==='cancelled'?'—':payment_label($detail['payment_type'])) ?></strong></div>
      <div><span>პროდუქტები — ფასდაკლების შემდეგ</span><strong><?= money((float)$detail['total'] - pos_service_amount($detail)) ?></strong></div>
      <div><span>მომსახურება</span><strong><?= money(pos_service_amount($detail)) ?></strong></div>
      <div><span>საბოლოო ჯამი</span><strong><?= money($detail['total']) ?></strong></div>
      <div><span>დრო</span><strong><?= h($detail['closed_at'] ?: $detail['created_at']) ?></strong></div>
    </div>
    <h3>პროდუქტები</h3>
    <div class="table-wrap cashier-history-table"><table><thead><tr><th>პროდუქტი</th><th>რაოდ.</th><th>ფასი</th><th>ჯამი</th><th>სტატუსი / მიზეზი</th></tr></thead><tbody>
      <?php foreach ($items as $it): $lineTotal=(float)$it['quantity']*(float)$it['price']; $itemStatus=(int)$it['is_cancelled']===1?'გაუქმებულია: '.($it['cancel_reason'] ?: '—'):'გაყიდულია'; ?>
      <tr><td><?= h($it['product_name']) ?></td><td><?= h(qty($it['quantity'])) ?></td><td><?= money($it['price']) ?></td><td><?= money($lineTotal) ?></td><td><?= h($itemStatus) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </section>
<?php else: ?>
  <section class="card">
    <h2>ბოლო 7 დღის ანგარიშები</h2>
    <div class="table-wrap cashier-history-table"><table><thead><tr><th>დრო</th><th>მაგიდა</th><th>მოლარე</th><th>ჯამი</th><th>გადახდა</th><th>სტატუსი</th><th>ნახვა</th></tr></thead><tbody>
      <?php if (!$orders): ?><tr><td colspan="7" class="cashier-history-empty">ამ ფილტრით ანგარიში ვერ მოიძებნა.</td></tr><?php endif; ?>
      <?php foreach ($orders as $o): $isZero=$o['status']==='cancelled'; ?>
      <tr>
        <td><?= h(date('m-d H:i', strtotime($o['closed_at'] ?: $o['created_at']))) ?></td>
        <td><?= h($o['table_name']) ?></td>
        <td><?= h($o['user_name'] ?: '—') ?></td>
        <td><?= money($o['total']) ?></td>
        <td><?= h($isZero?'—':payment_label($o['payment_type'])) ?></td>
        <td><span class="cashier-status <?= $isZero?'zero':'paid' ?>"><?= $isZero?'ნულით':'დახურული' ?></span></td>
        <td><a class="btn" href="<?= h(url_for('history', ['from'=>$from,'to'=>$to,'table_id'=>$tableId,'product'=>$productSearch,'order_id'=>(int)$o['id']])) ?>">ნახვა</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </section>
<?php endif; ?>
</section>
<?php render_footer();
