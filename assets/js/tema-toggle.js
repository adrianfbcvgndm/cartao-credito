/* ============================================================
   SHIM DE COMPATIBILIDADE · tema-toggle.js
   ------------------------------------------------------------
   O toggle de tema agora vive no header do RP Enterprise DS
   (assets/ds/js/ds.js). Este shim mantém páginas legadas que
   renderizam #tn-theme-toggle funcionando com o novo DS.
   Persiste em localStorage na MESMA chave antiga: 'rp-theme'.
   ============================================================ */
(function () {
  'use strict';
  function init() {
    var mount = document.getElementById('tn-theme-toggle');
    if (!mount || mount.dataset.dsReady) return;
    mount.dataset.dsReady = '1';
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ds-icon-btn';
    btn.setAttribute('aria-label', 'Alternar tema claro/escuro');
    btn.setAttribute('data-ds-theme-icon', '');
    btn.addEventListener('click', function () {
      if (window.dsToggleTheme) window.dsToggleTheme();
    });
    mount.appendChild(btn);
    /* desenha o ícone inicial */
    var t = document.documentElement.getAttribute('data-rp-theme') || 'light';
    btn.innerHTML = '<i data-lucide="' + (t === 'dark' ? 'sun' : 'moon') + '"></i>';
    if (window.lucide) window.lucide.createIcons();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
