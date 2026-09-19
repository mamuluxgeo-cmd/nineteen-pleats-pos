let garbaliaAllowNavigation = false;

function garbaliaLogoImg(className) {
  return '<img class="' + className + '" src="/Logo.png?v=12" alt="GARBALIA">';
}

document.addEventListener('change', function (event) {
  if (event.target && event.target.id === 'payment_type') {
    const box = document.getElementById('mixed_fields');
    if (box) box.style.display = event.target.value === 'mixed' ? 'grid' : 'none';
  }
  if (event.target && event.target.classList.contains('qty-input')) normalizeQtyInput(event.target);
});

document.addEventListener('input', function (event) {
  if (event.target && event.target.classList.contains('qty-input')) {
    event.target.step = '1';
    event.target.min = '1';
  }
});

document.addEventListener('DOMContentLoaded', function () {
  const loginHint = document.querySelector('.login-card .hint');
  if (loginHint) loginHint.remove();

  injectGarbaliaBrandStyles();
  brandGarbaliaEverywhere();
  addHistoryNavLink();
  removeReportsEverywhere();
  fixQuantityInputs();
  initLiveDatePill();

  const route = currentPage();
  const params = new URLSearchParams(window.location.search);
  if (route === 'reports') {
    window.location.replace('/history');
    return;
  }
  if (route === 'products') {
    document.body.classList.add('page-products');
    injectProductPageStyles();
    enhanceProductsPage();
  }
  if (route === 'table') {
    document.body.classList.add('page-table');
    enhanceTablePage(params.get('id') || tableIdFromPath());
    enhanceOrderCancelForms();
    initUnsentOrderGuard();
  }
});

function currentPage() {
  const params = new URLSearchParams(window.location.search);
  if (params.get('page')) return params.get('page');
  const first = window.location.pathname.replace(/^\/+|\/+$/g, '').split('/')[0];
  return first || 'day';
}

function tableIdFromPath() {
  const parts = window.location.pathname.replace(/^\/+|\/+$/g, '').split('/');
  return parts[0] === 'table' ? (parts[1] || '') : '';
}

