document.addEventListener('DOMContentLoaded', function () {
  injectGarbaliaWorkflowStyles();
  markOrderItemStates();
  cleanupOrderDeleteControls();
  addUnsentQuantityEditors();
  enhanceMixedPaymentAutoFill();
  enhanceDayCashMovementPanel();
  enhanceHistoryPage();
});

function injectGarbaliaWorkflowStyles() {
  if (document.getElementById('garbalia-workflow-style')) return;
  const style = document.createElement('style');
  style.id = 'garbalia-workflow-style';
  style.textContent = `
    .table-card.pending{background:linear-gradient(135deg,#fff7df 0%,#ffe6aa 100%)!important;border-color:#d99a26!important}
    .table-card.pending strong{color:#6b3f00;background:rgba(255,255,255,.76)!important}
    .current-order-card .order-item{position:relative!important;padding:11px 52px 11px 12px!important;margin-bottom:10px!important;border-radius:16px!important;box-shadow:0 7px 16px rgba(43,27,16,.055)!important}
    .current-order-card .order-item.sent-item{padding-right:12px!important}
    .current-order-card .order-item>div:first-child{padding:0!important}
    .current-order-card .order-item strong{font-size:.98rem!important;line-height:1.2!important}
    .current-order-card .order-item small{font-size:.82rem!important;line-height:1.25!important}
    .order-item.unsent-item{border-left:6px solid #d99020!important;background:linear-gradient(90deg,rgba(255,243,205,.78),#fffaf2 55%)!important}
    .order-item.sent-item{border-left:6px solid #24733c!important;background:linear-gradient(90deg,rgba(233,255,228,.78),#fffaf2 55%)!important}
    .unsent-text{display:inline-flex!important;width:max-content!important;margin-top:6px!important;border-radius:999px!important;background:#fff3cd!important;color:#8a5600!important;font-weight:950!important;padding:4px 9px!important}
    .sent{display:inline-flex!important;width:max-content!important;margin-top:6px!important;border-radius:999px!important;background:#e9ffe4!important;color:#24733c!important;font-weight:950!important;padding:4px 9px!important}
    .order-delete-form{position:absolute!important;right:12px!important;top:50%!important;transform:translateY(-50%)!important;margin:0!important;display:block!important;width:auto!important;min-width:0!important;z-index:2!important}
    .order-delete-form .item-delete-x{width:32px!important;height:32px!important;min-height:32px!important;border:0!important;border-radius:50%!important;background:#2b1b10!important;color:#fff!important;font-size:20px!important;line-height:1!important;font-weight:950!important;display:grid!important;place-items:center!important;padding:0!important;box-shadow:0 8px 18px rgba(43,27,16,.18)!important;cursor:pointer!important}
    .order-delete-form .item-delete-x:hover{background:#b7352d!important;transform:scale(1.04)!important}
    .order-qty-edit{display:inline-grid!important;grid-template-columns:auto 64px 34px!important;align-items:center!important;gap:7px!important;margin-top:8px!important;width:max-content!important;max-width:100%!important;background:rgba(255,255,255,.70)!important;border:1px solid rgba(217,144,32,.30)!important;border-radius:13px!important;padding:6px!important}
    .order-qty-edit span{font-weight:950!important;font-size:.78rem!important;color:#8a5600!important}.order-qty-edit input{width:64px!important;height:34px!important;min-height:34px!important;text-align:center!important;border-radius:10px!important;padding:0 6px!important}.order-qty-edit button{height:34px!important;min-height:34px!important;width:34px!important;border-radius:10px!important;padding:0!important;background:#24733c!important;color:#fff!important;border:0!important;font-weight:950!important;cursor:pointer!important}
    .garbalia-confirm-info .order-line strong{white-space:normal!important;text-align:right}
    .garbalia-cash-actions-panel{margin:18px 0!important;padding:18px 20px!important;border-radius:24px!important;display:flex!important;align-items:center!important;justify-content:space-between!important;gap:18px!important;background:linear-gradient(135deg,rgba(255,250,242,.97),rgba(241,226,206,.76))!important;box-shadow:0 18px 42px rgba(43,27,16,.10)!important}
    .garbalia-cash-panel-copy{display:flex;align-items:center;gap:14px;min-width:0}.garbalia-cash-panel-copy h2{margin:0;font-size:1.12rem;font-weight:950;color:#2b1b10}.garbalia-cash-panel-icon{display:grid;place-items:center;width:46px;height:46px;border-radius:16px;background:#2b1b10;color:#fff;font-size:1.2rem;font-weight:950;box-shadow:0 10px 22px rgba(43,27,16,.16)}.garbalia-cash-panel-actions{display:flex;gap:10px;align-items:center;justify-content:flex-end;flex-wrap:wrap}.garbalia-cash-panel-actions .btn{min-height:46px;border-radius:15px!important;white-space:nowrap!important}.garbalia-day-close-card{margin-top:16px!important;margin-bottom:18px!important;max-width:640px!important}
    .garbalia-cash-modal[hidden],.garbalia-close-modal[hidden]{display:none!important}.garbalia-cash-modal,.garbalia-close-modal{position:fixed;inset:0;z-index:10020;display:grid;place-items:center;padding:20px;background:rgba(43,27,16,.46);backdrop-filter:blur(7px);animation:garbaliaFadeIn .16s ease-out}.garbalia-cash-dialog,.garbalia-close-dialog{position:relative;width:min(520px,100%);max-height:min(88vh,760px);overflow:auto;border:1px solid #ead6bd;border-radius:28px;background:linear-gradient(180deg,#fffaf2 0%,#f8ecdd 100%);box-shadow:0 28px 70px rgba(43,27,16,.30);padding:28px;color:#2b1b10;text-align:center;animation:garbaliaPopIn .18s ease-out}.garbalia-cash-history-dialog{width:min(760px,100%);text-align:left;overflow-y:auto!important;overflow-x:hidden!important}.garbalia-cash-bg-logo,.garbalia-close-bg-logo{position:absolute;right:-18px;top:-16px;width:136px;height:88px;object-fit:contain;opacity:.07;filter:brightness(0);pointer-events:none}.garbalia-cash-mini,.garbalia-close-mini{width:48px;height:34px;object-fit:contain;margin:0 auto 10px;display:block;mix-blend-mode:multiply}.garbalia-cash-dialog h2,.garbalia-cash-dialog h3,.garbalia-close-dialog h3{margin:0 0 8px;font-size:1.35rem;font-weight:950;letter-spacing:-.02em}.garbalia-cash-dialog p,.garbalia-close-dialog p{margin:0 auto 18px;max-width:390px;color:#6d5140;font-weight:800;line-height:1.45}.garbalia-cash-close,.garbalia-close-x{position:absolute;right:12px;top:12px;width:34px;height:34px;border:0;border-radius:50%;background:rgba(43,27,16,.08);color:#2b1b10;font-size:20px;font-weight:900;cursor:pointer}
    .garbalia-cash-popup-form{display:grid!important;gap:13px!important;text-align:left}.garbalia-cash-popup-form label{margin:0!important}.garbalia-cash-popup-form input,.garbalia-cash-popup-form select{min-height:48px!important;border-radius:14px!important}.garbalia-cash-popup-form .btn{min-height:48px!important;border-radius:14px!important;width:100%!important}.garbalia-cash-history-dialog .table-wrap{margin-top:12px;max-height:52vh;overflow-y:auto!important;overflow-x:hidden!important;width:100%!important}.garbalia-cash-history-dialog table{width:100%!important;min-width:0!important;table-layout:fixed!important}.garbalia-cash-history-dialog th,.garbalia-cash-history-dialog td{white-space:normal!important;word-break:break-word!important;overflow-wrap:anywhere!important}
    .garbalia-close-summary{display:grid;gap:8px;margin:12px 0 16px}.garbalia-close-summary div{display:flex;justify-content:space-between;gap:12px;padding:10px 12px;border-radius:14px;background:rgba(255,255,255,.62);border:1px solid rgba(43,27,16,.09);text-align:left}.garbalia-close-summary span{color:#7a6657;font-weight:850}.garbalia-close-summary strong{font-weight:950;color:#2b1b10;text-align:right}.garbalia-close-list{max-height:160px;overflow:auto;text-align:left;border:1px solid rgba(43,27,16,.09);border-radius:16px;background:rgba(255,255,255,.48);padding:8px;margin-bottom:14px}.garbalia-close-list div{display:flex;justify-content:space-between;gap:10px;padding:8px 6px;border-bottom:1px solid rgba(43,27,16,.08);font-weight:850}.garbalia-close-list div:last-child{border-bottom:0}.garbalia-discount-box{margin:10px 0 14px;padding:12px;border-radius:18px;background:rgba(241,226,206,.58);text-align:left}.garbalia-discount-toggle{display:flex;align-items:center;gap:9px;font-weight:950;cursor:pointer}.garbalia-discount-controls{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px}.garbalia-discount-controls[hidden]{display:none!important}.garbalia-discount-controls input,.garbalia-discount-controls select{min-height:44px!important;border-radius:13px!important}.garbalia-close-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px}.garbalia-close-actions .btn{width:100%;min-height:46px;border-radius:14px}.garbalia-close-actions .btn.light{background:#f1e2ce!important;color:#2b1b10!important}.history-filters .history-grid{grid-template-columns:repeat(4,minmax(150px,1fr))!important}.history-clean-actions{display:flex!important;align-items:center!important;gap:6px!important;flex-wrap:nowrap!important;margin-top:12px!important;overflow:visible!important}.history-clean-actions .btn{white-space:nowrap!important;font-size:.86rem!important;padding:9px 12px!important;min-height:40px!important;border-radius:12px!important}.history-detail.only-detail{max-width:980px;margin:0 auto 22px!important}.history-detail.only-detail .page-head{margin-bottom:14px!important}.history-detail.only-detail h3{margin-top:20px!important}.history-detail.only-detail .table-wrap{margin-top:10px!important}
    .garbalia-close-summary div[hidden]{display:none!important}
    @media(max-width:1050px){.history-clean-actions{flex-wrap:wrap!important}.history-clean-actions .btn{font-size:.85rem!important}}
    @media(max-width:820px){.current-order-card .order-item{padding:12px 50px 12px 12px!important}.current-order-card .order-item.sent-item{padding-right:12px!important}.garbalia-cash-actions-panel{align-items:stretch!important;flex-direction:column!important}.garbalia-cash-panel-actions{display:grid;grid-template-columns:1fr;width:100%}.garbalia-cash-panel-actions .btn{width:100%!important}.history-filters .history-grid{grid-template-columns:1fr 1fr!important}.history-clean-actions{flex-wrap:wrap!important}}
    @media(max-width:560px){.history-filters .history-grid{grid-template-columns:1fr!important}.history-clean-actions .btn{width:100%!important}.garbalia-close-actions,.garbalia-discount-controls{grid-template-columns:1fr}.order-qty-edit{grid-template-columns:auto 56px 32px!important}.order-qty-edit input{width:56px!important}}
  `;
  document.head.appendChild(style);
}

