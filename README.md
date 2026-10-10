# Cartão de Crédito (addon do MK-Auth)

Pagamento de faturas com cartão pelo Mercado Pago. O cliente recebe um link curto, paga na página do provedor e a fatura é baixada sozinha quando o Mercado Pago avisa.

Telas do addon (menu **Financeiro > 💳 Cartão de Crédito**):

| Tela | Para que serve |
|---|---|
| Visão geral | Quanto entrou no cartão no mês, taxas, líquido, últimos 6 meses e quem pagou |
| Recuperar pagamentos | Acha pagamento aprovado no Mercado Pago que não baixou a fatura |
| Auditoria MP | Confere o Mercado Pago contra as faturas do MK-Auth |
| Débito automático | Clientes com cobrança automática no cartão |
| Credenciais | Chaves do Mercado Pago e mensagens enviadas ao cliente |

## O que tem no repositório

| Caminho | Vai para | O que é |
|---|---|---|
| raiz (`*.php`, `assets/`) | `/opt/mk-auth/admin/addons/cartao_credito/` | o addon |
| `publico/cartao/` | `/var/www/cartao/` | link curto `/cartao?id=...` e endereço público do webhook |
| `rp_config_local.exemplo.php` | vira `rp_config_local.php` no servidor | modelo da configuração (senha do banco, segredo do webhook) |
| `instalar/instalar_novo.sh` | roda no servidor | instalação nova |
| `instalar/aplica.sh` | roda no servidor | migração de quem tinha o cartão dentro do Resumo de Pagamentos |
| `instalar/tabelas.sql` | banco `mkradius` | cria as tabelas que faltarem |

**Não vão para o GitHub:** `rp_config_local.php` e `.rp_autokey`. Cada servidor tem os seus.

## Antes de começar

- MK-Auth com PHP 8 (testado no 26.01 e no 26.02).
- Acesso root no servidor do MK-Auth.
- Uma conta no Mercado Pago com uma aplicação criada em **Suas integrações** (é de lá que saem o Access Token, a Public Key e a assinatura secreta do webhook).
- O domínio do provedor abrindo o MK-Auth em **https** (o Mercado Pago só manda aviso para https).

## Instalação nova

Use quando o servidor **ainda não tem** pagamento com cartão.

1. Baixe o repositório no servidor do MK-Auth:
   ```
   cd /root
   git clone git@github.com:adrianfbcvgndm/cartao-credito.git
   cd cartao-credito
   ```
   (Sem chave do GitHub no servidor: baixe o ZIP pelo site e descompacte em `/root/cartao-credito`.)

2. Rode o instalador:
   ```
   bash instalar/instalar_novo.sh
   ```
   Se o PHP 8 do servidor não for o `php` padrão, informe o caminho: `PHPB=/opt/php8/bin/php bash instalar/instalar_novo.sh`.

   Ele faz sozinho:
   - copia o addon para `/opt/mk-auth/admin/addons/cartao_credito/`;
   - cria o `rp_config_local.php` (copia do Resumo de Pagamentos, se o servidor tiver; senão, a partir do modelo);
   - cria as tabelas que faltarem no banco;
   - instala o link curto em `/var/www/cartao/`;
   - põe o item no menu **Financeiro** do MK-Auth;
   - agenda o débito automático (todo dia às 9h).

3. Se o instalador avisou **"edite rp_config_local.php"**, abra o arquivo e preencha:
   - `db_pass`: senha do banco do MK-Auth;
   - `mp_webhook_secret`: a assinatura secreta do webhook (passo 3 de "Depois de instalar").

Siga para **Depois de instalar**.

## Migração (cartão que estava dentro do Resumo de Pagamentos)

Use quando o servidor já cobra cartão pelo **Resumo de Pagamentos** antigo. Foi assim na RxMax em 09/10/2026.

1. Copie o addon e a configuração do próprio Resumo:
   ```
   D=/opt/mk-auth/admin/addons/cartao_credito
   R=/opt/mk-auth/admin/addons/Resumo_Pagamentos
   mkdir -p $D && rsync -a --exclude .git --exclude instalar --exclude publico --exclude README.md /root/cartao-credito/ $D/
   cp -p $R/rp_config_local.php $R/.rp_autokey $D/
   mkdir -p $D/logs && chmod 777 $D/logs && chown -R www-data:www-data $D
   ```
