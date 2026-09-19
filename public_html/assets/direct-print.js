(function () {
  function addReceiptSettingsNav() {
    const nav = document.querySelector('.nav');
    if (!nav || !nav.querySelector('a[href*="products"]') || nav.querySelector('a[href*="receipts"]')) return;
    const link = document.createElement('a');
    link.href = '/receipts';
    link.textContent = 'ქვითრები';
    const statistics = nav.querySelector('a[href*="statistics"]');
    const history = nav.querySelector('a[href*="history"]');
    if (statistics) nav.insertBefore(link, statistics);
    else if (history) nav.insertBefore(link, history);
    else nav.appendChild(link);
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char];
    });
  }

  function unsentItems() {
    return Array.from(document.querySelectorAll('.current-order-card .order-item:not(.cancelled)')).filter(function (item) {
      return !item.querySelector('.sent') && !item.classList.contains('sent-item');
    });
  }

  function orderInfoRows() {
    const rows = [];
    const table = document.querySelector('.page-head h1');
    const items = unsentItems();
    rows.push({label: 'მაგიდა', value: table ? table.textContent.trim() : '—'});
    rows.push({label: 'გასაგზავნი', value: items.length + ' პროდუქტი'});
    items.slice(0, 8).forEach(function (item, index) {
      const name = item.querySelector('strong');
      rows.push({label: 'პროდუქტი ' + (index + 1), value: name ? name.textContent.trim() : 'პროდუქტი', className: 'order-line'});
    });
    if (items.length > 8) rows.push({label: 'დამატებით', value: '+' + (items.length - 8) + ' პროდუქტი'});
    return rows;
  }

  function loadingWindow(win, title, message) {
    win.document.open();
    win.document.write('<!doctype html><html lang="ka"><head><meta charset="utf-8"><title>ბეჭდვა</title><style>body{margin:0;display:grid;place-items:center;min-height:100vh;background:#f6efe4;color:#2b1b10;font-family:Arial,sans-serif;text-align:center}div{padding:24px}strong{display:block;font-size:20px;margin-bottom:8px}span{color:#7a6657}</style></head><body><div><strong>' + escapeHtml(title || 'ქვითრები მზადდება…') + '</strong><span>' + escapeHtml(message || 'ბეჭდვის ფანჯარა რამდენიმე წამში გაიხსნება.') + '</span></div></body></html>');
    win.document.close();
  }

  function printDocument(data) {
    const barText = escapeHtml(data.bar && data.bar.text ? data.bar.text : '');
    const kitchenText = escapeHtml(data.kitchen && data.kitchen.text ? data.kitchen.text : '');
    const barSize = Math.max(10, Math.min(18, Number(data.bar && data.bar.font_size) || 13));
    const kitchenSize = Math.max(10, Math.min(18, Number(data.kitchen && data.kitchen.font_size) || 14));

    return '<!doctype html><html lang="ka"><head><meta charset="utf-8"><title>ქვითარი #' + escapeHtml(data.receipt_number || '') + '</title><style>' +
      '@page{size:80mm auto;margin:3mm}' +
      'html,body{margin:0;padding:0;background:#fff;color:#000}' +
      'body{width:74mm;font-family:Arial,"Noto Sans Georgian",sans-serif}' +
      '.receipt{box-sizing:border-box;width:100%;padding:0 0 3mm;break-after:page;page-break-after:always}' +
      '.receipt:last-child{break-after:auto;page-break-after:auto}' +
      'pre{margin:0;white-space:pre-wrap;word-break:break-word;font-family:Arial,"Noto Sans Georgian",sans-serif;line-height:1.35}' +
      '</style></head><body>' +
      '<section class="receipt"><pre style="font-size:' + barSize + 'px">' + barText + '</pre></section>' +
      '<section class="receipt"><pre style="font-size:' + kitchenSize + 'px">' + kitchenText + '</pre></section>' +
      '<script>window.onload=function(){setTimeout(function(){window.print();},80)};window.onafterprint=function(){window.close()};<\/script>' +
      '</body></html>';
  }

  function singleReceiptDocument(text, fontSize, title) {
    return '<!doctype html><html lang="ka"><head><meta charset="utf-8"><title>' + escapeHtml(title || 'ქვითარი') + '</title><style>' +
      '@page{size:80mm auto;margin:3mm}html,body{margin:0;padding:0;background:#fff;color:#000}' +
      'body{width:74mm;font-family:Arial,"Noto Sans Georgian",sans-serif}' +
      'pre{margin:0;white-space:pre-wrap;word-break:break-word;font-family:Arial,"Noto Sans Georgian",sans-serif;font-size:' + fontSize + 'px;line-height:1.35}' +
      '</style></head><body><pre>' + escapeHtml(text) + '</pre><script>window.onload=function(){setTimeout(function(){window.print();},80)};window.onafterprint=function(){window.close()};<\/script></body></html>';
  }

  function unlockAndReload() {
    try { garbaliaAllowNavigation = true; } catch (error) {}
    window.setTimeout(function () { window.location.reload(); }, 450);
  }

  function goToTables(url) {
    try { garbaliaAllowNavigation = true; } catch (error) {}
    window.setTimeout(function () { window.location.href = url || '/tables'; }, 500);
  }

  function sendAndPrint(form) {
    const button = form.querySelector('button[type="submit"],button:not([type])');
    const originalText = button ? button.textContent : '';
    const printWindow = window.open('', '_blank', 'width=460,height=760');
    if (!printWindow) {
      alert('ბრაუზერმა ბეჭდვის ფანჯარა დაბლოკა. დაუშვი Pop-ups pos.cours.ge-სთვის.');
      return;
    }

    loadingWindow(printWindow, 'ქვითრები მზადდება…', 'ბარისა და სამზარეულოს ქვითრები რამდენიმე წამში გაიხსნება.');
    form.dataset.directPrinting = '1';
    if (button) {
      button.disabled = true;
      button.textContent = 'იგზავნება…';
    }

    fetch('/send_order_print.php', {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      headers: {'Accept':'application/json'}
    }).then(function (response) {
      return response.json().catch(function () { return {ok:false,message:'სერვერის პასუხი ვერ დამუშავდა.'}; }).then(function (data) {
        if (!response.ok || !data.ok) throw new Error(data.message || 'შეკვეთის გაგზავნა ვერ მოხერხდა.');
        return data;
      });
    }).then(function (data) {
      printWindow.document.open();
      printWindow.document.write(printDocument(data));
      printWindow.document.close();
      unlockAndReload();
    }).catch(function (error) {
      try { printWindow.close(); } catch (closeError) {}
      form.dataset.directPrinting = '0';
      if (button) {
        button.disabled = false;
        button.textContent = originalText;
      }
      alert(error && error.message ? error.message : 'შეკვეთის გაგზავნა ვერ მოხერხდა.');
    });
  }

  function closeAndPrint(form) {
    if (form.dataset.directClosePrinting === '1') return;
    const modal = document.querySelector('.garbalia-close-modal');
    const button = modal ? modal.querySelector('[data-confirm-close]') : null;
    const originalText = button ? button.textContent : '';
    const printWindow = window.open('', '_blank', 'width=460,height=760');
    if (!printWindow) {
      form.dataset.garbaliaConfirmedClose = '0';
      alert('ბრაუზერმა ბეჭდვის ფანჯარა დაბლოკა. დაუშვი Pop-ups pos.cours.ge-სთვის.');
      return;
    }

    loadingWindow(printWindow, 'საბოლოო ქვითარი მზადდება…', 'მაგიდა იხურება და ქვითარი პირდაპირ გადავა ბეჭდვაზე.');
    form.dataset.directClosePrinting = '1';
    if (button) {
      button.disabled = true;
      button.textContent = 'იხურება…';
    }

    fetch('/close_order_print.php', {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      headers: {'Accept':'application/json'}
    }).then(function (response) {
      return response.json().catch(function () { return {ok:false,message:'სერვერის პასუხი ვერ დამუშავდა.'}; }).then(function (data) {
        if (!response.ok || !data.ok) throw new Error(data.message || 'მაგიდის დახურვა ვერ მოხერხდა.');
        return data;
      });
    }).then(function (data) {
      const finalText = data.final && data.final.text ? data.final.text : '';
      const finalSize = Math.max(10, Math.min(18, Number(data.final && data.final.font_size) || 13));
      printWindow.document.open();
      printWindow.document.write(singleReceiptDocument(finalText, finalSize, 'საბოლოო ქვითარი #' + (data.receipt_number || '')));
      printWindow.document.close();
      goToTables(data.redirect || '/tables');
    }).catch(function (error) {
      try { printWindow.close(); } catch (closeError) {}
      form.dataset.directClosePrinting = '0';
      form.dataset.garbaliaConfirmedClose = '0';
      if (button) {
        button.disabled = false;
        button.textContent = originalText;
      }
      alert(error && error.message ? error.message : 'მაგიდის დახურვა ვერ მოხერხდა.');
    });
  }

  function installClosePrintSubmitBridge() {
    const proto = window.HTMLFormElement && window.HTMLFormElement.prototype;
    if (!proto || proto.__garbaliaClosePrintBridge) return;
    const nativeSubmit = proto.submit;
    Object.defineProperty(proto, '__garbaliaClosePrintBridge', {value: true, configurable: false});
    proto.submit = function () {
      const action = this.querySelector && this.querySelector('input[name="action"]');
      if (action && action.value === 'close_order' && this.dataset.garbaliaConfirmedClose === '1') {
        // A second confirmation while printing must not fall through to a native POST.
        if (this.dataset.directClosePrinting !== '1') closeAndPrint(this);
        return;
      }
      return nativeSubmit.call(this);
    };
  }

  function initDirectPrint() {
    document.addEventListener('submit', function (event) {
      const form = event.target;
      const action = form && form.querySelector ? form.querySelector('input[name="action"]') : null;
      if (!action || action.value !== 'send_order') return;
      event.preventDefault();
      event.stopImmediatePropagation();
      if (form.dataset.directPrinting === '1') return;

      const rows = orderInfoRows();
      if (typeof showGarbaliaConfirm === 'function') {
        showGarbaliaConfirm({
          id: 'garbalia-send-order-modal',
          title: 'შეკვეთის გაგზავნა',
          message: 'გაგზავნის შემდეგ ბარისა და სამზარეულოს ქვითრები პირდაპირ გაიხსნება ბეჭდვაზე.',
          info: rows,
          confirmText: 'დიახ, გაგზავნა',
          cancelText: 'არა',
          confirmClass: 'primary',
          onConfirm: function () { sendAndPrint(form); }
        });
      } else {
        sendAndPrint(form);
      }
    }, true);
  }

  function initConfiguredSinglePrint() {
    document.addEventListener('click', function (event) {
      const button = event.target.closest && event.target.closest('[data-print]');
      if (!button) return;
      const target = document.getElementById(button.getAttribute('data-print'));
      if (!target) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      const win = window.open('', '_blank', 'width=440,height=720');
      if (!win) {
        alert('ბრაუზერმა ბეჭდვის ფანჯარა დაბლოკა. დაუშვი Pop-ups pos.cours.ge-სთვის.');
        return;
      }
      const computed = window.getComputedStyle(target);
      const size = Math.max(10, Math.min(18, parseFloat(computed.fontSize) || 13));
      win.document.open();
      win.document.write(singleReceiptDocument(target.innerText, size, 'ქვითარი'));
      win.document.close();
    }, true);
  }

  function start() {
    addReceiptSettingsNav();
    installClosePrintSubmitBridge();
    initDirectPrint();
    initConfiguredSinglePrint();
    window.setTimeout(addReceiptSettingsNav, 300);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