function parseMoney(text) {
  let cleaned = String(text == null ? '' : text).replace(/[^0-9,.\-]/g, '');
  if (cleaned.includes(',') && cleaned.includes('.')) {
    cleaned = cleaned.lastIndexOf(',') > cleaned.lastIndexOf('.')
      ? cleaned.replace(/\./g, '').replace(',', '.')
      : cleaned.replace(/,/g, '');
  } else if (cleaned.includes(',')) {
    cleaned = /^-?\d{1,3}(,\d{3})+$/.test(cleaned) ? cleaned.replace(/,/g, '') : cleaned.replace(',', '.');
  }
  const n = Number(cleaned);
  return Number.isFinite(n) ? n : 0;
}

function formatMoney(n) {
  return (Number(n) || 0).toFixed(2) + ' ₾';
}

function moneyToCents(value) {
  const number = parseMoney(value);
  const parts = Math.abs(number).toFixed(6).split('.');
  const fraction = parts[1] || '000000';
  const cents = Number(parts[0]) * 100 + Number(fraction.slice(0, 2)) + (Number(fraction[2]) >= 5 ? 1 : 0);
  return number < 0 ? -cents : cents;
}

// Integer cents mirror the server: round discount first, then 10% of the remainder.
function calculateCloseTotals(subtotal, discountType, discountValue, serviceRate) {
  const cents = Math.max(0, moneyToCents(subtotal));
  const value = Math.max(0, moneyToCents(discountValue));
  let discount = 0;
  if (discountType === 'percent') discount = Math.floor((cents * Math.min(10000, value) + 5000) / 10000);
  if (discountType === 'amount') discount = Math.min(cents, value);
  const net = Math.max(0, cents - discount);
  const service = Number(serviceRate) === 10 ? Math.round(net / 10) : 0;
  return {subtotal: cents / 100, discount: discount / 100, service: service / 100, total: (net + service) / 100};
}

