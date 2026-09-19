(function () {
  'use strict';

  function tableIdFromPage() {
    const parts = window.location.pathname.replace(/^\/+|\/+$/g, '').split('/');
    if (parts[0] === 'table' && /^\d+$/.test(parts[1] || '')) return parts[1];
    const params = new URLSearchParams(window.location.search);
    return params.get('page') === 'table' ? params.get('id') : null;
  }

  function injectStyles() {
    if (document.getElementById('garbalia-table-cancel-style')) return;
    const style = document.createElement('style');
    style.id = 'garbalia-table-cancel-style';
    style.textContent = `
      .garbalia-cancel-order-zone{margin-top:12px;padding-top:12px;border-top:1px solid rgba(43,27,16,.10)}
      .garbalia-cancel-order-btn{width:100%;min-height:42px;border:1px solid rgba(180,49,41,.28);border-radius:12px;background:#fff4f2;color:#a02c25;font:inherit;font-weight:950;cursor:pointer;transition:.16s ease}
      .garbalia-cancel-order-btn:hover{background:#b43129;color:#fff;transform:translateY(-1px);box-shadow:0 10px 22px rgba(180,49,41,.18)}
      .garbalia-table-cancel-overlay{position:fixed;inset:0;z-index:12000;display:grid;place-items:center;padding:14px;background:rgba(43,27,16,.52);backdrop-filter:blur(8px);opacity:0;transition:opacity .16s ease}
      .garbalia-table-cancel-overlay.is-open{opacity:1}
      .garbalia-table-cancel-dialog{position:relative;width:min(560px,calc(100vw - 24px));max-height:calc(100dvh - 24px);overflow:auto;padding:24px;border:1px solid #ead3b7;border-radius:26px;background:linear-gradient(160deg,#fffaf2,#f5e5d1);box-shadow:0 32px 90px rgba(43,27,16,.36);transform:translateY(10px) scale(.985);transition:transform .16s ease}
      .garbalia-table-cancel-overlay.is-open .garbalia-table-cancel-dialog{transform:none}
      .garbalia-table-cancel-dialog:before{content:"";position:absolute;right:-34px;top:-42px;width:180px;height:145px;background:url('/Logo.png?v=12') center/contain no-repeat;opacity:.045;filter:brightness(0);pointer-events:none}
      .garbalia-table-cancel-close{position:absolute;right:13px;top:13px;z-index:2;width:36px;height:36px;border:0;border-radius:50%;background:rgba(43,27,16,.09);color:#2b1b10;font-size:21px;font-weight:950;cursor:pointer}
      .garbalia-table-cancel-icon{display:grid;place-items:center;width:52px;height:52px;margin:0 auto 12px;border-radius:17px;background:linear-gradient(145deg,#c94439,#a82d25);color:#fff;font-size:23px;font-weight:950;box-shadow:0 13px 28px rgba(180,49,41,.22),inset 0 1px 0 rgba(255,255,255,.24)}
      .garbalia-table-cancel-dialog h3{margin:0;text-align:center;color:#2b1b10;font-size:1.28rem;font-weight:950;letter-spacing:-.025em}
      .garbalia-table-cancel-dialog>p{max-width:470px;margin:8px auto 15px;text-align:center;color:#755b49;font-size:.86rem;font-weight:780;line-height:1.5}
      .garbalia-table-cancel-summary{display:grid;grid-template-columns:1fr auto;gap:8px;margin-bottom:13px}
      .garbalia-table-cancel-summary div{min-width:0;padding:10px 11px;border:1px solid rgba(43,27,16,.09);border-radius:13px;background:rgba(255,255,255,.72)}
      .garbalia-table-cancel-summary span{display:block;color:#846a58;font-size:.70rem;font-weight:900}
      .garbalia-table-cancel-summary strong{display:block;margin-top:3px;color:#2b1b10;font-size:.91rem;font-weight:950;overflow-wrap:anywhere}
      .garbalia-table-cancel-form{display:grid;gap:10px}
      .garbalia-table-cancel-form label{display:grid;gap:6px;color:#422a1b;font-size:.82rem;font-weight:900}
      .garbalia-table-cancel-form select,.garbalia-table-cancel-form input{width:100%;min-height:44px;padding:9px 11px;border:1px solid #cba982;border-radius:12px;background:#fff;color:#2b1b10;font:inherit;font-size:.86rem;outline:0}
      .garbalia-table-cancel-form select:focus,.garbalia-table-cancel-form input:focus{border-color:#b43129;box-shadow:0 0 0 4px rgba(180,49,41,.10)}
      .garbalia-table-cancel-warning{padding:10px 11px;border:1px solid #e4a09a;border-radius:13px;background:#fff0ee;color:#7d211b;font-size:.76rem;font-weight:850;line-height:1.45}
      .garbalia-table-cancel-error{display:none;padding:9px 11px;border-radius:11px;background:#b43129;color:#fff;font-size:.78rem;font-weight:900}
      .garbalia-table-cancel-error.is-visible{display:block}
      .garbalia-table-cancel-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:3px}
      .garbalia-table-cancel-actions button{min-height:44px;border:0;border-radius:12px;font:inherit;font-size:.84rem;font-weight:950;cursor:pointer}
      .garbalia-table-cancel-back{background:#eedfcd;color:#3e291c}
      .garbalia-table-cancel-confirm{background:#b43129;color:#fff;box-shadow:0 10px 22px rgba(180,49,41,.20)}
      .garbalia-table-cancel-actions button:disabled{opacity:.55;cursor:wait}
      body.garbalia-table-cancel-open{overflow:hidden!important}
      @media(max-width:520px){.garbalia-table-cancel-dialog{padding:21px 15px 15px;border-radius:22px}.garbalia-table-cancel-summary{grid-template-columns:1fr 1fr}.garbalia-table-cancel-actions{grid-template-columns:1fr}.garbalia-table-cancel-back{order:2}}
    `;
    document.head.appendChild(style);
  }

  function createModal(tableId, tableName, totalText, isTakeaway) {
    const overlay = document.createElement('div');
    overlay.className = 'garbalia-table-cancel-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', isTakeaway ? 'გატანის შეკვეთის გაუქმება' : 'მაგიდის შეკვეთის გაუქმება');
    overlay.innerHTML = `
      <div class="garbalia-table-cancel-dialog">
        <button class="garbalia-table-cancel-close" type="button" aria-label="დახურვა">×</button>
        <div class="garbalia-table-cancel-icon">!</div>
        <h3>${isTakeaway ? 'გატანის გაუქმება' : 'მაგიდის გაუქმება'}</h3>
        <p>შეკვეთა მთლიანად გაუქმდება, გაყიდვაში არ ჩაითვლება და მიზეზი ისტორიაში დარჩება.</p>
        <div class="garbalia-table-cancel-summary">
          <div><span>ადგილი</span><strong></strong></div>
          <div><span>მიმდინარე ჯამი</span><strong></strong></div>
        </div>
        <form class="garbalia-table-cancel-form">
          <input type="hidden" name="table_id">
          <label>გაუქმების მიზეზი
            <select name="cancel_reason" required>
              <option value="კლიენტმა გადაიფიქრა">კლიენტმა გადაიფიქრა</option>
              <option value="სტუმარი წავიდა">სტუმარი წავიდა</option>
              <option value="შეცდომით გაიხსნა">შეცდომით გაიხსნა</option>
              <option value="შეკვეთა დუბლირებულია">შეკვეთა დუბლირებულია</option>
              <option value="სხვა">სხვა მიზეზი</option>
            </select>
          </label>
          <label>დამატებითი კომენტარი
            <input name="cancel_reason_custom" maxlength="180" placeholder="მაგ: სტუმარმა ლოდინი აღარ ისურვა">
          </label>
          <div class="garbalia-table-cancel-warning">სამზარეულოში უკვე გაგზავნილი პროდუქტებიც გაუქმებულად ჩაინიშნება. თუ მომზადება დაწყებულია, აუცილებლად აცნობე სამზარეულოს.</div>
          <div class="garbalia-table-cancel-error" role="alert"></div>
          <div class="garbalia-table-cancel-actions">
            <button class="garbalia-table-cancel-back" type="button">უკან დაბრუნება</button>
            <button class="garbalia-table-cancel-confirm" type="submit">დიახ, გაუქმება</button>
          </div>
        </form>
      </div>
    `;

    const summaryValues = overlay.querySelectorAll('.garbalia-table-cancel-summary strong');
    summaryValues[0].textContent = tableName;
    summaryValues[1].textContent = totalText;
    overlay.querySelector('input[name="table_id"]').value = tableId;

    const close = function () {
      overlay.classList.remove('is-open');
      document.body.classList.remove('garbalia-table-cancel-open');
      window.setTimeout(function () { overlay.remove(); }, 170);
    };

    overlay.querySelector('.garbalia-table-cancel-close').addEventListener('click', close);
    overlay.querySelector('.garbalia-table-cancel-back').addEventListener('click', close);
    overlay.addEventListener('click', function (event) {
      if (event.target === overlay) close();
    });
    document.addEventListener('keydown', function escapeHandler(event) {
      if (event.key === 'Escape' && overlay.isConnected) {
        close();
        document.removeEventListener('keydown', escapeHandler);
      }
    });

    const form = overlay.querySelector('form');
    const errorBox = overlay.querySelector('.garbalia-table-cancel-error');
    const confirmButton = overlay.querySelector('.garbalia-table-cancel-confirm');
    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      errorBox.classList.remove('is-visible');
      errorBox.textContent = '';
      confirmButton.disabled = true;
      confirmButton.textContent = 'უქმდება…';

      try {
        const result = await window.garbaliaActionRequest('/cancel-table-order.php', {
          method: 'POST',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
          body: new FormData(form)
        });
        confirmButton.textContent = 'გაუქმდა ✓';
        window.setTimeout(function () {
          window.location.href = result.redirect || '/tables';
        }, 350);
      } catch (error) {
        errorBox.textContent = error && error.message ? error.message : 'გაუქმება ვერ მოხერხდა. სცადე თავიდან.';
        errorBox.classList.add('is-visible');
        confirmButton.disabled = !!window.garbaliaOutcomeUnknown;
        confirmButton.textContent = 'დიახ, გაუქმება';
      }
    });

    document.body.appendChild(overlay);
    document.body.classList.add('garbalia-table-cancel-open');
    requestAnimationFrame(function () { overlay.classList.add('is-open'); });
    window.setTimeout(function () { overlay.querySelector('select').focus(); }, 120);
  }

  function addCancelButton() {
    const tableId = tableIdFromPage();
    if (!tableId || document.querySelector('.garbalia-cancel-order-zone')) return;

    const closeForm = document.querySelector('.current-order-card .close-form');
    if (!closeForm) return;

    const heading = document.querySelector('.page-head h1');
    const tableName = heading ? heading.textContent.trim() : 'მაგიდა';
    const total = document.querySelector('.page-head .total-box');
    const totalText = total ? total.textContent.trim() : '—';
    const isTakeaway = /^გატანა\b/i.test(tableName);

    const zone = document.createElement('div');
    zone.className = 'garbalia-cancel-order-zone';
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'garbalia-cancel-order-btn';
    button.textContent = isTakeaway ? 'გატანის შეკვეთის გაუქმება' : 'მაგიდის გაუქმება';
    button.addEventListener('click', function () {
      createModal(tableId, tableName, totalText, isTakeaway);
    });
    zone.appendChild(button);
    closeForm.insertAdjacentElement('afterend', zone);
  }

  function start() {
    injectStyles();
    addCancelButton();
    window.setTimeout(addCancelButton, 250);
    window.setTimeout(addCancelButton, 700);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