function injectGarbaliaBrandStyles() {
  if (document.getElementById('garbalia-exact-brand-style')) return;
  const style = document.createElement('style');
  style.id = 'garbalia-exact-brand-style';
  style.textContent = `
    .garbalia-mark,.footer-mark{display:flex!important;align-items:center!important;justify-content:center!important;background:transparent!important;color:#111!important;overflow:visible!important;border:0!important;border-radius:0!important;padding:0!important;box-shadow:none!important}
    .garbalia-mark{width:76px!important;height:44px!important;flex:0 0 76px!important}.footer-mark{width:76px!important;height:44px!important;flex:0 0 76px!important}
    .garbalia-header-logo,.footer-logo-img{display:block!important;width:100%!important;height:100%!important;object-fit:contain!important;background:transparent!important;border:0!important;border-radius:0!important;padding:0!important;box-shadow:none!important}.garbalia-header-logo{filter:brightness(0) invert(1)!important;mix-blend-mode:screen!important}.footer-logo-img{mix-blend-mode:multiply!important}
    .garbalia-word{letter-spacing:.04em!important}.brand.garbalia-brand{gap:13px!important;min-width:0!important}.brand-text{min-width:0!important}
    .login-card{position:relative;overflow:hidden;border-radius:28px!important;max-width:460px!important}.login-card:before{display:none!important;content:none!important}
    .login-brand-line{display:flex!important;flex-direction:column!important;align-items:center!important;justify-content:center!important;gap:8px!important;margin:0 auto 18px!important;width:100%!important;text-align:center!important}
    .login-logo{flex:0 0 auto!important;width:70px!important;max-width:70px!important;height:62px!important;margin:0 auto!important;background:transparent!important;box-shadow:none!important;border-radius:0!important;display:flex!important;align-items:center!important;justify-content:center!important;padding:0!important;border:0!important;overflow:visible!important}
    .login-logo-img{display:block!important;width:70px!important;height:62px!important;object-fit:contain!important;border-radius:0!important;background:transparent!important;padding:0!important;box-shadow:none!important;border:0!important;mix-blend-mode:multiply!important}
    .garbalia-login-badge{margin:0 auto!important;display:flex!important;flex-direction:column!important;gap:3px!important;align-items:center!important;justify-content:center!important;text-align:center!important;border:0!important;background:transparent!important;border-radius:0!important;padding:0!important;color:#2b1b10!important;box-shadow:none!important;min-width:0!important;font-family:Inter,Montserrat,Poppins,Arial,sans-serif!important}
    .garbalia-login-badge strong{font-size:1rem!important;letter-spacing:.14em!important;line-height:1.1!important;font-weight:950!important;text-align:center!important}.garbalia-login-badge small{font-size:.76rem!important;color:#6d5140!important;font-weight:800!important;line-height:1.25!important;text-align:center!important;letter-spacing:.01em!important}
    .footer-brand{gap:14px!important}.footer-brand strong{letter-spacing:.10em!important}
    .live-date-pill{display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:10px!important;min-height:44px!important;min-width:176px!important;padding:10px 16px!important;border-radius:999px!important;background:linear-gradient(180deg,rgba(255,250,242,.96),rgba(241,226,206,.92))!important;border:1px solid rgba(43,27,16,.12)!important;box-shadow:0 12px 26px rgba(43,27,16,.10)!important;color:#2b1b10!important;font-family:Inter,Montserrat,Poppins,system-ui,sans-serif!important;font-weight:950!important;letter-spacing:.01em!important}
    .live-date-pill:before{content:"";width:8px;height:8px;border-radius:999px;background:#24733c;box-shadow:0 0 0 5px rgba(36,115,60,.13)}.live-date-text{white-space:nowrap;font-size:.98rem;line-height:1}
    .cancel-form.cancel-form-compact{grid-template-columns:74px minmax(190px,1fr) 116px!important;align-items:center!important}.cancel-form.cancel-form-compact select{width:100%!important}.cancel-form.cancel-form-compact .btn{width:100%!important}.cancel-form.cancel-form-compact .muted{white-space:nowrap!important}
    .garbalia-confirm-overlay{position:fixed;inset:0;z-index:10000;display:grid;place-items:center;padding:20px;background:rgba(43,27,16,.46);backdrop-filter:blur(7px);animation:garbaliaFadeIn .16s ease-out}
    .garbalia-confirm-dialog{position:relative;width:min(470px,100%);overflow:hidden;border:1px solid #ead6bd;border-radius:28px;background:linear-gradient(180deg,#fffaf2 0%,#f8ecdd 100%);box-shadow:0 28px 70px rgba(43,27,16,.30);padding:28px;color:#2b1b10;text-align:center;animation:garbaliaPopIn .18s ease-out;font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif}
    .garbalia-confirm-bg-logo{position:absolute;right:-18px;top:-16px;width:136px;height:88px;object-fit:contain;opacity:.07;filter:brightness(0);pointer-events:none}.garbalia-confirm-mini{width:48px;height:34px;object-fit:contain;margin:0 auto 10px;mix-blend-mode:multiply}
    .garbalia-confirm-dialog h3{margin:0 0 8px;font-size:1.36rem;font-weight:950;letter-spacing:-.02em}.garbalia-confirm-dialog p{margin:0 auto 20px;max-width:380px;color:#6d5140;font-weight:800;line-height:1.45;white-space:pre-line}.garbalia-confirm-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px}.garbalia-confirm-actions .btn{width:100%;min-height:46px;border-radius:14px}.garbalia-confirm-actions .btn.light{background:#f1e2ce!important;color:#2b1b10!important}.garbalia-confirm-close{position:absolute;right:12px;top:12px;width:34px;height:34px;border:0;border-radius:50%;background:rgba(43,27,16,.08);color:#2b1b10;font-size:20px;font-weight:900;cursor:pointer}
    .garbalia-confirm-info{display:grid;gap:8px;margin:0 auto 18px;max-width:380px;text-align:left}.garbalia-confirm-info div{display:flex;justify-content:space-between;gap:12px;padding:10px 12px;border-radius:14px;background:rgba(255,255,255,.58);border:1px solid rgba(43,27,16,.09)}.garbalia-confirm-info span{color:#7a6657;font-weight:800}.garbalia-confirm-info strong{font-weight:950;color:#2b1b10;white-space:nowrap}.garbalia-confirm-info .diff-plus strong{color:#24733c}.garbalia-confirm-info .diff-minus strong{color:#b9332a}.garbalia-confirm-info .diff-zero strong{color:#2357a5}
    .unsent-overlay{position:fixed;inset:0;z-index:9999;display:grid;place-items:center;padding:20px;background:rgba(43,27,16,.45);backdrop-filter:blur(7px)}
    .unsent-dialog{position:relative;width:min(430px,100%);overflow:hidden;border:1px solid #ead6bd;border-radius:28px;background:linear-gradient(180deg,#fffaf2 0%,#f8ecdd 100%);box-shadow:0 28px 70px rgba(43,27,16,.28);padding:28px;color:#2b1b10;text-align:center}
    .unsent-logo{position:absolute;right:-12px;top:-10px;width:126px;height:82px;object-fit:contain;opacity:.08;filter:brightness(0);pointer-events:none}.unsent-mini{width:46px;height:34px;object-fit:contain;margin:0 auto 10px;mix-blend-mode:multiply}.unsent-dialog h3{margin:0 0 8px;font-size:1.35rem;font-weight:950;letter-spacing:-.02em}.unsent-dialog p{margin:0 auto 20px;max-width:320px;color:#6d5140;font-weight:800}.unsent-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px}.unsent-actions .btn{width:100%;min-height:46px;border-radius:14px}.unsent-actions .btn.light{background:#f1e2ce!important;color:#2b1b10!important}.unsent-close{position:absolute;right:12px;top:12px;width:34px;height:34px;border:0;border-radius:50%;background:rgba(43,27,16,.08);color:#2b1b10;font-size:20px;font-weight:900;cursor:pointer}
    @keyframes garbaliaFadeIn{from{opacity:0}to{opacity:1}}@keyframes garbaliaPopIn{from{opacity:0;transform:translateY(10px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}
    @media(max-width:820px){.garbalia-mark{width:66px!important;height:40px!important;flex-basis:66px!important}.garbalia-word{font-size:.95rem!important}.brand small{font-size:.72rem!important}.cancel-form.cancel-form-compact{grid-template-columns:1fr 1fr!important}.cancel-form.cancel-form-compact .btn{grid-column:1/-1}}
    @media(max-width:620px){.garbalia-mark,.footer-mark{width:60px!important;height:38px!important;flex-basis:60px!important}.login-logo{width:64px!important;max-width:64px!important}.login-logo-img{width:64px!important;height:56px!important}.garbalia-login-badge strong{font-size:.92rem!important}.garbalia-login-badge small{font-size:.70rem!important}.unsent-actions,.garbalia-confirm-actions{grid-template-columns:1fr}.live-date-pill{min-width:0!important;width:100%!important}.cancel-form.cancel-form-compact{grid-template-columns:1fr!important}.garbalia-confirm-info div{display:block}.garbalia-confirm-info strong{display:block;margin-top:3px}}
  `;
  document.head.appendChild(style);
}

