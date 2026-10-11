<?php
/* Instalador do addon Cartão de Crédito (Mercado Pago) para MK-Auth — 11/10/2026.
   Como usar (como root, no servidor do MK-Auth, dentro da pasta descompactada):
       php instalar/instalar.php
   Atualizar: o mesmo comando (guarda backup e mantém a configuração do servidor).
   Senha do MySQL diferente da padrão: php instalar/instalar.php --dbpass=SENHA (ou ele pergunta).
   Remover:   php instalar/instalar.php --remover   (os dados ficam no banco). */
if (PHP_SAPI !== 'cli') exit("Rode pelo terminal do servidor.\n");
$args = array_slice($argv, 1);
if (PHP_VERSION_ID < 80000) {   // MK-Auth novo tem o PHP 8 em /opt/php8
    if (is_file('/opt/php8/bin/php')) { passthru('/opt/php8/bin/php ' . escapeshellarg(__FILE__) . ' ' . implode(' ', array_map('escapeshellarg', $args)), $rc); exit($rc); }
    exit("❌ Precisa de PHP 8 (este é " . PHP_VERSION . ").\n");
}
$ok = fn($t) => print("✅ $t\n"); $av = fn($t) => print("⚠️  $t\n"); $erro = function ($t) { print("❌ $t\n"); exit(1); };
if (function_exists('posix_getuid') && posix_getuid() !== 0) $erro('Rode como root.');
$MK = '/opt/mk-auth'; $ADD = "$MK/admin/addons"; $D = "$ADD/cartao_credito"; $R = "$ADD/Resumo_Pagamentos";
$SRC = dirname(__DIR__); $JS = "$ADD/addon.js"; $PUB = '/var/www/cartao'; $CRON = '/etc/cron.d/cartao_credito';
$BK = '/root/bk_cartao_credito'; $ts = date('Ymd_His');
if (!is_dir($ADD)) $erro("MK-Auth não encontrado ($ADD).");
@mkdir($BK, 0700, true);
$arg = function ($nome) use ($args) { foreach ($args as $a) if (strpos($a, "--$nome=") === 0) return substr($a, strlen($nome) + 3); return null; };

/* ---------- remover ---------- */
if (in_array('--remover', $args, true)) {
    if (is_file($JS)) {
        $s = file_get_contents($JS);
        $n = preg_replace('/\n?\/\/CARTAO DE CREDITO - INICIO.*?\/\/CARTAO DE CREDITO - FIM\n?/s', "\n", $s);
        $n = preg_replace('/\n?[^\n]*addons\/cartao_credito\/[^\n]*/', '', $n);
        if ($n !== $s) { copy($JS, "$BK/addon.js.antes_remover_$ts"); file_put_contents($JS, $n); $ok('Menu tirado do MK-Auth.'); }
    }
    if (is_file($CRON)) { unlink($CRON); $ok('Agendamento removido.'); }
    $cr = (string)shell_exec('crontab -l 2>/dev/null');
    if (strpos($cr, 'cartao_credito/cron_autopay.php') !== false) {
        $tmp = tempnam('/tmp', 'cr'); file_put_contents($tmp, preg_replace('/^.*cartao_credito\/cron_autopay\.php.*\n?/m', '', $cr)); exec('crontab ' . escapeshellarg($tmp)); unlink($tmp); $ok('Linha do débito automático tirada do crontab.');
    }
    if (is_dir($PUB) && strpos((string)@file_get_contents("$PUB/webhook_mp.php"), 'cartao_credito') !== false) { exec('mv ' . escapeshellarg($PUB) . ' ' . escapeshellarg("$BK/www_cartao_removido_$ts")); $ok("Link curto /cartao guardado em $BK e removido."); }
    if (is_dir($D)) { exec('tar czf ' . escapeshellarg("$BK/cartao_credito_removido_$ts.tgz") . ' -C ' . escapeshellarg($ADD) . ' cartao_credito && rm -rf ' . escapeshellarg($D)); $ok("Pasta do addon guardada em $BK/cartao_credito_removido_$ts.tgz e removida."); }
    print("Os dados (credenciais, auditoria, débito automático) continuam no banco.\n"); exit;
}

print("\n💳 Cartão de Crédito (Mercado Pago) — instalação\n\n");
if (!is_file("$SRC/pagamento.php")) $erro("Rode de dentro da pasta descompactada: php instalar/instalar.php");
$mods = array_map('basename', glob('/etc/apache2/mods-enabled/php*.load') ?: []);
if ($mods && !preg_grep('/php8/', $mods)) $erro('O MK-Auth deste servidor roda PHP 7 na web (' . implode(', ', $mods) . '). Atualize o MK-Auth (2601 ou mais novo) e rode de novo.');
foreach (['curl', 'openssl', 'mysqli', 'json', 'mbstring'] as $e) if (!extension_loaded($e)) $erro("Falta a extensão PHP '$e' neste PHP (" . PHP_BINARY . ').');
$ok('PHP ' . PHP_VERSION . ' com curl, openssl e mysqli.');
$atualizar = is_dir($D);

