/* ============================================================
   RP ENTERPRISE · TOASTS
   API global: dsToast(msg, tipo, opts)
     tipo: 'success' | 'danger' | 'warning' | 'info'
     opts: { title, duration (ms, 0 = fixo) }
   ============================================================ */
(function () {
  'use strict';

  var ICONS = {
    success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
    danger:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/></svg>',
    warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4M12 17h.01"/></svg>',
    info:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>'
  };
  var CLOSE = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>';

  function region() {
    var r = document.querySelector('.ds-toast-region');
    if (!r) {
      r = document.createElement('div');
      r.className = 'ds-toast-region';
      r.setAttribute('role', 'status');
      r.setAttribute('aria-live', 'polite');
      document.body.appendChild(r);
    }
    return r;
  }

  function dismiss(el) {
    if (!el || el.classList.contains('closing')) return;
    el.classList.add('closing');
    setTimeout(function () { el.remove(); }, 260);
  }

  window.dsToast = function (msg, tipo, opts) {
    tipo = ICONS[tipo] ? tipo : 'info';
    opts = opts || {};
    var dur = (opts.duration === 0) ? 0 : (opts.duration || 4200);

    var t = document.createElement('div');
    t.className = 'ds-toast ds-toast-' + tipo;
    t.innerHTML =
      '<div class="ds-toast-ico">' + ICONS[tipo] + '</div>' +
      '<div class="ds-toast-body">' +
        (opts.title ? '<b></b>' : '') + '<span></span>' +
      '</div>' +
      '<button type="button" class="ds-toast-close" aria-label="Fechar">' + CLOSE + '</button>' +
      (dur ? '<div class="ds-toast-progress" style="animation-duration:' + dur + 'ms"></div>' : '');

    if (opts.title) t.querySelector('b').textContent = opts.title;
    t.querySelector('span').textContent = String(msg == null ? '' : msg);
    t.querySelector('.ds-toast-close').addEventListener('click', function () { dismiss(t); });

    region().appendChild(t);
    if (dur) setTimeout(function () { dismiss(t); }, dur);
    return t;
  };

  /* Açúcar sintático */
  ['success', 'danger', 'warning', 'info'].forEach(function (k) {
    window.dsToast[k] = function (msg, opts) { return window.dsToast(msg, k, opts); };
  });
})();
