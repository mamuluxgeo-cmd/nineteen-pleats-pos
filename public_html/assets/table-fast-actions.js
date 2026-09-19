(function () {
  'use strict';

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];
    });
  }

  function money(value) {
    return (Number(value) || 0).toFixed(2) + ' ₾';
  }

  function ensureCloseForm(card, tableId) {
    if (!card || card.querySelector('.close-form')) return;
    var head = document.querySelector('.page-head[data-order-id]');
    var orderId = head ? Number(head.dataset.orderId) || 0 : 0;
    var serviceRate = head ? head.dataset.serviceRate : '';
    card.insertAdjacentHTML('beforeend',
      '<hr><h2>მაგიდის დახურვა</h2>' +
      '<form class="close-form" method="post" data-service-rate="' + esc(serviceRate) + '">' +
      '<input type="hidden" name="action" value="close_order">' +
      '<input type="hidden" name="table_id" value="' + esc(tableId) + '">' +
      '<input type="hidden" name="expected_order_id" value="' + orderId + '">' +
      '<label>გადახდის ტიპი<select id="payment_type" name="payment_type"><option value="cash">ნაღდი</option><option value="card">ბარათი</option><option value="mixed">შერეული</option></select></label>' +
      '<div id="mixed_fields" class="mixed-fields"><label>ნაღდი<input name="cash_amount" type="number" step="0.01" min="0"></label><label>ბარათი<input name="card_amount" type="number" step="0.01" min="0"></label></div>' +
      '<button class="btn success">საბოლოო ანგარიში</button></form>'
    );
    if (typeof enhanceMixedPaymentAutoFill === 'function') enhanceMixedPaymentAutoFill();
  }

  function addItemToOrder(data, tableId) {
    var card = document.querySelector('.current-order-card');
    if (!card) return false;

    var empty = card.querySelector(':scope > p.muted');
    if (empty) empty.remove();

    var item = data.item || {};
    var line = document.createElement('div');
    line.className = 'order-item unsent-item';
    line.innerHTML =
      '<div><strong>' + esc(item.quantity) + ' x ' + esc(item.name) + '</strong>' +
      '<small>' + money(item.price) + ' / ჯამი: ' + money((Number(item.price) || 0) * (Number(item.quantity) || 0)) + '</small>' +
      (item.comment ? '<em>' + esc(item.comment) + '</em>' : '') +
      '<small class="unsent-text">გასაგზავნია</small></div>' +
      '<form class="cancel-form" method="post">' +
      '<input type="hidden" name="action" value="cancel_item">' +
      '<input type="hidden" name="table_id" value="' + esc(tableId) + '">' +
      '<input type="hidden" name="item_id" value="' + esc(item.id) + '">' +
      '<select name="cancel_reason"><option>შეცდომით დაემატა</option><option>კლიენტმა გადაიფიქრა</option><option>პროდუქტი აღარ არის</option><option>სხვა</option></select>' +
      '<input name="cancel_reason_custom" placeholder="დამატებით">' +
      '<button class="btn danger">გაუქმება</button></form>';

    var actions = card.querySelector('.actions');
    if (actions) card.insertBefore(line, actions);
    else card.appendChild(line);

    var sendButton = card.querySelector('.send-order-form button');
    if (sendButton) sendButton.disabled = false;

    var totalBox = document.querySelector('.total-box');
    if (totalBox && data.order) {
      totalBox.textContent = money(data.order.total);
      totalBox.dataset.subtotal = (Number(data.order.total) || 0).toFixed(2);
    }
    if (data.order) {
      var currentHead = document.querySelector('.page-head');
      if (currentHead) currentHead.dataset.orderId = String(data.order.id);
      card.querySelectorAll('input[name="expected_order_id"]').forEach(function (input) { input.value = String(data.order.id); });
    }

    if (data.order && data.order.receipt_number > 0 && !document.querySelector('[data-open-receipt-number]')) {
      var head = document.querySelector('.page-head');
      if (head && totalBox) {
        var badge = document.createElement('div');
        badge.className = 'pill';
        badge.setAttribute('data-open-receipt-number', '1');
        badge.style.cssText = 'background:#2b1b10;color:#fff;font-weight:950';
        badge.textContent = 'ქვითარი #' + data.order.receipt_number;
        head.insertBefore(badge, totalBox);
      }
    }

    ensureCloseForm(card, tableId);
    document.dispatchEvent(new CustomEvent('garbalia:order-updated'));
    if (typeof addCancelButton === 'function') addCancelButton();

    if (typeof markOrderItemStates === 'function') markOrderItemStates();
    if (typeof cleanupOrderDeleteControls === 'function') cleanupOrderDeleteControls();
    if (typeof addUnsentQuantityEditors === 'function') addUnsentQuantityEditors();
    return true;
  }

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || !form.classList || !form.classList.contains('product-row')) return;
    var action = form.querySelector('input[name="action"]');
    if (!action || action.value !== 'add_item') return;

    event.preventDefault();
    event.stopImmediatePropagation();
    if (form.dataset.fastAdding === '1') return;

    var button = form.querySelector('button[type="submit"],button:not([type])');
    var oldText = button ? button.textContent : '';
    form.dataset.fastAdding = '1';
    if (button) {
      button.disabled = true;
      button.textContent = 'ემატება…';
    }

    var data = new FormData(form);
    window.garbaliaActionRequest('/add-item-fast.php', {
      method: 'POST',
      body: data,
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}
    }).then(function (payload) {
      var tableIdInput = form.querySelector('input[name="table_id"]');
      addItemToOrder(payload, tableIdInput ? tableIdInput.value : '');
      var qty = form.querySelector('input[name="quantity"]');
      var comment = form.querySelector('input[name="comment"]');
      if (qty) qty.value = '1';
      if (comment) comment.value = '';
      if (button) {
        button.textContent = 'დაემატა ✓';
        window.setTimeout(function () {
          button.disabled = false;
          button.textContent = oldText || 'დამატება';
        }, 450);
      }
      form.dataset.fastAdding = '0';
    }).catch(function (error) {
      form.dataset.fastAdding = '0';
      if (button) {
        button.disabled = !!window.garbaliaOutcomeUnknown;
        button.textContent = oldText || 'დამატება';
      }
      alert(error && error.message ? error.message : 'პროდუქტის დამატება ვერ მოხერხდა.');
    });
  }, true);
})();