function closePricingContext(form) {
  const box = document.querySelector('.total-box[data-subtotal]');
  const head = document.querySelector('.page-head[data-service-rate]');
  const subtotal = box ? Number(box.dataset.subtotal) : NaN;
  const rateText = form && form.hasAttribute('data-service-rate') ? form.dataset.serviceRate : (head ? head.dataset.serviceRate : '');
  if (!Number.isFinite(subtotal) || subtotal < 0 || !['0', '10'].includes(rateText)) {
    throw new Error('საბოლოო თანხის დასათვლელად განაახლე გვერდი.');
  }
  return {subtotal: subtotal, serviceRate: Number(rateText)};
}

window.GarbaliaMoney = {parse: parseMoney, format: formatMoney, calculate: calculateCloseTotals, toCents: moneyToCents};

function markOrderItemStates() {
  document.querySelectorAll('.order-item:not(.cancelled)').forEach(function (item) {
    if (item.querySelector('.sent')) item.classList.add('sent-item');
    if (item.querySelector('.unsent-text') || !item.querySelector('.sent')) item.classList.add('unsent-item');
  });
}

function cleanupOrderDeleteControls() {
  document.querySelectorAll('.current-order-card .order-item:not(.cancelled)').forEach(function (item) {
    const form = item.querySelector('form.cancel-form');
    if (!form) return;
    if (item.classList.contains('sent-item') || item.querySelector('.sent')) {
      form.remove();
      return;
    }
    form.className = 'order-delete-form';
    Array.from(form.children).forEach(function (child) {
      const tag = child.tagName ? child.tagName.toLowerCase() : '';
      const name = child.name || '';
      const isHiddenKeep = tag === 'input' && child.type === 'hidden' && ['action', 'table_id', 'item_id'].includes(name);
      const isButton = tag === 'button';
      if (!isHiddenKeep && !isButton) child.remove();
    });
    const button = form.querySelector('button');
    if (button) {
      button.className = 'item-delete-x';
      button.type = 'submit';
      button.textContent = '×';
      button.title = 'სიიდან წაშლა';
      button.setAttribute('aria-label', 'სიიდან წაშლა');
    }
  });
}

