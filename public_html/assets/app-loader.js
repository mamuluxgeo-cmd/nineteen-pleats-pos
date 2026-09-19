(function () {
  'use strict';
  var ACTION_TIMEOUT_MS = 15000;
  var queue = Promise.resolve();
  var uncertainMessage = 'სერვერის პასუხი ვერ დადასტურდა. ოპერაცია შეიძლება შესრულებულია. განაახლე გვერდი და გადაამოწმე მაგიდა ან ისტორია.';

  function buttons(form) {
    return form.querySelectorAll('button[type="submit"],button:not([type]),input[type="submit"]');
  }
  function unlock(form) {
    delete form.dataset.garbaliaSubmitting;
    buttons(form).forEach(function (button) {
      if (button.dataset.wasDisabled !== '1') button.disabled = false;
      delete button.dataset.wasDisabled;
    });
  }
  function uncertain() {
    window.garbaliaOutcomeUnknown = true;
    document.querySelectorAll('form').forEach(function (form) {
      buttons(form).forEach(function (button) { button.disabled = true; });
    });
    if (!document.getElementById('pos-check-outcome')) {
      var notice = document.createElement('div');
      notice.id = 'pos-check-outcome';
      notice.className = 'warn';
      notice.setAttribute('role', 'alert');
      notice.textContent = uncertainMessage + ' ';
      var reload = document.createElement('button');
      reload.type = 'button';
      reload.className = 'btn';
      reload.textContent = 'გვერდის განახლება';
      reload.addEventListener('click', function () { window.location.reload(); });
      notice.appendChild(reload);
      var container = document.querySelector('main') || document.body;
      container.insertBefore(notice, container.firstChild);
    }
  }

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || event.defaultPrevented) return;
    if (window.garbaliaOutcomeUnknown || form.dataset.garbaliaSubmitting === '1') {
      event.preventDefault();
      event.stopImmediatePropagation();
      return;
    }
    form.dataset.garbaliaSubmitting = '1';
    buttons(form).forEach(function (button) {
      if (button.disabled) button.dataset.wasDisabled = '1';
      button.disabled = true;
    });
    // A timer must not unlock a write while the server may still be executing it.
  });
  window.addEventListener('pageshow', function () {
    if (!window.garbaliaOutcomeUnknown) document.querySelectorAll('form[data-garbalia-submitting="1"]').forEach(unlock);
  });
  var proto = window.HTMLFormElement && window.HTMLFormElement.prototype;
  if (proto) {
    var nativeSubmit = proto.submit;
    proto.submit = function () {
      if (window.garbaliaOutcomeUnknown) { uncertain(); return; }
      return nativeSubmit.call(this);
    };
  }

  function perform(url, init) {
    if (window.garbaliaOutcomeUnknown) return Promise.reject(new Error(uncertainMessage));
    var controller = window.AbortController ? new window.AbortController() : null;
    var options = Object.assign({}, init || {});
    if (controller) options.signal = controller.signal;
    var timer;
    var deadline = new Promise(function (resolve, reject) {
      timer = window.setTimeout(function () {
        if (controller) controller.abort();
        var error = new Error(uncertainMessage);
        error.outcomeUnknown = true;
        reject(error);
      }, ACTION_TIMEOUT_MS);
    });
    var request = Promise.resolve().then(function () { return window.fetch(url, options); }).then(function (response) {
      return response.json().then(function (payload) {
        if (!payload || typeof payload.ok !== 'boolean') throw new Error(uncertainMessage);
        if (!response.ok || !payload.ok) {
          var error = new Error(payload.message || 'ოპერაცია ვერ შესრულდა.');
          error.knownRejection = response.status >= 400 && response.status < 500;
          throw error;
        }
        return payload;
      });
    });
    // The deadline includes reading the body, not just receiving HTTP headers.
    return Promise.race([request, deadline]).catch(function (error) {
      if (!error.knownRejection) {
        error.outcomeUnknown = true;
        error.message = uncertainMessage;
        uncertain();
      }
      throw error;
    }).finally(function () { window.clearTimeout(timer); });
  }
  window.garbaliaActionRequest = function (url, init) {
    // Preserve click order across different product forms on this terminal.
    // This queues each intentional click once; it never retries a failed POST.
    var result = queue.then(function () { return perform(url, init); });
    queue = result.catch(function () {});
    return result;
  };
})();
