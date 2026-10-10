/* ============================================================
   RP ENTERPRISE · NÚCLEO JS
   Tema claro/escuro, sidebar (collapse + drawer mobile),
   dropdowns, ripple, busca global e inicialização dos ícones.
   Sem dependências além do lucide (vendor).
   ============================================================ */
(function () {
  'use strict';

  var doc = document, root = doc.documentElement;

  /* ---------- TEMA ---------- */
  function getTheme() {
    try { return localStorage.getItem('rp-theme') || 'light'; } catch (e) { return 'light'; }
  }
  function applyTheme(t) {
    root.setAttribute('data-rp-theme', t);
    try { localStorage.setItem('rp-theme', t); } catch (e) {}
    var ic = doc.querySelector('[data-ds-theme-icon]');
    if (ic && window.lucide) {
      ic.innerHTML = '<i data-lucide="' + (t === 'dark' ? 'sun' : 'moon') + '"></i>';
      window.lucide.createIcons({ attrs: {} , nameAttr: 'data-lucide' });
    }
  }
  window.dsToggleTheme = function () {
    applyTheme(getTheme() === 'dark' ? 'light' : 'dark');
  };

  /* ---------- SIDEBAR ---------- */
  window.dsToggleSidebar = function () {
    var app = doc.querySelector('.tn-scope.ds-app');
    if (!app) return;
    if (window.innerWidth <= 900) {
      app.classList.toggle('ds-sidebar-open');
    } else {
      app.classList.toggle('ds-collapsed');
      try { localStorage.setItem('rp-sidebar', app.classList.contains('ds-collapsed') ? '1' : '0'); } catch (e) {}
    }
  };
  window.dsCloseSidebar = function () {
    var app = doc.querySelector('.tn-scope.ds-app');
    if (app) app.classList.remove('ds-sidebar-open');
  };

  /* ---------- DROPDOWNS (data-ds-dropdown) ---------- */
  function closeDropdowns(except) {
    doc.querySelectorAll('.ds-dropdown.open').forEach(function (d) {
      if (d !== except) d.classList.remove('open');
    });
  }
  doc.addEventListener('click', function (ev) {
    var trigger = ev.target.closest('[data-ds-dropdown]');
    if (trigger) {
      var dd = trigger.closest('.ds-dropdown');
      if (dd) { ev.preventDefault(); closeDropdowns(dd); dd.classList.toggle('open'); return; }
    }
    if (!ev.target.closest('.ds-dropdown')) closeDropdowns(null);
  });
  doc.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') { closeDropdowns(null); var s = doc.getElementById('ds-search-results'); if (s) s.remove(); }
  });

  /* ---------- RIPPLE nos botões ---------- */
  doc.addEventListener('pointerdown', function (ev) {
    var btn = ev.target.closest('.tn-btn, .ds-btn');
    if (!btn || btn.disabled) return;
    var rect = btn.getBoundingClientRect();
    var r = doc.createElement('span');
    var size = Math.max(rect.width, rect.height);
    r.className = 'ds-ripple';
    r.style.width = r.style.height = size + 'px';
    r.style.left = (ev.clientX - rect.left - size / 2) + 'px';
    r.style.top = (ev.clientY - rect.top - size / 2) + 'px';
    btn.appendChild(r);
    setTimeout(function () { r.remove(); }, 600);
  });

  /* ---------- BUSCA GLOBAL ---------- */
  var searchIndex = [];
  window.dsRegisterSearch = function (items) { searchIndex = searchIndex.concat(items || []); };

  function renderSearch(input) {
    var q = (input.value || '').trim().toLowerCase();
    var box = doc.getElementById('ds-search-results');
    if (!q) { if (box) box.remove(); return; }
    if (!box) {
      box = doc.createElement('div');
      box.id = 'ds-search-results';
      box.className = 'ds-search-results';
      input.parentElement.appendChild(box);
    }
    var hits = searchIndex.filter(function (it) {
      return (it.label + ' ' + (it.keywords || '')).toLowerCase().indexOf(q) !== -1;
    }).slice(0, 8);
    if (!hits.length) {
      box.innerHTML = '<div class="ds-search-empty">Nenhum resultado para “' + q.replace(/</g, '&lt;') + '”</div>';
      return;
    }
    box.innerHTML = hits.map(function (it) {
      return '<a href="' + it.href + '"><i data-lucide="' + (it.icon || 'corner-down-right') + '"></i>' + it.label + '</a>';
    }).join('');
    if (window.lucide) window.lucide.createIcons();
  }
  doc.addEventListener('input', function (ev) {
    if (ev.target.matches('.ds-search-input')) renderSearch(ev.target);
  });
  doc.addEventListener('click', function (ev) {
    if (!ev.target.closest('.ds-global-search')) {
      var b = doc.getElementById('ds-search-results');
      if (b) b.remove();
    }
  });
  /* atalho "/" foca a busca */
  doc.addEventListener('keydown', function (ev) {
    if (ev.key === '/' && !ev.target.closest('input,textarea,select,[contenteditable]')) {
      var inp = doc.querySelector('.ds-search-input');
      if (inp) { ev.preventDefault(); inp.focus(); }
    }
  });

  /* ---------- LOADING helper ---------- */
  window.dsBtnLoading = function (btn, on) {
    if (typeof btn === 'string') btn = doc.querySelector(btn);
    if (!btn) return;
    btn.classList.toggle('ds-btn-loading', on !== false);
    btn.disabled = on !== false;
  };

  /* ---------- INIT ---------- */
  function init() {
    applyTheme(getTheme());
    /* restaura sidebar colapsada */
    try {
      if (localStorage.getItem('rp-sidebar') === '1' && window.innerWidth > 900) {
        var app = doc.querySelector('.tn-scope.ds-app');
        if (app) app.classList.add('ds-collapsed');
      }
    } catch (e) {}
    /* ícones lucide */
    if (window.lucide) window.lucide.createIcons();
    /* skeleton -> conteúdo: marca app como carregado */
    doc.querySelectorAll('[data-ds-skeleton]').forEach(function (el) { el.remove(); });
  }
  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', init);
  else init();
})();