function addUnsentQuantityEditors() {
  document.querySelectorAll('.current-order-card .order-item.unsent-item').forEach(function (item) {
    if (item.querySelector('.order-qty-edit')) return;
    const deleteForm = item.querySelector('.order-delete-form');
    if (!deleteForm) return;
    const tableInput = deleteForm.querySelector('input[name="table_id"]');
    const itemInput = deleteForm.querySelector('input[name="item_id"]');
    const title = item.querySelector('strong') ? item.querySelector('strong').textContent.trim() : '';
    const currentQty = Math.max(1, parseInt(title.split('x')[0], 10) || 1);
    const form = document.createElement('form');
    form.className = 'order-qty-edit';
    form.method = 'post';
    form.innerHTML = '<input type="hidden" name="action" value="update_item_quantity"><input type="hidden" name="table_id" value="' + (tableInput ? tableInput.value : '') + '"><input type="hidden" name="item_id" value="' + (itemInput ? itemInput.value : '') + '"><span>რაოდ.</span><input name="quantity" type="number" min="1" step="1" value="' + currentQty + '"><button type="submit" title="რაოდენობის განახლება">✓</button>';
    const info = item.querySelector('div:first-child');
    if (info) info.appendChild(form);
  });
}

function enhanceMixedPaymentAutoFill() {
  const form = document.querySelector('.close-form');
  if (!form || form.dataset.mixedAuto === '1') return;
  form.dataset.mixedAuto = '1';
  const type = form.querySelector('select[name="payment_type"]');
  const cash = form.querySelector('input[name="cash_amount"]');
  const card = form.querySelector('input[name="card_amount"]');
  if (!type || !cash || !card) return;
  let lastEdited = 'cash';
  function total() {
    const pricing = closePricingContext(form);
    return calculateCloseTotals(pricing.subtotal, 'none', 0, pricing.serviceRate).total;
  }
  function sync(changed) {
    if (type.value !== 'mixed') return;
    lastEdited = changed || lastEdited;
    let t;
    try { t = total(); } catch (error) { return; }
    if (lastEdited === 'cash') card.value = Math.max(0, t - parseMoney(cash.value)).toFixed(2);
    else cash.value = Math.max(0, t - parseMoney(card.value)).toFixed(2);
  }
  type.addEventListener('change', function () {
    if (type.value === 'mixed') {
      if (!cash.value && !card.value) cash.value = '0.00';
      sync(lastEdited);
    }
  });
  cash.addEventListener('input', function () { sync('cash'); });
  card.addEventListener('input', function () { sync('card'); });
  document.addEventListener('garbalia:order-updated', function () { sync(lastEdited); });
}