function brandGarbaliaEverywhere() {
  document.querySelectorAll('.garbalia-mark').forEach(function (mark) { mark.innerHTML = garbaliaLogoImg('garbalia-header-logo'); });
  document.querySelectorAll('.footer-mark').forEach(function (mark) { mark.innerHTML = garbaliaLogoImg('footer-logo-img'); });
  const logo = document.querySelector('.login-logo');
  if (logo) {
    logo.innerHTML = garbaliaLogoImg('login-logo-img');
    if (!document.querySelector('.login-brand-line')) {
      logo.insertAdjacentHTML('beforebegin', '<div class="login-brand-line"></div>');
      const row = document.querySelector('.login-brand-line');
      row.appendChild(logo);
      row.insertAdjacentHTML('beforeend', '<div class="garbalia-login-badge"><strong>© GARBALIA POS</strong><small>Restaurant management software</small></div>');
    }
  }
}

function addHistoryNavLink() {
  const nav = document.querySelector('.nav');
  if (!nav) return;
  if (nav.querySelector('a[href*="products"]') && !nav.querySelector('a[href*="history"]')) {
    const historyLink = document.createElement('a');
    historyLink.href = '/history';
    historyLink.textContent = 'ისტორია';
    const reportsLink = nav.querySelector('a[href*="reports"]');
    if (reportsLink) nav.insertBefore(historyLink, reportsLink); else nav.appendChild(historyLink);
  }
}

function removeReportsEverywhere() {
  document.querySelectorAll('.nav a[href*="reports"], a[href="/reports"]').forEach(function (link) { link.remove(); });
}

