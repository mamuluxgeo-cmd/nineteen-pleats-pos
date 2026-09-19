<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/order-numbers.php';
require __DIR__ . '/includes/receipt-templates.php';

require_login();

$orderId = (int)($_GET['order_id'] ?? 0);
$tableId = (int)($_GET['table_id'] ?? 0);
$ids = array_values(array_filter(array_map('intval', explode(',', $_GET['item_ids'] ?? ''))));

$order = fetch_order($orderId);
$table = fetch_table($tableId);

if (!$order || !$table || !$ids || (int)$order['table_id'] !== $tableId) {
    flash('დასაბეჭდი შეკვეთა ვერ მოიძებნა.', 'warn');
    redirect_to('tables');
}

$receiptNumber = receipt_number_for_order($order);
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = db()->prepare('SELECT * FROM order_items WHERE order_id=? AND id IN (' . $placeholders . ') ORDER BY id ASC');
$stmt->execute(array_merge([$orderId], $ids));
$items = $stmt->fetchAll();

if (!$items) {
    flash('დასაბეჭდი პროდუქტები ვერ მოიძებნა.', 'warn');
    redirect_to('table', ['id' => $tableId]);
}

$barTemplate = receipt_template('bar');
$kitchenTemplate = receipt_template('kitchen');
$barReceipt = build_configurable_bar_receipt($table, $items, $receiptNumber);
$kitchenReceipt = build_configurable_kitchen_receipt($table, $items, $receiptNumber);

render_header('შეკვეთის ბეჭდვა');
?>
<style>
.dual-print-page{display:grid;gap:16px}.dual-print-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap}.dual-print-head h1{margin:0}.dual-print-note{margin:7px 0 0;color:var(--muted);font-size:.9rem;font-weight:800;max-width:760px}.dual-print-number{display:inline-flex;margin-top:9px;padding:7px 11px;border-radius:999px;background:#2b1b10;color:#fff;font-size:.84rem;font-weight:950}.dual-print-card{max-width:760px;margin:0 auto;width:100%;padding:18px!important}.dual-print-preview{display:grid;gap:0;border:1px solid var(--line);border-radius:18px;overflow:hidden;background:#fff}.dual-receipt-part{padding:18px}.dual-receipt-part h2{margin:0 0 12px;text-align:center;font-size:1.12rem;font-weight:950}.dual-receipt-part pre{margin:0;white-space:pre-wrap;word-break:break-word;font-family:Arial,"Noto Sans Georgian",sans-serif;line-height:1.45;color:#111}.dual-cut-line{height:14px;border-top:1px dashed #6d5140;border-bottom:1px dashed #6d5140;background:#f5e8d6}.dual-print-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:14px;flex-wrap:wrap}.dual-print-actions .btn{min-height:46px}.dual-print-main{background:#2357a5!important;color:#fff!important}@media(max-width:600px){.dual-print-card{padding:13px!important}.dual-receipt-part{padding:14px}.dual-print-actions,.dual-print-actions .btn{width:100%}}
</style>
<section class="dual-print-page">
  <div class="dual-print-head">
    <div>
      <h1>შეკვეთის ბეჭდვა</h1>
      <p class="dual-print-note">სარეზერვო ბეჭდვის გვერდი — ყოველდღიურ მუშაობაში ქვითრები უკვე პირდაპირ მაგიდის გვერდიდან იბეჭდება.</p>
      <span class="dual-print-number">ქვითრის ნომერი #<?= (int)$receiptNumber ?></span>
    </div>
    <a class="btn" href="<?= h(url_for('table', ['id'=>$tableId])) ?>">მაგიდაზე დაბრუნება</a>
  </div>

  <section class="card dual-print-card">
    <div id="dual_receipt_preview" class="dual-print-preview">
      <article class="dual-receipt-part"><pre id="bar_receipt_text" style="font-size:<?= (int)$barTemplate['font_size'] ?>px"><?= h($barReceipt) ?></pre></article>
      <div class="dual-cut-line" aria-hidden="true"></div>
      <article class="dual-receipt-part"><pre id="kitchen_receipt_text" style="font-size:<?= (int)$kitchenTemplate['font_size'] ?>px"><?= h($kitchenReceipt) ?></pre></article>
    </div>
    <div class="dual-print-actions"><button type="button" class="btn primary dual-print-main" id="print_both_receipts">ორივე ქვითრის ბეჭდვა</button></div>
  </section>
</section>
<script>
(function () {
  const button = document.getElementById('print_both_receipts');
  if (!button) return;
  function esc(value){return String(value).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
  button.addEventListener('click', function () {
    const bar = document.getElementById('bar_receipt_text');
    const kitchen = document.getElementById('kitchen_receipt_text');
    const win = window.open('', '_blank', 'width=460,height=760');
    if (!win) { alert('ბრაუზერმა ბეჭდვის ფანჯარა დაბლოკა. დაუშვი pop-up ამ საიტისთვის.'); return; }
    win.document.write('<!doctype html><html lang="ka"><head><meta charset="utf-8"><title>ქვითრები</title><style>@page{size:80mm auto;margin:3mm}html,body{margin:0;padding:0;background:#fff;color:#000}body{width:74mm;font-family:Arial,"Noto Sans Georgian",sans-serif}.receipt{box-sizing:border-box;width:100%;padding:0 0 3mm;break-after:page;page-break-after:always}.receipt:last-child{break-after:auto;page-break-after:auto}pre{margin:0;white-space:pre-wrap;word-break:break-word;font-family:Arial,"Noto Sans Georgian",sans-serif;line-height:1.35}</style></head><body><section class="receipt"><pre style="font-size:<?= (int)$barTemplate['font_size'] ?>px">'+esc(bar.innerText)+'</pre></section><section class="receipt"><pre style="font-size:<?= (int)$kitchenTemplate['font_size'] ?>px">'+esc(kitchen.innerText)+'</pre></section><script>window.onload=function(){window.print();};window.onafterprint=function(){window.close();};<\/script></body></html>');
    win.document.close();
  });
})();
</script>
<?php render_footer();
