<?php
// Addon Cartão de Crédito (09/10/2026): separado do Resumo_Pagamentos.
// NAO incluir addons.class.php aqui: a copia distribuida com o addon bloqueia
// o acesso ("Acesso negado") neste servidor. A cor do menu e resolvida via CSS.
require_once __DIR__ . '/rp_config.php';
require_once __DIR__ . '/financeiro_core.php';

// Trava de login do addon (sessao MK-AUTH ativa)
rp_require_mkauth_session();

if (!defined('ADMIN2URL')) {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('ADMIN2URL', $protocol . "://" . $host . "/admin/");
}
if (!defined('KEY2PROVEDOR')) define('KEY2PROVEDOR', 'MKAUTH');
if (!defined('DIR2ADMIN'))   define('DIR2ADMIN', '/opt/mk-auth/admin/');
if (!defined('DIR2INCLUDE')) define('DIR2INCLUDE', '/opt/mk-auth/include/');

if (file_exists('/opt/mk-auth/include/configure.php')) {
    @include_once('/opt/mk-auth/include/configure.php');
}

// Carrega Manifest do addon (se nao definido pelo addons.class.php)
if (!isset($Manifest) || !is_object($Manifest)) {
    $mf = __DIR__ . '/manifest.json';
    if (is_file($mf)) {
        $Manifest = json_decode(file_get_contents($mf));
    } else {
        $Manifest = (object) ['name' => 'Cartão de Crédito'];
    }
}

// Detecta aba ativa
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'visao';
$paginas = [
    'visao'       => 'visao_geral.php',
    'recuperar'   => 'recuperar_pagamentos.php',
    'auditoria'   => 'auditoria_mp.php',
    'autopay'     => 'cfg_autopay.php',
    'credenciais' => 'credenciais.php',
];
if (!isset($paginas[$tab])) $tab = 'visao';

// ===== Menu (pílulas soltas, uma por tela) =====
$nav_meta = [
    'visao'       => ['label' => 'Visão geral',          'icon' => 'credit-card',  'cor' => '#2a78d6', 'desc' => 'Quanto entrou no cartão, taxas e quem pagou'],
    'recuperar'   => ['label' => 'Recuperar pagamentos', 'icon' => 'rotate-ccw',   'cor' => '#eb6834', 'desc' => 'Pagamento aprovado no Mercado Pago que não baixou a fatura'],
    'auditoria'   => ['label' => 'Auditoria MP',         'icon' => 'shield-check', 'cor' => '#4a3aa7', 'desc' => 'Confere o Mercado Pago contra as faturas do MK-Auth'],
    'autopay'     => ['label' => 'Débito automático',    'icon' => 'zap',          'cor' => '#eda100', 'desc' => 'Clientes com cobrança automática no cartão'],
    'credenciais' => ['label' => 'Credenciais',          'icon' => 'key-round',    'cor' => '#59636e', 'desc' => 'Chaves do Mercado Pago e mensagens ao cliente'],
];

$tab_atual   = $nav_meta[$tab];
$addon_nome  = htmlspecialchars($Manifest->name ?? 'Cartão de Crédito');
$addon_ver   = htmlspecialchars((string)($Manifest->version ?? ''));
$addon_autor = htmlspecialchars($Manifest->author ?? '');