function fixQuantityInputs() {
  document.querySelectorAll('.qty-input').forEach(function (input) {
    input.step = '1';
    input.min = '1';
    input.inputMode = 'numeric';
    normalizeQtyInput(input);
  });
}
function normalizeQtyInput(input) {
  const number = parseInt(String(input.value).replace(',', '.'), 10);
  input.value = Number.isFinite(number) && number >= 1 ? String(number) : '1';
}

function initLiveDatePill() {
  const pill = document.querySelector('.page-head .pill');
  if (!pill || pill.dataset.liveDate === '1') return;
  const original = pill.textContent.trim();
  if (!/^დღე\s*#/.test(original)) return;
  pill.dataset.liveDate = '1';
  pill.classList.add('live-date-pill');

  function renderDate() {
    const now = new Date();
    const months = ['იანვარი','თებერვალი','მარტი','აპრილი','მაისი','ივნისი','ივლისი','აგვისტო','სექტემბერი','ოქტომბერი','ნოემბერი','დეკემბერი'];
    const day = now.getDate();
    const month = months[now.getMonth()];
    const hour = String(now.getHours()).padStart(2, '0');
    const minute = String(now.getMinutes()).padStart(2, '0');
    pill.innerHTML = '<span class="live-date-text">' + day + ' ' + month + ' ' + hour + ':' + minute + '</span>';
    pill.title = 'დღევანდელი თარიღი და დრო';
  }

  function scheduleNextMinute() {
    const now = new Date();
    const delay = (60 - now.getSeconds()) * 1000 + 120;
    window.setTimeout(function () {
      renderDate();
      scheduleNextMinute();
    }, delay);
  }

  renderDate();
  scheduleNextMinute();
}

function injectProductPageStyles() {
  if (document.getElementById('product-page-style')) return;
  const style = document.createElement('style');
  style.id = 'product-page-style';
  style.textContent = `
    .page-products .wrap{max-width:1280px!important}.page-products .page-head{align-items:center!important;margin-bottom:16px!important}.page-products .two-col{display:block!important;width:100%!important}.page-products .two-col.products-full-layout{display:block!important}.page-products .card{border-radius:24px;overflow:visible}.page-products .product-list-card{width:100%!important;max-width:none!important}.page-products .table-wrap{border:0;overflow:visible;background:transparent;width:100%}
    .product-top-panel{margin:0 0 18px;padding:18px 20px;border:1px solid #ead6bd;border-radius:24px;background:linear-gradient(135deg,rgba(255,250,242,.96),rgba(241,226,206,.78));box-shadow:0 18px 42px rgba(43,27,16,.10)}.product-top-row{display:flex;align-items:center;justify-content:space-between;gap:18px}.product-top-copy{display:flex;align-items:center;gap:13px;min-width:0}.product-top-icon{display:grid;place-items:center;width:46px;height:46px;border-radius:15px;background:#2b1b10;color:#fff;font-size:28px;font-weight:950;box-shadow:0 10px 22px rgba(43,27,16,.17)}.product-top-copy h2{margin:0;font-size:1.18rem;font-weight:950;color:#2b1b10}.product-top-copy p{margin:3px 0 0;color:#7a6657;font-weight:800}.product-add-toggle{min-height:46px;padding:10px 18px!important;border-radius:15px!important;white-space:nowrap!important}.product-add-holder{margin-top:14px}.product-add-card{max-width:none!important;margin:0!important;background:rgba(255,255,255,.72)!important;border:1px solid rgba(43,27,16,.10)!important;box-shadow:none!important}.product-add-card[hidden]{display:none!important}.product-add-card h2{display:none!important}.product-add-card form{display:grid!important;grid-template-columns:1.4fr 160px 220px 130px 170px;gap:12px;align-items:end}.product-add-card label{margin:0!important}.product-add-card .check{align-self:center;display:flex!important;align-items:center!important;gap:8px!important;padding:12px 14px!important;border:1px solid #ead6bd;border-radius:14px;background:#fff}.product-add-card .btn{min-height:48px!important;border-radius:14px!important}
    .page-products table,.page-products tbody{display:block;width:100%;min-width:0;background:transparent;border-collapse:separate;border-spacing:0}.page-products thead{display:none}.page-products tbody{display:grid;gap:10px}.page-products tr{display:grid;width:100%;grid-template-columns:minmax(240px,1.35fr) minmax(130px,.8fr) 105px 98px 210px;gap:12px;align-items:center;background:#fff;border:1px solid #ead6bd;border-radius:18px;padding:13px 14px;box-shadow:0 8px 20px rgba(43,27,16,.07);overflow:hidden}.page-products td{border:0;padding:0;min-width:0;overflow:hidden;text-overflow:ellipsis}.page-products td:nth-child(1){font-weight:900;font-size:1rem;line-height:1.18;white-space:normal;overflow-wrap:anywhere}.page-products td:nth-child(2){color:#7a6657;font-size:.92rem;white-space:nowrap}.page-products td:nth-child(3){font-weight:900;white-space:nowrap;font-size:.95rem}.page-products td:nth-child(4){display:flex;width:100%;align-items:center;justify-content:center;border-radius:999px;background:#e9ffe4;color:#24733c;font-weight:900;padding:6px 8px;font-size:.84rem;white-space:nowrap;overflow:hidden}.page-products .product-actions-cell{display:flex;gap:8px;align-items:center;justify-content:flex-end;flex-wrap:nowrap;overflow:visible}.page-products .inline-action-form{margin:0!important;display:inline-flex}.page-products .btn.mini{min-height:36px;width:auto!important;padding:8px 11px;border-radius:11px;font-size:.84rem;line-height:1.1;white-space:nowrap}.page-products .btn.edit{background:#2357a5;color:#fff!important;text-decoration:none}
    @media(max-width:1120px){.product-add-card form{grid-template-columns:1fr 130px 170px}.product-add-card .check,.product-add-card .btn{grid-column:auto}.page-products tr{grid-template-columns:minmax(0,1fr) 120px 88px 92px 194px}}
    @media(max-width:820px){.product-top-row{align-items:stretch;flex-direction:column}.product-add-toggle{width:100%!important}.product-add-card form{grid-template-columns:1fr 1fr}.product-add-card .btn{grid-column:1/-1}.page-products tr{grid-template-columns:minmax(0,1fr) 100px 78px 86px}.page-products .product-actions-cell{grid-column:1/-1;justify-content:flex-start}.page-products .btn.mini{min-height:38px}}
    @media(max-width:640px){.product-top-panel{padding:15px}.product-top-copy{align-items:flex-start}.page-products tr{display:block;padding:14px}.page-products td{display:flex;justify-content:space-between;gap:12px;padding:7px 0;border-bottom:1px solid #f0dfc9;overflow:visible}.page-products td:last-child{border-bottom:0}.page-products td:before{font-weight:900;color:#7a6657}.page-products td:nth-child(1):before{content:'პროდუქტი'}.page-products td:nth-child(2):before{content:'კატეგორია'}.page-products td:nth-child(3):before{content:'ფასი'}.page-products td:nth-child(4):before{content:'სტატუსი'}.page-products td:nth-child(4){justify-content:space-between;background:transparent;color:#24733c;padding:7px 0}.page-products .product-actions-cell{justify-content:stretch;display:flex}.page-products .product-actions-cell,.page-products .inline-action-form,.page-products .product-actions-cell .btn{width:100%!important}.product-add-card form{grid-template-columns:1fr!important}}
  `;
  document.head.appendChild(style);
}

function enhanceProductsPage() {
  const section = document.querySelector('.page-products .two-col');
  const pageHead = document.querySelector('.page-products .page-head');
  if (section && pageHead && !document.querySelector('.product-top-panel')) {
    const cards = Array.from(section.children).filter(function (el) { return el.classList && el.classList.contains('card'); });
    const addCard = cards[0];
    const listCard = cards[1];
    if (addCard && listCard) {
      section.classList.add('products-full-layout');
      addCard.classList.add('product-add-card');
      listCard.classList.add('product-list-card');
      const editMode = (addCard.querySelector('h2') && addCard.querySelector('h2').textContent.includes('რედაქტირება')) || new URLSearchParams(window.location.search).has('edit');
      const panel = document.createElement('section');
      panel.className = 'product-top-panel';
      panel.innerHTML = '<div class="product-top-row"><div class="product-top-copy"><span class="product-top-icon">+</span><div><h2>პროდუქციის მართვა</h2><p>დაამატე ახალი პროდუქტი მხოლოდ მაშინ, როცა დაგჭირდება — სია კი სრულად ჩანს.</p></div></div><button type="button" class="btn success product-add-toggle">' + (editMode ? 'რედაქტირების ფორმა' : 'ახალი პროდუქტის დამატება') + '</button></div><div class="product-add-holder"></div>';
      pageHead.insertAdjacentElement('afterend', panel);
      panel.querySelector('.product-add-holder').appendChild(addCard);
      const toggle = panel.querySelector('.product-add-toggle');
      const setOpen = function (open) {
        addCard.hidden = !open;
        toggle.textContent = open ? 'ფორმის დამალვა' : 'ახალი პროდუქტის დამატება';
        panel.classList.toggle('is-open', open);
      };
      setOpen(editMode);
      toggle.addEventListener('click', function () { setOpen(addCard.hidden); });
    }
  }

  document.querySelectorAll('td form input[name="action"][value="toggle_product"]').forEach(function (input) {
    const toggleForm = input.closest('form');
    if (!toggleForm || toggleForm.dataset.enhanced === '1') return;
    toggleForm.dataset.enhanced = '1';
    const actionCell = toggleForm.closest('td');
    if (!actionCell) return;
    actionCell.classList.add('product-actions-cell');
    const editLink = actionCell.querySelector('a');
    if (editLink) editLink.classList.add('btn', 'mini', 'edit');
    toggleForm.classList.add('inline-action-form');
    const toggleBtn = toggleForm.querySelector('button');
    if (toggleBtn) toggleBtn.classList.add('mini');
  });
}

function enhanceTablePage(tableId) {
  const totalBox = document.querySelector('.total-box');
  const orderCard = document.querySelector('.pos-grid > .card:nth-child(2)');
  if (!tableId || !totalBox || !orderCard || document.getElementById('zero-close-form')) return;
  if (!orderCard.querySelector('.order-item')) return;
  const total = parseFloat(totalBox.textContent.replace(',', '.').replace(/[^0-9.]/g, '')) || 0;
  if (total > 0.001) return;
  const closeTitle = Array.from(orderCard.querySelectorAll('h2')).find(function (h) { return h.textContent.includes('მაგიდის დახურვა'); });
  const closeForm = orderCard.querySelector('form.close-form');
  if (closeTitle) closeTitle.remove();
  if (closeForm) closeForm.remove();
  const form = document.createElement('form');
  form.id = 'zero-close-form';
  form.method = 'post';
  form.className = 'zero-close-form';
  form.innerHTML = '<h3>ცარიელი მაგიდის დახურვა</h3><p class="muted">ჯამი არის 0.00 ₾ — მაგიდა დაიხურება გაყიდვის გარეშე.</p><input type="hidden" name="action" value="cancel_order"><input type="hidden" name="table_id" value="' + escapeHtml(tableId) + '"><button class="btn danger" type="submit">ნულით დახურვა</button>';
  orderCard.appendChild(form);
}

function enhanceOrderCancelForms() {
  document.querySelectorAll('.cancel-form').forEach(function (form) {
    const extra = form.querySelector('input[name="cancel_reason_custom"]');
    if (extra) extra.remove();
    form.classList.add('cancel-form-compact');
  });
}

function hasUnsentActiveItems() {
  const items = Array.from(document.querySelectorAll('.order-item:not(.cancelled)'));
  return items.some(function (item) { return !item.querySelector('.sent'); });
}

function initUnsentOrderGuard() {
  if (document.body.dataset.unsentGuard === '1') return;
  document.body.dataset.unsentGuard = '1';

  document.addEventListener('click', function (event) {
    const link = event.target.closest && event.target.closest('a[href]');
    if (!link || garbaliaAllowNavigation || !hasUnsentActiveItems()) return;
    if (link.target === '_blank' || link.hasAttribute('download')) return;
    const href = link.getAttribute('href') || '';
    if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
    const targetUrl = new URL(link.href, window.location.href);
    if (targetUrl.pathname === window.location.pathname && targetUrl.search === window.location.search) return;
    event.preventDefault();
    showUnsentOrderModal(function () {
      garbaliaAllowNavigation = true;
      window.location.href = link.href;
    });
  }, true);

  window.addEventListener('beforeunload', function (event) {
    if (!garbaliaAllowNavigation && hasUnsentActiveItems()) {
      event.preventDefault();
      event.returnValue = '';
    }
  });
}

function showUnsentOrderModal(onConfirm) {
  showGarbaliaConfirm({
    id: 'garbalia-unsent-modal',
    title: 'შეკვეთა არ გადაგიგზავნია',
    message: 'ნამდვილად გსურთ გამოსვლა?',
    confirmText: 'დიახ',
    cancelText: 'არა',
    confirmClass: 'danger',
    onConfirm: onConfirm
  });
}

function showGarbaliaConfirm(options) {
  const old = document.getElementById(options.id || 'garbalia-confirm-modal');
  if (old) old.remove();
  const overlay = document.createElement('div');
  overlay.id = options.id || 'garbalia-confirm-modal';
  overlay.className = 'garbalia-confirm-overlay';
  const infoHtml = Array.isArray(options.info) && options.info.length
    ? '<div class="garbalia-confirm-info">' + options.info.map(function (row) {
        const cls = row.className ? ' class="' + escapeHtml(row.className) + '"' : '';
        return '<div' + cls + '><span>' + escapeHtml(row.label) + '</span><strong>' + escapeHtml(row.value) + '</strong></div>';
      }).join('') + '</div>'
    : '';
  overlay.innerHTML = '<div class="garbalia-confirm-dialog" role="dialog" aria-modal="true"><button type="button" class="garbalia-confirm-close" aria-label="დახურვა">×</button><img class="garbalia-confirm-bg-logo" src="/Logo.png?v=12" alt=""><img class="garbalia-confirm-mini" src="/Logo.png?v=12" alt="GARBALIA"><h3>' + escapeHtml(options.title || 'დადასტურება') + '</h3><p>' + escapeHtml(options.message || '') + '</p>' + infoHtml + '<div class="garbalia-confirm-actions"><button type="button" class="btn light" data-garbalia-cancel>' + escapeHtml(options.cancelText || 'არა') + '</button><button type="button" class="btn ' + escapeHtml(options.confirmClass || 'danger') + '" data-garbalia-confirm>' + escapeHtml(options.confirmText || 'დიახ') + '</button></div></div>';
  document.body.appendChild(overlay);
  const close = function () { overlay.remove(); document.removeEventListener('keydown', escHandler); };
  const confirmButton = overlay.querySelector('[data-garbalia-confirm]');
  overlay.querySelector('.garbalia-confirm-close').addEventListener('click', close);
  overlay.querySelector('[data-garbalia-cancel]').addEventListener('click', close);
  overlay.addEventListener('click', function (event) { if (event.target === overlay) close(); });
  function escHandler(event) {
    if (event.key === 'Escape') {
      close();
    }
  }
  document.addEventListener('keydown', escHandler);
  confirmButton.addEventListener('click', function () {
    close();
    if (typeof options.onConfirm === 'function') options.onConfirm();
  });
  confirmButton.focus();
}

function escapeHtml(text) {
  return String(text).replace(/[&<>"']/g, function (char) {
    return {'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'}[char];
  });
}

function parseMoney(text) {
  return parseFloat(String(text || '').replace(',', '.').replace(/[^0-9.\-]/g, '')) || 0;
}

function formatMoney(value) {
  return (Number(value) || 0).toFixed(2) + ' ₾';
}

function findStatValue(labelText) {
  const cards = Array.from(document.querySelectorAll('.stats div'));
  const card = cards.find(function (div) {
    const span = div.querySelector('span');
    return span && span.textContent.trim().includes(labelText);
  });
  return card ? parseMoney(card.querySelector('strong') ? card.querySelector('strong').textContent : '') : 0;
}

function submitFormAfterConfirm(form) {
  garbaliaAllowNavigation = true;
  form.submit();
}

document.addEventListener('submit', function (event) {
  const qtyInput = event.target.querySelector && event.target.querySelector('.qty-input');
  if (qtyInput) normalizeQtyInput(qtyInput);
  const action = event.target.querySelector && event.target.querySelector('input[name="action"]');
  if (!action) return;
  const form = event.target;

  if (action.value === 'cancel_item') {
    event.preventDefault();
    const orderItem = form.closest('.order-item');
    const itemName = orderItem && orderItem.querySelector('strong') ? orderItem.querySelector('strong').textContent.trim() : 'პროდუქტი';
    const reasonSelect = form.querySelector('select[name="cancel_reason"]');
    const reason = reasonSelect ? reasonSelect.options[reasonSelect.selectedIndex].text : 'მიზეზი არ არის მითითებული';
    showGarbaliaConfirm({
      title: 'პროდუქტის გაუქმება',
      message: 'ნამდვილად გსურთ ამ პროდუქტის გაუქმება?',
      info: [
        {label: 'პროდუქტი', value: itemName},
        {label: 'მიზეზი', value: reason}
      ],
      confirmText: 'დიახ, გაუქმდეს',
      cancelText: 'არა',
      confirmClass: 'danger',
      onConfirm: function () { submitFormAfterConfirm(form); }
    });
    return;
  }

  if (action.value === 'open_day') {
    event.preventDefault();
    const amount = parseMoney(form.querySelector('input[name="opening_cash"]') ? form.querySelector('input[name="opening_cash"]').value : '0');
    showGarbaliaConfirm({
      title: 'დღის გახსნა',
      message: 'იხსნება დღევანდელი დღის სალარო. გადაამოწმე საწყისი ნაღდი თანხა.',
      info: [
        {label: 'საწყისი ნაღდი', value: formatMoney(amount)},
        {label: 'მოქმედება', value: 'დღის გახსნა'}
      ],
      confirmText: 'დიახ, გაიხსნას',
      cancelText: 'არა',
      confirmClass: 'success',
      onConfirm: function () { submitFormAfterConfirm(form); }
    });
    return;
  }

  if (action.value === 'close_day') {
    event.preventDefault();
    const counted = parseMoney(form.querySelector('input[name="closing_cash"]') ? form.querySelector('input[name="closing_cash"]').value : '0');
    const cashSales = findStatValue('ნაღდი');
    const expected = findStatValue('მოსალოდნელი ნაღდი');
    const opening = Math.max(0, expected - cashSales);
    const diff = counted - expected;
    const diffClass = Math.abs(diff) < 0.01 ? 'diff-zero' : (diff > 0 ? 'diff-plus' : 'diff-minus');
    const diffText = Math.abs(diff) < 0.01 ? 'სხვაობა არ არის' : (diff > 0 ? 'ზედმეტობაა ' + formatMoney(Math.abs(diff)) : 'ნაკლებობაა ' + formatMoney(Math.abs(diff)));
    showGarbaliaConfirm({
      title: 'დღის დახურვა',
      message: 'თქვენ ხურავთ სამუშაო დღეს. გადაამოწმეთ სალაროს მონაცემები.',
      info: [
        {label: 'საწყისი ნაშთი', value: formatMoney(opening)},
        {label: 'ნაღდი ნავაჭრი', value: formatMoney(cashSales)},
        {label: 'უნდა იყოს სალაროში', value: formatMoney(expected)},
        {label: 'დათვლილი ნაღდი', value: formatMoney(counted)},
        {label: 'სხვაობა', value: diffText, className: diffClass}
      ],
      confirmText: 'დიახ, სწორია',
      cancelText: 'არა',
      confirmClass: 'danger',
      onConfirm: function () { submitFormAfterConfirm(form); }
    });
    return;
  }

  if (action.value === 'cancel_order') {
    event.preventDefault();
    showGarbaliaConfirm({
      title: 'ნულით დახურვა',
      message: 'ნამდვილად გინდა ამ მაგიდის ნულით დახურვა?\nგაყიდვებში თანხა არ დაემატება.',
      confirmText: 'დიახ, დახურვა',
      cancelText: 'არა',
      confirmClass: 'danger',
      onConfirm: function () { submitFormAfterConfirm(form); }
    });
    return;
  }

  garbaliaAllowNavigation = true;
  window.setTimeout(function () { garbaliaAllowNavigation = false; }, 2500);
});

document.addEventListener('click', function (event) {
  const button = event.target.closest('[data-print]');
  if (!button) return;
  const el = document.getElementById(button.getAttribute('data-print'));
  if (!el) return;
  const win = window.open('', '_blank', 'width=420,height=720');
  win.document.write('<!doctype html><html><head><meta charset="utf-8"><title>Print</title><style>@page{size:80mm auto;margin:4mm}body{font-family:Arial,sans-serif;font-size:13px;line-height:1.35;color:#000;margin:0}pre{white-space:pre-wrap;margin:0;word-break:break-word}</style></head><body><pre>' + escapeHtml(el.innerText) + '</pre><script>window.onload=function(){window.print();}</script></body></html>');
  win.document.close();
});