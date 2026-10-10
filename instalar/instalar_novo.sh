#!/bin/bash
# Instalação NOVA do addon Cartão de Crédito (servidor que ainda não tem o cartão).
# Uso (como root, dentro da pasta do repositório):  bash instalar/instalar_novo.sh
# Variáveis opcionais: PHPB=/opt/php8/bin/php  DBPASS=senha_do_banco
set -e
ORIG="$(cd "$(dirname "$0")/.." && pwd)"
D=/opt/mk-auth/admin/addons/cartao_credito
R=/opt/mk-auth/admin/addons/Resumo_Pagamentos
PHPB=${PHPB:-php}
[ -d /opt/mk-auth/admin/addons ] || { echo "Não achei o MK-Auth em /opt/mk-auth"; exit 1; }
[ -e "$D" ] && { echo "Já existe $D. Para atualizar, use só o rsync do passo 'Atualizar' do README."; exit 1; }

echo "1) copiando o addon para $D"
mkdir -p "$D"
rsync -a --exclude .git --exclude instalar --exclude publico --exclude README.md "$ORIG"/ "$D"/
mkdir -p "$D/logs" && chmod 777 "$D/logs"

echo "2) configuração (rp_config_local.php e .rp_autokey)"
if [ -f "$R/rp_config_local.php" ]; then
  cp -p "$R/rp_config_local.php" "$D/"; [ -f "$R/.rp_autokey" ] && cp -p "$R/.rp_autokey" "$D/"
  echo "   copiei do Resumo de Pagamentos deste servidor"
else
  cp "$D/rp_config_local.exemplo.php" "$D/rp_config_local.php"
  [ -n "$DBPASS" ] && sed -i "s#SENHA_DO_BANCO#$DBPASS#" "$D/rp_config_local.php"
  echo "   ATENÇÃO: edite $D/rp_config_local.php (senha do banco e mp_webhook_secret)"
fi
chown -R www-data:www-data "$D"

echo "3) tabelas no banco"
DBP=$(sed -n "s/.*'db_pass' *=> *'\([^']*\)'.*/\1/p" "$D/rp_config_local.php")
mysql -uroot -p"$DBP" mkradius < "$ORIG/instalar/tabelas.sql"

echo "4) link curto /cartao e webhook público em /var/www/cartao"
if [ ! -f /var/www/cartao/index.php ]; then
  mkdir -p /var/www/cartao && cp -a "$ORIG/publico/cartao/." /var/www/cartao/ && chown www-data:www-data /var/www/cartao/webhook_mp.php
else
  echo "   /var/www/cartao já existe; confira se aponta para addons/cartao_credito"
fi

echo "5) menu do MK-Auth (Financeiro > Cartão de Crédito)"
grep -q "addons/cartao_credito/" /opt/mk-auth/admin/addons/addon.js 2>/dev/null || cat >> /opt/mk-auth/admin/addons/addon.js <<'JS'

//CARTAO DE CREDITO - INICIO
try { add_menu.financeiro('{"plink": "' + window.location.protocol + "//" + window.location.host + '/admin/addons/cartao_credito/", "ptext": "💳 Cartão de Crédito"}'); } catch (e) {}
//CARTAO DE CREDITO - FIM
JS

echo "6) cron do débito automático (todo dia 9h)"
crontab -l 2>/dev/null | grep -q cartao_credito/cron_autopay.php || (crontab -l 2>/dev/null; echo "0 9 * * * /usr/bin/php $D/cron_autopay.php >/dev/null 2>&1") | crontab -

echo "7) conferindo sintaxe"
for f in "$D"/*.php /var/www/cartao/*.php; do $PHPB -l "$f" | grep -v "^No syntax" || true; done
echo "PRONTO. Siga o passo 'Depois de instalar' do README."