function enhanceDayCashMovementPanel() {
  const layout = document.querySelector('.day-cash-layout');
  if (!layout || layout.dataset.garbaliaEnhanced === '1') return;
  layout.dataset.garbaliaEnhanced = '1';
  const cards = Array.from(layout.querySelectorAll('.card'));
  const cashCard = cards[0];
  const historyCard = cards[1];
  const form = cashCard ? cashCard.querySelector('form') : null;
  if (!form || !historyCard) return;
  const historyHtml = historyCard.innerHTML;
  const movementCount = historyCard.querySelectorAll('tbody tr').length;
  const panel = document.createElement('section');
  panel.className = 'card garbalia-cash-actions-panel';
  panel.innerHTML = '<div class="garbalia-cash-panel-copy"><span class="garbalia-cash-panel-icon">₾</span><div><h2>სალაროს მოძრაობა</h2></div></div><div class="garbalia-cash-panel-actions"><button type="button" class="btn success" data-cash-open>თანხის მოძრაობის დამატება</button><button type="button" class="btn" data-cash-history>მოძრაობის ნახვა' + (movementCount ? ' · ' + movementCount : '') + '</button></div>';
  layout.insertAdjacentElement('beforebegin', panel);
  const movementModal = document.createElement('div');
  movementModal.id = 'garbalia-cash-movement-modal';
  movementModal.className = 'garbalia-cash-modal';
  movementModal.hidden = true;
  movementModal.innerHTML = '<div class="garbalia-cash-dialog" role="dialog" aria-modal="true"><button type="button" class="garbalia-cash-close" data-cash-close aria-label="დახურვა">×</button><img class="garbalia-cash-bg-logo" src="/Logo.png?v=12" alt=""><img class="garbalia-cash-mini" src="/Logo.png?v=12" alt="GARBALIA"><h3>სალაროს მოძრაობა</h3><p>აირჩიე ტიპი, ჩაწერე თანხა და საჭიროების შემთხვევაში კომენტარი.</p><div class="garbalia-cash-form-holder"></div></div>';
  movementModal.querySelector('.garbalia-cash-form-holder').appendChild(form);
  form.classList.add('garbalia-cash-popup-form');
  const submit = form.querySelector('button[type="submit"], button:not([type])');
  if (submit) submit.textContent = 'დამატება';
  document.body.appendChild(movementModal);
  const historyModal = document.createElement('div');
  historyModal.id = 'garbalia-cash-history-modal';
  historyModal.className = 'garbalia-cash-modal';
  historyModal.hidden = true;
  historyModal.innerHTML = '<div class="garbalia-cash-dialog garbalia-cash-history-dialog" role="dialog" aria-modal="true"><button type="button" class="garbalia-cash-close" data-cash-close aria-label="დახურვა">×</button><img class="garbalia-cash-bg-logo" src="/Logo.png?v=12" alt=""><img class="garbalia-cash-mini" src="/Logo.png?v=12" alt="GARBALIA">' + historyHtml + '</div>';
  document.body.appendChild(historyModal);
  layout.remove();
  const closeDayCard = Array.from(document.querySelectorAll('.card.narrow')).find(function (card) {
    const h2 = card.querySelector('h2');
    return h2 && h2.textContent.includes('დღის დახურვა');
  });
  if (closeDayCard) closeDayCard.classList.add('garbalia-day-close-card');
  panel.querySelector('[data-cash-open]').addEventListener('click', function () { openCashModal(movementModal); const amount = movementModal.querySelector('input[name="amount"]'); if (amount) setTimeout(function () { amount.focus(); }, 80); });
  panel.querySelector('[data-cash-history]').addEventListener('click', function () { openCashModal(historyModal); });
  document.querySelectorAll('[data-cash-close]').forEach(function (btn) { btn.addEventListener('click', function () { closeCashModal(btn.closest('.garbalia-cash-modal')); }); });
  [movementModal, historyModal].forEach(function (modal) { modal.addEventListener('click', function (event) { if (event.target === modal) closeCashModal(modal); }); });
}

function openCashModal(modal) { if (modal) modal.hidden = false; }
function closeCashModal(modal) { if (modal) modal.hidden = true; }

document.addEventListener('keydown', function (event) {
  if (event.key !== 'Escape') return;
  document.querySelectorAll('.garbalia-cash-modal:not([hidden]),.garbalia-close-modal:not([hidden])').forEach(function (modal) {
    if (modal.garbaliaDismiss) modal.garbaliaDismiss();
    else modal.hidden = true;
  });
});