/* ---------- banco: senha padrão do MK-Auth, a do rp_config_local.php existente, a do Resumo, --dbpass ou pergunta ---------- */
$cfgDe = function ($f) { if (!is_file($f)) return null; $c = @include $f; return is_array($c) ? $c : null; };
$tenta = function ($p) { mysqli_report(MYSQLI_REPORT_OFF); $c = @new mysqli('127.0.0.1', 'root', $p, 'mkradius'); return $c->connect_errno ? null : $c; };
$local = $cfgDe("$D/rp_config_local.php") ?? $cfgDe("$R/rp_config_local.php");
$senhas = array_values(array_unique(array_filter([$arg('dbpass'), $local['db_pass'] ?? null, 'vertrigo'], fn($x) => $x !== null && $x !== '' && $x !== 'SENHA_DO_BANCO')));
$db = null; $dbpass = null;
foreach ($senhas as $p) if ($db = $tenta($p)) { $dbpass = $p; break; }
if (!$db) {
    print("Senha do MySQL (usuário root) do MK-Auth: ");
    $p = trim((string)fgets(STDIN));
    if (!($db = $tenta($p))) $erro('Não consegui entrar no banco com essa senha. Rode de novo com --dbpass=SENHA.');
    $dbpass = $p;
}
$db->set_charset('utf8mb4');
$ok('Banco do MK-Auth acessado.');

/* ---------- backup + cópia dos arquivos (configuração do servidor nunca é trocada) ---------- */
if ($atualizar) { exec('tar czf ' . escapeshellarg("$BK/cartao_credito_antes_$ts.tgz") . ' -C ' . escapeshellarg($ADD) . ' cartao_credito'); $ok("Versão anterior guardada em $BK/cartao_credito_antes_$ts.tgz"); }
$fora = ['.git', 'instalar', 'publico', 'docs', 'README.md', 'rp_config_local.php', '.rp_autokey', 'logs'];
$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($SRC, FilesystemIterator::SKIP_DOTS),
    fn($f, $k, $i) => !($i->getSubPath() === '' && (in_array($f->getFilename(), $fora, true) || preg_match('/\.pdf$/i', $f->getFilename()))) && !preg_match('/\.bak/', $f->getFilename())), RecursiveIteratorIterator::SELF_FIRST);
$n = 0;
foreach ($it as $f) {
    $alvo = $D . substr($f->getPathname(), strlen($SRC));
    if ($f->isDir()) { @mkdir($alvo, 0755, true); continue; }
    @mkdir(dirname($alvo), 0755, true); copy($f->getPathname(), $alvo); $n++;
}
@mkdir("$D/logs", 0777, true); @chmod("$D/logs", 0777);
$ok("$n arquivos copiados para $D");

/* configuração: senha do banco só vai para arquivo se não for a padrão; num servidor com Resumo, reaproveita a dele */
if (!is_file("$D/rp_config_local.php")) {
    if (is_file("$R/rp_config_local.php")) {
        copy("$R/rp_config_local.php", "$D/rp_config_local.php"); if (is_file("$R/.rp_autokey")) copy("$R/.rp_autokey", "$D/.rp_autokey");
        $ok('Configuração copiada do Resumo de Pagamentos deste servidor.');
    } elseif ($dbpass !== 'vertrigo') {
        file_put_contents("$D/rp_config_local.php", "<?php\n/* criado pelo instalador (" . date('d/m/Y H:i') . "). Não vai para o GitHub. */\nreturn ['db_host' => '127.0.0.1', 'db_user' => 'root', 'db_pass' => " . var_export($dbpass, true) . ", 'db_name' => 'mkradius'];\n");
        $ok('rp_config_local.php criado com a senha do banco.');
    }
}
exec('chown -R www-data:www-data ' . escapeshellarg($D));

/* ---------- tabelas ---------- */
$sql = file_get_contents(__DIR__ . '/tabelas.sql');
if ($db->multi_query($sql)) { do { if ($r = $db->store_result()) $r->free(); } while ($db->more_results() && $db->next_result()); }
if ($db->errno) $erro('Erro criando tabelas: ' . $db->error);
$ok('Tabelas prontas (só cria o que falta).');

