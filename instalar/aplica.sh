set -e
R=/opt/mk-auth/admin/addons/Resumo_Pagamentos; D=/opt/mk-auth/admin/addons/cartao_credito; B=/root/bk_separa_cartao_20261009
PHPB=${PHPB:-php}
mkdir -p $B/movidos $B/www_cartao
cd $R
cp -p index.php configuracoes.php api_enviar_whatsapp.php pagamento.php webhook_mp.php autopay_publico.php $B/
cp -p cartao_publico/index.php $B/cartao_publico_index.php
cp -p /var/www/cartao/* $B/www_cartao/ 2>/dev/null || true
cp -p ../addon.js $B/addon.js
crontab -l > $B/crontab.txt 2>/dev/null || true
for f in auditoria_mp.php recuperar_pagamentos.php cfg_autopay.php autopay_helper.php cron_autopay.php; do mv $f $B/movidos/; done
cp /tmp/cc_deploy/stub_pg.php pagamento.php; cp /tmp/cc_deploy/stub_pg.php autopay_publico.php; cp /tmp/cc_deploy/stub_wh.php webhook_mp.php
chown www-data:www-data pagamento.php autopay_publico.php webhook_mp.php
python3 /tmp/cc_deploy/edita_rp.py $R
if [ -f /var/www/cartao/index.php ]; then sed -i 's#/admin/addons/Resumo_Pagamentos/pagamento.php#/admin/addons/cartao_credito/pagamento.php#' /var/www/cartao/index.php; fi
if [ -f /var/www/cartao/webhook_mp.php ]; then sed -i 's#addons/Resumo_Pagamentos/webhook_mp.php#addons/cartao_credito/webhook_mp.php#' /var/www/cartao/webhook_mp.php; fi
if crontab -l 2>/dev/null | grep -q Resumo_Pagamentos/cron_autopay.php; then crontab -l | sed 's#Resumo_Pagamentos/cron_autopay.php#cartao_credito/cron_autopay.php#' | crontab -; fi
grep -q "addons/cartao_credito/" ../addon.js || cat >> ../addon.js <<'JS'

//CARTAO DE CREDITO - INICIO (addon cartao_credito, 09/10/2026)
try { add_menu.financeiro('{"plink": "' + window.location.protocol + "//" + window.location.host + '/admin/addons/cartao_credito/", "ptext": "💳 Cartão de Crédito"}'); } catch (e) {}
//CARTAO DE CREDITO - FIM
JS
for f in $R/*.php $D/*.php /var/www/cartao/*.php; do [ -f $f ] && $PHPB -l $f | grep -v "^No syntax" || true; done
echo FEITO