2. Rode a migração:
   ```
   mkdir -p /tmp/cc_deploy && cp -a /root/cartao-credito/instalar/. /root/cartao-credito/publico /tmp/cc_deploy/
   PHPB=/opt/php8/bin/php bash /tmp/cc_deploy/aplica.sh
   ```
   Ela guarda backup em `/root/bk_separa_cartao_<data>` e:
   - troca `pagamento.php`, `webhook_mp.php` e `autopay_publico.php` do Resumo por atalhos para o addon novo (os links que o cliente já recebeu continuam funcionando);
   - tira Recuperar, Auditoria e a aba Cartão do Resumo (os endereços antigos levam para o addon novo);
   - aponta `/var/www/cartao` e o cron do débito automático para o addon novo;
   - põe o item no menu **Financeiro**.

O endereço do webhook no Mercado Pago **não muda**. Siga para **Depois de instalar** (pule o passo 3 se o webhook já estava cadastrado).

## Depois de instalar

1. **Atualize o navegador** (Ctrl+F5) e abra **Financeiro > 💳 Cartão de Crédito**.
2. **Credenciais:** cole o **Access Token** e a **Public Key** de produção do Mercado Pago, as mensagens ao cliente e o acréscimo do parcelamento em 3x. Salve.
3. **Webhook no Mercado Pago** (Suas integrações > sua aplicação > Webhooks):
   - URL de produção: `https://SEU-DOMINIO/cartao/webhook_mp.php`
   - Evento: **Pagamentos**
   - Copie a **assinatura secreta** e cole em `mp_webhook_secret` no `rp_config_local.php`.
4. **Teste o link do cliente:** abra `https://SEU-DOMINIO/cartao?id=NUMERO_DE_UMA_FATURA_EM_ABERTO`. Tem que aparecer a página de pagamento da fatura.
5. **Teste o webhook:**
   - `curl -X POST https://SEU-DOMINIO/cartao/webhook_mp.php` tem que responder **401** (recusa aviso sem assinatura; é o certo).
   - No Mercado Pago, use **Simular notificação**: tem que dar **200**.
6. **Primeiro pagamento real:** pague uma fatura pequena com cartão e confira se ela baixou sozinha e apareceu na **Visão geral**.

## Atualizar

```
cd /root/cartao-credito && git pull
rsync -a --exclude .git --exclude instalar --exclude publico --exclude README.md --exclude rp_config_local.php --exclude .rp_autokey ./ /opt/mk-auth/admin/addons/cartao_credito/
chown -R www-data:www-data /opt/mk-auth/admin/addons/cartao_credito
```
O `rsync` não apaga nem troca a configuração do servidor.

## Problemas comuns

| Sintoma | Causa provável |
|---|---|
| Cliente pagou e a fatura não baixou | `mp_webhook_secret` errado ou vazio, ou webhook não cadastrado. Use **Recuperar pagamentos** para baixar. |
| `/cartao?id=...` dá "Não encontrado" | `/var/www/cartao/` não existe ou o Apache não serve `/var/www` em `/cartao`. |
| Menu não aparece | Ctrl+F5 (o navegador guarda o menu antigo). |
| Botão "receber Pix no WhatsApp" da página de pagamento falha | Ele usa o envio de WhatsApp do **Resumo de Pagamentos**; sem o Resumo instalado esse botão não funciona. O cartão funciona normal. |
| Avisos no Telegram não chegam | Usam o token e o grupo configurados no Resumo de Pagamentos (tabela `addon_msg_config`). |

## Desinstalar

Apague `/opt/mk-auth/admin/addons/cartao_credito/` e `/var/www/cartao/`, tire o bloco `//CARTAO DE CREDITO` do `/opt/mk-auth/admin/addons/addon.js` e a linha `cartao_credito/cron_autopay.php` do `crontab -e`. As tabelas e os pagamentos já baixados continuam no banco.
Se foi uma migração, os arquivos antigos do Resumo estão em `/root/bk_separa_cartao_<data>`.