function isHistoryPage() {
  const params = new URLSearchParams(window.location.search);
  const path = window.location.pathname.replace(/^\/+|\/+$/g, '');
  return params.get('page') === 'history' || path === 'history';
}
function formatDateForUrl(date) { return date.getFullYear() + '-' + String(date.getMonth()+1).padStart(2,'0') + '-' + String(date.getDate()).padStart(2,'0'); }
function historyUrl(from, to) {
  const params = new URLSearchParams(window.location.search);
  ['page','order_id','payment','status','item_filter','product','export'].forEach(function (key) { params.delete(key); });
  params.set('from', from); params.set('to', to);
  const query = params.toString();
  return '/history' + (query ? '?' + query : '');
}
function removeHistoryFilterByLabel(text) {
  document.querySelectorAll('.history-filters label').forEach(function (label) {
    const ownText = Array.from(label.childNodes).filter(function (node) { return node.nodeType === Node.TEXT_NODE; }).map(function (node) { return node.textContent.trim(); }).join(' ');
    if (ownText.includes(text) || label.textContent.trim().startsWith(text)) label.remove();
  });
}
function simplifyHistoryListTable() {
  const cards = Array.from(document.querySelectorAll('section.card'));
  const listCard = cards.find(function (card) { const h2 = card.querySelector('h2'); return h2 && h2.textContent.trim().includes('ანგარიშების სია'); });
  if (!listCard) return;
  const table = listCard.querySelector('table');
  if (!table || table.dataset.garbaliaHistoryClean === '1') return;
  table.dataset.garbaliaHistoryClean = '1';
  const headRow = table.querySelector('thead tr');
  if (headRow && headRow.children.length >= 9) {
    const heads = Array.from(headRow.children); headRow.innerHTML = ''; [heads[7], heads[0], heads[2], heads[3], heads[4], heads[5], heads[6], heads[8]].forEach(function (cell) { headRow.appendChild(cell); });
  }
  table.querySelectorAll('tbody tr').forEach(function (row) { const cells = Array.from(row.children); if (cells.length < 9) return; row.innerHTML = ''; [cells[7], cells[0], cells[2], cells[3], cells[4], cells[5], cells[6], cells[8]].forEach(function (cell) { row.appendChild(cell); }); });
}
function enhanceHistoryPage() {
  if (!isHistoryPage()) return;
  const params = new URLSearchParams(window.location.search);
  if (params.get('order_id')) {
    document.querySelectorAll('.history-filters, .stats').forEach(function (el) { el.remove(); });
    const mainHead = document.querySelector('.wrap > .page-head'); if (mainHead) mainHead.remove();
    Array.from(document.querySelectorAll('section.card')).forEach(function (card) { const h2 = card.querySelector('h2'); if (h2 && h2.textContent.trim().includes('ანგარიშების სია')) card.remove(); });
    const detail = document.querySelector('.history-detail');
    if (detail) { detail.classList.add('only-detail'); const back = detail.querySelector('.page-head .btn'); if (back) { back.href = '/history'; back.textContent = 'ისტორიაში დაბრუნება'; } }
    return;
  }
  ['პროდუქტის ძებნა','გადახდა','სტატუსი','პროდუქტები'].forEach(removeHistoryFilterByLabel);
  const actions = document.querySelector('.history-actions');
  if (actions && !actions.dataset.garbaliaExtraFilters) {
    actions.dataset.garbaliaExtraFilters = '1'; actions.classList.add('history-clean-actions');
    const searchBtn = document.querySelector('.history-filters form button[type="submit"], .history-filters form button.btn.primary');
    if (searchBtn) { searchBtn.classList.add('primary'); searchBtn.textContent = 'ძებნა'; actions.insertBefore(searchBtn, actions.firstChild); }
    const today = new Date(); const last7 = new Date(today); last7.setDate(today.getDate() - 6); const prevMonthStart = new Date(today.getFullYear(), today.getMonth()-1, 1); const prevMonthEnd = new Date(today.getFullYear(), today.getMonth(), 0); const yearStart = new Date(today.getFullYear(), 0, 1);
    const buttons = [['წინა თვეში', historyUrl(formatDateForUrl(prevMonthStart), formatDateForUrl(prevMonthEnd))], ['ამ წელში', historyUrl(formatDateForUrl(yearStart), formatDateForUrl(today))], ['ბოლო 7 დღეში', historyUrl(formatDateForUrl(last7), formatDateForUrl(today))]];
    const excel = actions.querySelector('a[href*="export=excel"]');
    buttons.forEach(function (item) { const a = document.createElement('a'); a.className = 'btn'; a.href = item[1]; a.textContent = item[0]; if (excel) actions.insertBefore(a, excel); else actions.appendChild(a); });
  }
  const pageHint = document.querySelector('.wrap > .page-head .muted'); if (pageHint) pageHint.textContent = 'აირჩიე პერიოდი, მაგიდა ან მომხმარებელი — შემდეგ გახსენი კონკრეტული ანგარიში.';
  simplifyHistoryListTable();
}

function activeOrderItems() { return Array.from(document.querySelectorAll('.order-item:not(.cancelled)')); }
function unsentOrderItems() { return activeOrderItems().filter(function (item) { return !item.querySelector('.sent'); }); }
function itemTitle(item, fallback) { return item && item.querySelector('strong') ? item.querySelector('strong').textContent.trim() : fallback; }
function itemSum(item) { const sumLine = item && item.querySelector('small') ? item.querySelector('small').textContent.trim().replace(/\s+/g, ' ') : ''; return sumLine.replace(/^.*ჯამი:\s*/u, '').trim(); }

function closeModal(modal) { if (modal) modal.hidden = true; }
function setHidden(form, name, value) {
  let input = form.querySelector('input[name="' + name + '"]');
  if (!input) { input = document.createElement('input'); input.type = 'hidden'; input.name = name; form.appendChild(input); }
  input.value = value;
}