/* ---------- link curto /cartao e endereço do webhook ---------- */
if (!is_dir($PUB)) {
    mkdir($PUB, 0755, true);
    foreach (scandir("$SRC/publico/cartao") as $f) if ($f !== '.' && $f !== '..') copy("$SRC/publico/cartao/$f", "$PUB/$f");
    exec('chown -R www-data:www-data ' . escapeshellarg($PUB));
    $ok("Link curto criado em $PUB (https://SEU-DOMINIO/cartao?id=FATURA).");
} elseif (strpos((string)@file_get_contents("$PUB/webhook_mp.php"), 'cartao_credito') !== false) {
    foreach (scandir("$SRC/publico/cartao") as $f) if ($f !== '.' && $f !== '..') copy("$SRC/publico/cartao/$f", "$PUB/$f");   // atualiza os atalhos
    exec('chown -R www-data:www-data ' . escapeshellarg($PUB));
    $ok("Link curto $PUB já aponta para o addon (atualizado).");
}
else $av("$PUB já existe e aponta para outro lugar (Resumo de Pagamentos?). Para migrar um cartão que estava no Resumo, veja o README (\"Migração\").");

/* ---------- menu Financeiro > Cartão de Crédito (em try/catch: nunca quebra os atalhos dos outros addons) ---------- */
if (is_file($JS)) {
    $s = file_get_contents($JS);
    if (strpos($s, 'addons/cartao_credito/') === false) {
        copy($JS, "$BK/addon.js.antes_cartao_$ts");
        file_put_contents($JS, rtrim($s) . "\n\n//CARTAO DE CREDITO - INICIO\ntry { add_menu.financeiro(JSON.stringify({ plink: window.location.protocol + \"//\" + window.location.host + \"/admin/addons/cartao_credito/\", ptext: \"💳 Cartão de Crédito\" })); } catch (e) {}\n//CARTAO DE CREDITO - FIM\n");
        $ok('Menu "💳 Cartão de Crédito" adicionado em Financeiro (backup do addon.js em ' . $BK . ').');
    } else $ok('Menu do Cartão de Crédito já estava no MK-Auth.');
} else $av("Não achei $JS: abra o addon direto em /admin/addons/cartao_credito/");

/* ---------- débito automático todo dia às 9h ---------- */
$cr = (string)shell_exec('crontab -l 2>/dev/null');
if (strpos($cr, 'cartao_credito/cron_autopay.php') !== false || is_file($CRON)) $ok('Agendamento do débito automático já existia.');
elseif (strpos($cr, 'Resumo_Pagamentos/cron_autopay.php') !== false) $av('O débito automático ainda roda pelo Resumo de Pagamentos (crontab). Para migrar, veja o README ("Migração").');
else { file_put_contents($CRON, "# addon Cartão de Crédito (MK-Auth) — débito automático\n0 9 * * * root " . PHP_BINARY . " $D/cron_autopay.php >/dev/null 2>&1\n"); chmod($CRON, 0644); $ok("Débito automático agendado ($CRON, todo dia às 9h)."); }

/* ---------- confere a sintaxe de tudo ---------- */
$ruins = [];
foreach (array_merge(glob("$D/*.php"), glob("$PUB/*.php") ?: []) as $f) { exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($f) . ' 2>&1', $o, $rc); if ($rc) $ruins[] = basename($f); }
$ruins ? $av('Arquivos com erro de sintaxe: ' . implode(', ', $ruins)) : $ok('Sintaxe dos arquivos conferida.');

$sec = (string)($db->query("SELECT valor FROM addon_msg_config WHERE chave='mp_webhook_secret'")->fetch_assoc()['valor'] ?? '') ?: (string)($cfgDe("$D/rp_config_local.php")['mp_webhook_secret'] ?? '');
$cred = $db->query("SELECT access_token FROM integrar_cartao_mp LIMIT 1")->fetch_assoc();
print("\n🎉 Pronto! Agora, no navegador:\n");
print("   1. Abra o MK-Auth, aperte Ctrl+F5 e entre em Financeiro > 💳 Cartão de Crédito.\n");
print("   2. Aba Credenciais: Access Token e Public Key do Mercado Pago" . (!empty($cred['access_token']) ? ' (já preenchidos)' : '') . ".\n");
print("   3. No Mercado Pago, cadastre o webhook https://SEU-DOMINIO/cartao/webhook_mp.php (evento Pagamentos)\n");
print("      e cole a assinatura secreta na aba Credenciais" . ($sec !== '' && strpos($sec, 'COLE_AQUI') !== 0 ? ' (já configurada)' : '') . ".\n");
print("   4. Teste: https://SEU-DOMINIO/cartao?id=NUMERO_DE_UMA_FATURA_EM_ABERTO\n\n");
