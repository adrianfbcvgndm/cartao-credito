/* ============================================================
   RP ENTERPRISE · MODAIS
   API global:
     dsModal.open(id)            — abre modal existente no DOM
     dsModal.close(id|el)        — fecha
     dsModal.confirm({title, message, tone, okText, cancelText})
       → Promise<boolean>  (substituto premium do confirm())
   Fecha com ESC, clique no backdrop e botão X.
   ============================================================ */
(function () {
  'use strict';

  function backdropOf(el) {
    return el.classList.contains('ds-modal-backdrop') ? el : el.closest('.ds-modal-backdrop');
  }

  function open(id) {
    var el = typeof id === 'string' ? document.getElementById(id) : id;
    if (!el) return;
    var bd = backdropOf(el) || el;
    bd.classList.add('open');
    bd.setAttribute('aria-hidden', 'false');
    var focusable = bd.querySelector('button,input,select,textarea,a[href]');
    if (focusable) setTimeout(function () { focusable.focus(); }, 60);
  }

  function close(id) {
    var el = typeof id === 'string' ? document.getElementById(id) : id;
    if (!el) return;
    var bd = backdropOf(el) || el;
    bd.classList.remove('open');
    bd.setAttribute('aria-hidden', 'true');
    if (bd.dataset.dsEphemeral) setTimeout(function () { bd.remove(); }, 280);
  }

  document.addEventListener('click', function (ev) {
    var opener = ev.target.closest('[data-ds-modal-open]');
    if (opener) { ev.preventDefault(); open(opener.getAttribute('data-ds-modal-open')); return; }
    var closer = ev.target.closest('[data-ds-modal-close], .ds-modal-close');
    if (closer) { var bd = backdropOf(closer); if (bd) close(bd); return; }
    if (ev.target.classList && ev.target.classList.contains('ds-modal-backdrop')) close(ev.target);
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Escape') return;
    var openBd = document.querySelector('.ds-modal-backdrop.open');
    if (openBd) close(openBd);
  });

  var TONE_ICON = {
    danger:  { cls: 'danger',  svg: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4M12 17h.01"/></svg>' },
    warning: { cls: 'warning', svg: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>' },
    success: { cls: 'success', svg: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>' },
    info:    { cls: '',        svg: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>' }
  };

  function confirmModal(opts) {
    opts = opts || {};
    var tone = TONE_ICON[opts.tone] ? opts.tone : 'info';
    return new Promise(function (resolve) {
      var bd = document.createElement('div');
      bd.className = 'ds-modal-backdrop tn-scope-modal';
      bd.dataset.dsEphemeral = '1';
      bd.setAttribute('role', 'dialog');
      bd.setAttribute('aria-modal', 'true');
      bd.innerHTML =
        '<div class="ds-modal sm tn-scope" role="document">' +
          '<div class="ds-modal-head">' +
            '<div class="ds-modal-ico ' + TONE_ICON[tone].cls + '">' + TONE_ICON[tone].svg + '</div>' +
            '<div class="ds-modal-title"><b></b><small></small></div>' +
          '</div>' +
          '<div class="ds-modal-body"></div>' +
          '<div class="ds-modal-foot">' +
            '<button type="button" class="tn-btn" data-act="cancel"></button>' +
            '<button type="button" class="tn-btn ' + (tone === 'danger' ? 'tn-btn-red' : 'tn-btn-primary') + '" data-act="ok"></button>' +
          '</div>' +
        '</div>';
      bd.querySelector('b').textContent = opts.title || 'Confirmar ação';
      bd.querySelector('small').textContent = opts.subtitle || '';
      bd.querySelector('.ds-modal-body').textContent = opts.message || 'Deseja continuar?';
      bd.querySelector('[data-act=cancel]').textContent = opts.cancelText || 'Cancelar';
      bd.querySelector('[data-act=ok]').textContent = opts.okText || 'Confirmar';

      function done(v) { close(bd); resolve(v); }
      bd.querySelector('[data-act=cancel]').addEventListener('click', function () { done(false); });
      bd.querySelector('[data-act=ok]').addEventListener('click', function () { done(true); });
      bd.addEventListener('click', function (ev) { if (ev.target === bd) done(false); });

      document.body.appendChild(bd);
      requestAnimationFrame(function () { open(bd); });
    });
  }

  window.dsModal = { open: open, close: close, confirm: confirmModal };
})();