function showDiscountCloseModal(form) {
  let pricing;
  try { pricing = closePricingContext(form); } catch (error) { alert(error.message); return; }
  const subtotal = pricing.subtotal;
  document.querySelectorAll('.garbalia-close-modal').forEach(function (oldModal) {
    if (oldModal.garbaliaDismiss) oldModal.garbaliaDismiss();
    else oldModal.remove();
  });
  const paymentSelect = form.querySelector('select[name="payment_type"]');
  const paymentText = paymentSelect ? paymentSelect.options[paymentSelect.selectedIndex].text.trim() : '—';
  const paymentType = paymentSelect ? paymentSelect.value : 'cash';
  const tableTitle = document.querySelector('.page-head h1') ? document.querySelector('.page-head h1').textContent.trim() : 'მაგიდა';
  const items = activeOrderItems();
  const cashInput = form.querySelector('input[name="cash_amount"]');
  const cardInput = form.querySelector('input[name="card_amount"]');

  const modal = document.createElement('div');
  modal.className = 'garbalia-close-modal';
  modal.innerHTML = '<div class="garbalia-close-dialog" role="dialog" aria-modal="true"><button type="button" class="garbalia-close-x" data-close-modal>×</button><img class="garbalia-close-bg-logo" src="/Logo.png?v=12" alt=""><img class="garbalia-close-mini" src="/Logo.png?v=12" alt="GARBALIA"><h3>მაგიდის დახურვა</h3><p>გადაამოწმე შეკვეთის სია, გადახდა და ფასდაკლება. დადასტურების შემდეგ მაგიდა დაიხურება.</p><div class="garbalia-close-summary"><div><span>მაგიდა</span><strong data-table-title></strong></div><div><span>გადახდა</span><strong data-payment-title></strong></div><div><span>ქვეჯამი</span><strong data-subtotal></strong></div><div data-discount-line hidden><span>ფასდაკლება</span><strong data-discount-amount>-0.00 ₾</strong></div><div data-service-line><span>მომსახურება 10%</span><strong data-service-amount></strong></div><div><span>საბოლოო ჯამი</span><strong data-final-total></strong></div><div data-cash-line hidden><span>ნაღდი</span><strong data-cash-total></strong></div><div data-card-line hidden><span>ბარათი</span><strong data-card-total></strong></div></div><div class="garbalia-close-list"></div><div class="garbalia-discount-box"><label class="garbalia-discount-toggle"><input type="checkbox" data-discount-enabled> ფასდაკლება</label><div class="garbalia-discount-controls" hidden><select data-discount-type><option value="percent">პროცენტული %</option><option value="amount">თანხობრივი ₾</option></select><input type="number" min="0" step="0.01" data-discount-value placeholder="მაგ: 10"></div></div><div class="garbalia-close-actions"><button type="button" class="btn light" data-close-modal>გაუქმება</button><button type="button" class="btn success" data-confirm-close>დიახ, დახურვა</button></div></div>';
  modal.querySelector('[data-table-title]').textContent = tableTitle;
  modal.querySelector('[data-payment-title]').textContent = paymentText;
  modal.querySelector('[data-subtotal]').textContent = formatMoney(subtotal);
  modal.querySelector('[data-service-line]').hidden = pricing.serviceRate === 0;
  modal.querySelector('[data-cash-line]').hidden = paymentType !== 'mixed';
  modal.querySelector('[data-card-line]').hidden = paymentType !== 'mixed';
  const list = modal.querySelector('.garbalia-close-list');
  items.slice(0, 10).forEach(function (item) {
    const div = document.createElement('div');
    const title = document.createElement('span');
    const amount = document.createElement('strong');
    title.textContent = itemTitle(item, 'პროდუქტი');
    amount.textContent = itemSum(item);
    div.appendChild(title);
    div.appendChild(amount);
    list.appendChild(div);
  });
  if (items.length > 10) {
    const div = document.createElement('div');
    div.innerHTML = '<span>კიდევ</span><strong>+' + (items.length - 10) + ' პროდუქტი</strong>';
    list.appendChild(div);
  }
  document.body.appendChild(modal);

  const enabled = modal.querySelector('[data-discount-enabled]');
  const controls = modal.querySelector('.garbalia-discount-controls');
  const type = modal.querySelector('[data-discount-type]');
  const value = modal.querySelector('[data-discount-value]');
  const discountLine = modal.querySelector('[data-discount-line]');
  const discountAmountEl = modal.querySelector('[data-discount-amount]');
  const finalEl = modal.querySelector('[data-final-total]');
  const serviceEl = modal.querySelector('[data-service-amount]');
  let finalTotal = subtotal;
  let discountAmount = 0;
  let lastSplit = 'cash';

  function recalc() {
    if (!modal.isConnected || modal.hidden) return;
    const totals = calculateCloseTotals(subtotal, enabled.checked ? type.value : 'none', value.value, pricing.serviceRate);
    discountAmount = totals.discount;
    finalTotal = totals.total;
    discountLine.hidden = discountAmount <= 0;
    discountAmountEl.textContent = '-' + formatMoney(discountAmount);
    finalEl.textContent = formatMoney(finalTotal);
    serviceEl.textContent = formatMoney(totals.service);
    if (paymentType === 'mixed') syncSplit(lastSplit);
  }

  function syncSplit(changed) {
    lastSplit = changed || lastSplit;
    if (!cashInput || !cardInput) return;
    const finalCents = moneyToCents(finalTotal);
    const edited = lastSplit === 'cash' ? cashInput : cardInput;
    const remainder = lastSplit === 'cash' ? cardInput : cashInput;
    const paidCents = Math.max(0, Math.min(finalCents, moneyToCents(edited.value)));
    edited.value = (paidCents / 100).toFixed(2);
    remainder.value = ((finalCents - paidCents) / 100).toFixed(2);
    modal.querySelector('[data-cash-total]').textContent = formatMoney(parseMoney(cashInput.value));
    modal.querySelector('[data-card-total]').textContent = formatMoney(parseMoney(cardInput.value));
  }

  enabled.addEventListener('change', function () { controls.hidden = !enabled.checked; recalc(); if (enabled.checked) setTimeout(function () { value.focus(); }, 60); });
  type.addEventListener('change', recalc);
  value.addEventListener('input', recalc);
  const onCash = function () { lastSplit = 'cash'; recalc(); };
  const onCard = function () { lastSplit = 'card'; recalc(); };
  if (cashInput) cashInput.addEventListener('input', onCash);
  if (cardInput) cardInput.addEventListener('input', onCard);
  function dismiss() {
    if (form.dataset.directClosePrinting === '1') return;
    if (cashInput) cashInput.removeEventListener('input', onCash);
    if (cardInput) cardInput.removeEventListener('input', onCard);
    closeModal(modal);
    modal.remove();
  }
  modal.garbaliaDismiss = dismiss;

  modal.querySelectorAll('[data-close-modal]').forEach(function (btn) { btn.addEventListener('click', dismiss); });
  modal.addEventListener('click', function (event) { if (event.target === modal) dismiss(); });
  modal.querySelector('[data-confirm-close]').addEventListener('click', function () {
    if (form.dataset.directClosePrinting === '1') return;
    // Only the new preview can opt in; stale cached clients must refresh before charging.
    setHidden(form, 'service_charge_version', '1');
    setHidden(form, 'expected_subtotal', subtotal.toFixed(2));
    setHidden(form, 'discount_enabled', enabled.checked ? '1' : '0');
    setHidden(form, 'discount_type', enabled.checked ? type.value : 'none');
    setHidden(form, 'discount_value', enabled.checked ? (value.value || '0') : '0');
    if (paymentType === 'mixed') syncSplit(lastSplit);
    form.dataset.garbaliaConfirmedClose = '1';
    try { garbaliaAllowNavigation = true; } catch (e) {}
    form.submit();
  });
  recalc();
}