// Usuario logado (somente exibicao)
if (session_status() === PHP_SESSION_NONE) @session_start();
$ui_user = '';
foreach (['login', 'usuario', 'user', 'admin'] as $k) {
    if (!empty($_SESSION[$k]) && is_string($_SESSION[$k])) { $ui_user = $_SESSION[$k]; break; }
}
if ($ui_user === '') $ui_user = 'Administrador';
$ui_user = htmlspecialchars($ui_user);
$ui_iniciais = strtoupper(substr($ui_user, 0, 1) . (strpos($ui_user, ' ') !== false ? substr($ui_user, strpos($ui_user, ' ') + 1, 1) : (strlen($ui_user) > 1 ? substr($ui_user, 1, 1) : '')));
?>
<!DOCTYPE html>
<html lang="pt-BR" class="has-navbar-fixed-top">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="iso-8859-1">
    <title>MK - AUTH :: <?php echo $addon_nome; ?></title>

    <!-- Inclui arquivos de estilo do MK-AUTH (IDENTICO ao addon original) -->
    <link href="../../estilos/mk-auth.css" rel="stylesheet" type="text/css" />
    <link href="../../estilos/font-awesome.css" rel="stylesheet" type="text/css" />
    <link href="../../estilos/bi-icons.css" rel="stylesheet" type="text/css" />

    <!-- Inclui bibliotecas JavaScript do MK-AUTH (IDENTICO ao addon original) -->
    <script src="../../scripts/jquery.js"></script>
    <script src="../../scripts/mk-auth.js"></script>

    <!-- Aplica o tema do addon o mais cedo possivel (evita flash) -->
    <script>
    (function(){
      try {
        var t = 'light'; try { localStorage.setItem('rp-theme','light'); } catch(e){}
        if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-rp-theme', t);
      } catch(e){}
    })();
    </script>

    <!-- RP Enterprise Design System (escopado em .tn-scope) -->
    <link rel="stylesheet" href="assets/ds/ds.css?v=20260925">
    <!-- Menu no topo (estilo painel RxMax) -->
    <link rel="stylesheet" href="assets/ds/pages/topmenu.css?v=20260922c">
    <!-- Pele RxMax Speed (grafite, cards, abas) — 25/09/2026 -->
    <link rel="stylesheet" href="assets/ds/pages/rxmax.css?v=20260925">
    <!-- Menu em pílulas (mesmo visual do Dashboard RxMax) — 09/10/2026 -->
    <link rel="stylesheet" href="assets/ds/pages/menu_p3.css?v=20261009d">
    <!-- Pele P3: o addon inteiro no visual do Dashboard RxMax — 09/10/2026 -->
    <link rel="stylesheet" href="assets/ds/pages/pele_p3.css?v=20261009b">

    <style>
      .tn-scope.rp-topmode a.rp-tm-gbtn { display: inline-flex; align-items: center; text-decoration: none; }
      .tn-scope.rp-topmode .rp-tm-grp.on a.rp-tm-gbtn { color: #fff; }
    </style>

    <!-- Bibliotecas locais (sem dependencia de CDN) -->
    <script src="assets/vendor/lucide.min.js?v=20260922"></script>
    <script src="assets/vendor/chart.umd.min.js"></script>
    <script src="assets/ds/js/charts.js?v=20260922"></script>
</head>
<body>
    <!-- Cabecalho do MK-AUTH (PROVEDOR/OPCOES + IPSEC + RAM/CPU) -->
    <?php include('../../topo.php'); ?>

    <!-- ===== App Shell (menu no topo, estilo painel RxMax) ===== -->
    <div class="tn-scope ds-app rp-topmode">

        <!-- ============ MENU NO TOPO ============ -->
        <header class="rp-tm" id="rp-tm">
            <div class="rp-tm-in">
                <?php /* 09/10/2026: logo removido a pedido do Rafael */ ?>

                <button type="button" class="rp-tm-burger" aria-label="Abrir menu"
                        onclick="document.getElementById('rp-tm').classList.toggle('open')">
                    <i data-lucide="menu"></i>
                </button>

                <nav class="rp-tm-nav" aria-label="Navegação do módulo">
                    <?php foreach ($nav_meta as $key => $m): ?>
                        <div class="rp-tm-grp <?php echo $tab === $key ? 'on' : ''; ?>">
                            <a class="rp-tm-gbtn" href="?tab=<?php echo $key; ?>" <?php echo $tab === $key ? 'aria-current="page"' : ''; ?>>
                                <span class="rp-tm-gic" style="background:<?php echo $m['cor']; ?>"><i data-lucide="<?php echo $m['icon']; ?>"></i></span>
                                <?php echo htmlspecialchars($m['label']); ?>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </nav>

                <div class="rp-tm-act">
                    <button type="button" class="ds-icon-btn" onclick="window.print()"
                            data-ds-tip="Imprimir" aria-label="Imprimir">
                        <i data-lucide="printer"></i>
                    </button>
                    <?php /* 09/10/2026: botão de modo escuro removido */ ?>
                    <div class="ds-dropdown">
                        <button type="button" class="ds-avatar-btn" data-ds-dropdown aria-haspopup="true" aria-label="Menu do usuário">
                            <span class="ds-avatar"><?php echo $ui_iniciais; ?></span>
                            <i data-lucide="chevron-down"></i>
                        </button>
                        <div class="ds-dropdown-menu" role="menu">
                            <div class="ds-dd-head"><?php echo $ui_user; ?> <span class="ds-badge ds-badge-success ds-badge-dot">Online</span></div>
                            <a href="?tab=credenciais"><i data-lucide="key-round"></i> Credenciais</a>
                            <?php if (is_dir(__DIR__ . '/../Resumo_Pagamentos')): ?><a href="../Resumo_Pagamentos/"><i data-lucide="receipt"></i> Resumo de Pagamentos</a><?php endif; ?>
                            <div class="ds-dd-sep"></div>
                            <a href="<?php echo ADMIN2URL; ?>"><i data-lucide="arrow-left"></i> Voltar ao MK-AUTH</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- barra de contexto da aba ativa -->
            <div class="rp-tm-sub">
                <span class="rp-tm-cur"><i data-lucide="<?php echo $tab_atual['icon']; ?>"></i> <b><?php echo htmlspecialchars($tab_atual['label']); ?></b></span>
                <span class="rp-tm-desc"><?php echo htmlspecialchars($tab_atual['desc'] ?? ''); ?></span>
                <span class="rp-tm-ver"><i data-lucide="package"></i> v<?php echo $addon_ver ?: '—'; ?><?php /* 11/10/2026: nome do provedor do MK-Auth (o addon roda em qualquer provedor) */ $rp_prov = ''; try { $c9 = rp_db_connect(); if ($c9 && !$c9->connect_errno && ($r9 = $c9->query("SELECT nome FROM sis_provedor ORDER BY id LIMIT 1"))) $rp_prov = trim((string)($r9->fetch_assoc()['nome'] ?? '')); } catch (\Throwable $e) {} echo ' · ' . htmlspecialchars($rp_prov ?: $addon_autor); ?></span>
            </div>
        </header>

        <!-- ============ CONTEUDO DA ABA ATIVA ============ -->
        <div class="ds-main rp-main">
            <main class="ds-content" id="ds-content">
                <?php
                $arquivo = $paginas[$tab];
                $arquivoCompleto = __DIR__ . '/' . $arquivo;
                if (is_file($arquivoCompleto)) {
                    $GLOBALS['rp_in_index'] = true;
                    if ($tab === 'autopay') { $conn = rp_db_connect(); if ($conn) $conn->set_charset('utf8mb4'); }
                    include $arquivoCompleto;
                } else {
                    echo '<div class="ds-empty"><div class="ds-empty-ico"><i data-lucide="file-x"></i></div>'
                       . '<b>Página não encontrada</b><p>O arquivo ' . htmlspecialchars($arquivo) . ' não existe neste módulo.</p></div>';
                }
                ?>
            </main>
        </div>
    </div>
    <!-- ===== /App Shell ===== -->

    <!-- Rodape do MK-AUTH -->
    <?php include('../../baixo.php'); ?>

    <!-- RP Enterprise DS: nucleo, toasts e modais -->
    <script src="assets/ds/js/ds.js?v=20260922"></script>
    <script src="assets/ds/js/toast.js?v=20260922"></script>
    <script src="assets/ds/js/modal.js?v=20260922"></script>
    <script>
    // Indexa as paginas do modulo na busca global
    dsRegisterSearch([
        <?php foreach ($nav_meta as $key => $m): ?>
        { label: <?php echo json_encode($m['label']); ?>, href: '?tab=<?php echo $key; ?>', icon: <?php echo json_encode($m['icon']); ?>, keywords: <?php echo json_encode($m['desc'] ?? ''); ?> },
        <?php endforeach; ?>
    ]);
    </script>

    <!-- Inclui scripts relacionados ao menu (IDENTICO ao addon original) -->
    <script src="../../menu.js.hhvm"></script>

    <!-- COR AUTOMÁTICA: o menu do MK-AUTH dentro do addon e a sidebar herdam
         a cor configurada em Opções > Cor do menu/barras. A cor real é lida
         da página principal do painel (mesma origem) e fica em cache no
         navegador; ao trocar a cor no MK-AUTH, o addon acompanha sozinho. -->
    <script>
    (function () {
        var nav = document.querySelector('nav.navbar.is-fixed-top');
        var app = document.querySelector('.tn-scope.ds-app');
        if (!nav) return;

        function tintSidebar() {
            if (!app) return;
            var c = getComputedStyle(nav).backgroundColor;
            if (!c || c === 'transparent' || c === 'rgba(0, 0, 0, 0)') return;
            var m = c.match(/\d+(\.\d+)?/g);
            if (!m || m.length < 3) return;
            var r = +m[0], g = +m[1], b = +m[2];
            var dark = function (f) { return 'rgb(' + Math.round(r * f) + ',' + Math.round(g * f) + ',' + Math.round(b * f) + ')'; };
            var lum = (0.299 * r + 0.587 * g + 0.114 * b) / 255; // fundo claro -> texto escuro
            var claro = lum > 0.62;
            var s = app.style;
            s.setProperty('--ds-sidebar-bg', dark(1));
            s.setProperty('--ds-sidebar-hover', claro ? dark(0.9) : dark(0.8));
            s.setProperty('--ds-sidebar-active', claro ? 'rgba(0,0,0,.14)' : 'rgba(255,255,255,.20)');
            s.setProperty('--ds-sidebar-text', claro ? 'rgba(0,0,0,.72)' : 'rgba(255,255,255,.85)');
            s.setProperty('--ds-sidebar-text-hover', claro ? '#111827' : '#ffffff');
            s.setProperty('--ds-sidebar-border', claro ? 'rgba(0,0,0,.12)' : 'rgba(255,255,255,.16)');
            s.setProperty('--ds-sidebar-muted', claro ? 'rgba(0,0,0,.55)' : 'rgba(255,255,255,.65)');
            s.setProperty('--ds-sidebar-chip', claro ? 'rgba(0,0,0,.10)' : 'rgba(255,255,255,.18)');
            app.classList.add('ds-sidebar-auto');
        }

        function aplicar(cls) {
            nav.className = nav.className.replace(/\bis-(dark|black|light|white|primary|link|info|success|warning|danger)\b/g, '').replace(/\s+/g, ' ').trim();
            nav.classList.add(cls);
            tintSidebar();
        }

        var salva = null;
        try { salva = localStorage.getItem('rp-mk-navcolor'); } catch (e) {}
        aplicar(salva || 'is-danger'); // aplica imediato (cache ou padrão)

        // Busca a cor real na página principal do painel e atualiza o cache
        fetch('../../index.hhvm', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.text() : ''; })
            .then(function (html) {
                var m = html.match(/navbar\s+is-fixed-top\s+(is-[a-z]+)/);
                if (!m || !m[1]) return;
                try { localStorage.setItem('rp-mk-navcolor', m[1]); } catch (e) {}
                if (m[1] !== (salva || 'is-danger')) aplicar(m[1]);
            })
            .catch(function () {});
    })();
    </script>
</body>
</html>
