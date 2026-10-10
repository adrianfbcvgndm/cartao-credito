/* ============================================================
   RP ENTERPRISE · TEMA DE GRÁFICOS (Modelo A · Enterprise Clean · paleta RxMax 25/09/2026)
   ------------------------------------------------------------
   Padroniza os 7 gráficos do addon: tipografia Inter, grade
   discreta, tooltips escuros, linhas suaves com degradê sutil,
   roscas finas com TOTAL no centro e barras arredondadas.
   Uso: incluir depois do chart.umd e antes dos scripts das
   páginas. Expõe window.rpChart com helpers.
   ============================================================ */
(function () {
  'use strict';
  if (typeof Chart === 'undefined') return;

  function cssVar(name, fallback) {
    var scope = document.querySelector('.tn-scope') || document.documentElement;
    var v = getComputedStyle(scope).getPropertyValue(name).trim();
    return v || fallback;
  }
  function isDark() {
    return document.documentElement.getAttribute('data-rp-theme') === 'dark';
  }

  /* ---------- defaults globais ---------- */
  Chart.defaults.font.family = "'Inter',-apple-system,'Segoe UI',sans-serif";
  Chart.defaults.font.size = 11.5;
  Chart.defaults.color = cssVar('--ds-text-2', '#6B7280');
  Chart.defaults.plugins.tooltip.backgroundColor = '#0F172A';
  Chart.defaults.plugins.tooltip.padding = 10;
  Chart.defaults.plugins.tooltip.cornerRadius = 8;
  Chart.defaults.plugins.tooltip.titleFont = { weight: '600' };
  Chart.defaults.plugins.tooltip.usePointStyle = true;
  Chart.defaults.plugins.tooltip.boxWidth = 8;
  Chart.defaults.plugins.tooltip.boxHeight = 8;
  Chart.defaults.plugins.tooltip.boxPadding = 4;
  Chart.defaults.plugins.legend.labels.usePointStyle = true;
  Chart.defaults.plugins.legend.labels.pointStyle = 'circle';
  Chart.defaults.plugins.legend.labels.boxWidth = 7;
  Chart.defaults.plugins.legend.labels.padding = 12;

  /* ---------- paleta categórica validada ---------- */
  /* paleta do painel RxMax (dash08): azul, laranja, roxo, verde, grafite… */
  var PALETA = ['#2a78d6', '#eb6834', '#4a3aa7', '#1baf7a', '#0f172a',
                '#3987e5', '#9A5B00', '#C02B21', '#8a8f98'];

  /* ---------- plugin: total no centro das roscas ---------- */
  var rpCenterText = {
    id: 'rpCenterText',
    afterDraw: function (chart) {
      var opts = chart.options.plugins && chart.options.plugins.rpCenter;
      if (!opts || chart.config.type !== 'doughnut') return;
      var meta = chart.getDatasetMeta(0);
      if (!meta || !meta.data || !meta.data.length) return;
      var x = meta.data[0].x, y = meta.data[0].y;
      var ctx = chart.ctx;
      var titulo = typeof opts.title === 'function' ? opts.title(chart) : opts.title;
      var sub = typeof opts.subtitle === 'function' ? opts.subtitle(chart) : opts.subtitle;
      ctx.save();
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.font = "700 19px 'Inter',sans-serif";
      ctx.fillStyle = cssVar('--ds-text', '#111827');
      ctx.fillText(titulo || '', x, y - (sub ? 8 : 0));
      if (sub) {
        ctx.font = "500 10.5px 'Inter',sans-serif";
        ctx.fillStyle = cssVar('--ds-text-3', '#9CA3AF');
        ctx.fillText(sub, x, y + 12);
      }
      ctx.restore();
    }
  };
  Chart.register(rpCenterText);

  /* ---------- plugin: corrige legenda de datasets tracejados ----------
     No primeiro render, itens de legenda de linhas com borderDash podem
     não aparecer (quirk do Chart.js). Um update('none') logo após o
     primeiro desenho resolve, sem reanimar. */
  Chart.register({
    id: 'rpLegendRefresh',
    afterRender: function (chart) {
      if (chart.$rpLegendRefreshed) return;
      var temDash = (chart.data.datasets || []).some(function (d) {
        return d.borderDash && d.borderDash.length;
      });
      chart.$rpLegendRefreshed = true;
      if (temDash) {
        requestAnimationFrame(function () {
          try { chart.update('none'); } catch (e) {}
        });
      }
    }
  });

  /* ---------- helpers ---------- */
  function fmtBRL(v) {
    return 'R$ ' + Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function fmtCompact(v) {
    v = Number(v || 0);
    if (Math.abs(v) >= 1000000) return 'R$ ' + (v / 1000000).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + 'M';
    if (Math.abs(v) >= 1000) return 'R$ ' + (v / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + 'k';
    return 'R$ ' + v.toLocaleString('pt-BR', { maximumFractionDigits: 0 });
  }
  function gradiente(ctx2d, hex, altura) {
    var g = ctx2d.createLinearGradient(0, 0, 0, altura || 260);
    g.addColorStop(0, hex + '22');
    g.addColorStop(1, hex + '00');
    return g;
  }
  function corGrade() { return isDark() ? 'rgba(148,163,184,.08)' : 'rgba(15,23,42,.05)'; }
  function corSuperficie() { return isDark() ? cssVar('--ds-surface', '#111827') : '#ffffff'; }

  /* eixo X sem grade; eixo Y com grade suave e poucos ticks */
  function eixoX(extra) {
    return Object.assign({ grid: { display: false }, border: { display: false } }, extra || {});
  }
  function eixoY(extra) {
    return Object.assign({
      grid: { color: corGrade() }, border: { display: false },
      ticks: { maxTicksLimit: 5, callback: function (v) { return fmtCompact(v); } }
    }, extra || {});
  }

  /* dataset de LINHA no padrão do modelo (degradê sutil, sem pontos) */
  function linha(ctx2d, cor, dados, label, altura) {
    return {
      label: label, data: dados,
      borderColor: cor, borderWidth: 2, tension: 0.4,
      pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: cor,
      fill: true, backgroundColor: gradiente(ctx2d, cor, altura)
    };
  }

  /* opções de ROSCA no padrão do modelo (fina, com total no centro) */
  function opcoesRosca(tituloCentro, subCentro, extraPlugins) {
    return {
      responsive: true, maintainAspectRatio: false, cutout: '74%',
      plugins: Object.assign({
        legend: { position: 'bottom' },
        rpCenter: { title: tituloCentro, subtitle: subCentro }
      }, extraPlugins || {})
    };
  }

  /* estiliza dataset de ROSCA (bordas na cor da superfície, cantos suaves) */
  function estiloRosca(ds) {
    ds.borderWidth = 3;
    ds.borderColor = corSuperficie();
    ds.borderRadius = 6;
    ds.spacing = 2;
    ds.hoverOffset = 6;
    return ds;
  }

  /* estiliza dataset de BARRAS (cantos arredondados, espessura fixa) */
  function estiloBarra(ds, horizontal) {
    ds.borderRadius = horizontal
      ? { topRight: 5, bottomRight: 5 }
      : { topLeft: 5, topRight: 5 };
    ds.borderWidth = 0;
    ds.maxBarThickness = horizontal ? 16 : 34;
    return ds;
  }

  window.rpChart = {
    PALETA: PALETA, fmtBRL: fmtBRL, fmtCompact: fmtCompact,
    gradiente: gradiente, corGrade: corGrade, corSuperficie: corSuperficie,
    eixoX: eixoX, eixoY: eixoY, linha: linha,
    opcoesRosca: opcoesRosca, estiloRosca: estiloRosca, estiloBarra: estiloBarra
  };
})();