document.addEventListener('submit', function (event) {
  const form = event.target;
  const action = form && form.querySelector ? form.querySelector('input[name="action"]') : null;
  if (!action) return;

  if (action.value === 'send_order') {
    if (form.dataset.garbaliaConfirmedSend === '1') return;
    event.preventDefault();
    event.stopPropagation();
    const items = unsentOrderItems();
    const tableTitle = document.querySelector('.page-head h1') ? document.querySelector('.page-head h1').textContent.trim() : 'მაგიდა';
    const info = [{label: 'მაგიდა', value: tableTitle}, {label: 'გასაგზავნი', value: String(items.length) + ' პროდუქტი'}];
    items.slice(0, 8).forEach(function (item, index) { const sum = itemSum(item); info.push({label: 'პროდუქტი ' + (index + 1), value: sum ? itemTitle(item, 'პროდუქტი') + ' — ' + sum : itemTitle(item, 'პროდუქტი'), className: 'order-line'}); });
    if (items.length > 8) info.push({label: 'კიდევ', value: '+' + (items.length - 8) + ' პროდუქტი'});
    showGarbaliaConfirm({ id: 'garbalia-send-order-modal', title: 'შეკვეთის გაგზავნა', message: 'გადაამოწმე გასაგზავნი პროდუქცია. დადასტურების შემდეგ შეკვეთა გადავა ბეჭდვაზე.', info: info, confirmText: 'დიახ, გაგზავნა', cancelText: 'არა', confirmClass: 'primary', onConfirm: function () { form.dataset.garbaliaConfirmedSend = '1'; try { garbaliaAllowNavigation = true; } catch (e) {} form.submit(); } });
    return;
  }

  if (action.value !== 'close_order') return;
  if (form.dataset.garbaliaConfirmedClose === '1') return;
  event.preventDefault();
  event.stopPropagation();
  const unsent = unsentOrderItems();
  if (unsent.length > 0) {
    const info = [{label: 'გასაგზავნი', value: String(unsent.length) + ' პროდუქტი'}];
    unsent.slice(0, 6).forEach(function (item, index) { info.push({label: 'პროდუქტი ' + (index + 1), value: itemTitle(item, 'პროდუქტი'), className: 'order-line'}); });
    if (unsent.length > 6) info.push({label: 'კიდევ', value: '+' + (unsent.length - 6) + ' პროდუქტი'});
    showGarbaliaConfirm({ id: 'garbalia-block-close-modal', title: 'ჯერ გაგზავნაა საჭირო', message: 'ამ მაგიდაზე არის გაუგზავნელი პროდუქცია. მაგიდის დახურვამდე ჯერ გაგზავნე შეკვეთა.', info: info, confirmText: 'შეკვეთის გაგზავნა', cancelText: 'დახურვა', confirmClass: 'primary', onConfirm: function () { const sendForm = document.querySelector('form.send-order-form, form input[name="action"][value="send_order"]')?.closest('form'); if (sendForm) sendForm.requestSubmit ? sendForm.requestSubmit() : sendForm.submit(); } });
    return;
  }
  showDiscountCloseModal(form);
}, true);
