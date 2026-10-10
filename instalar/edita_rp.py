import sys
R=sys.argv[1]
def ed(fn, pares):
    p=R+'/'+fn; s=open(p,encoding='utf-8').read()
    for a,b,n in pares:
        assert s.count(a)==n, (fn,a[:60],s.count(a)); s=s.replace(a,b)
    open(p,'w',encoding='utf-8').write(s); print('ok',fn)
# index.php
ed('index.php',[
 ("$tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';\n",
  "$tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';\n"
  "// 09/10/2026: cartão (Mercado Pago) virou o addon cartao_credito; endereços antigos levam pra lá\n"
  "$cc_ir = ['cartao' => 'visao', 'recuperar' => 'recuperar', 'auditoria' => 'auditoria'];\n"
  "if ($tab === 'config' && ($_GET['cfg_tab'] ?? '') === 'cartao') { $cc_s = $_GET['mp_sub'] ?? 'credenciais'; $cc_ir['config'] = in_array($cc_s, ['auditoria','recuperar','autopay']) ? $cc_s : 'credenciais'; }\n"
  "if (isset($cc_ir[$tab])) { header('Location: ../cartao_credito/?tab=' . $cc_ir[$tab]); exit; }\n",1),
 ("    'recuperar'      => ['label' => 'Recuperar Pagamentos', 'icon' => 'rotate-ccw',       'group' => 'Financeiro',   'desc' => 'Reconciliação e recuperação de pagamentos'],\n    'auditoria'      => ['label' => 'Auditoria MP',         'icon' => 'shield-check',     'group' => 'Financeiro',   'desc' => 'Auditoria de transações Mercado Pago'],\n",
  "    'cartao'         => ['label' => 'Cartão de Crédito',    'icon' => 'credit-card',      'group' => 'Financeiro',   'desc' => 'Abre o addon do cartão (Mercado Pago)'],\n",1),
 ("'recuperar'=>'#eb6834',","'recuperar'=>'#eb6834','cartao'=>'#2a78d6',",1),
])
# configuracoes.php
p=R+'/configuracoes.php'; s=open(p,encoding='utf-8').read()
lk='''    <a href="?tab=config&cfg_tab=cartao" class="cfg-subtab <?php echo $cfg_tab=='cartao'?'active':''; ?>">
        <i data-lucide="credit-card"></i>
        <span class="lbl">Cartão de Crédito</span>
    </a>
'''
assert s.count(lk)==1; s=s.replace(lk,'')
a=s.index("<?php elseif ($cfg_tab === 'cartao'): ?>"); b=s.index("<?php elseif ($cfg_tab === 'logs'): ?>")
s=s[:a]+"<?php /* 09/10/2026: Cartão de Crédito saiu daqui, virou o addon cartao_credito */ ?>\n"+s[b:]
for x in ["'path'=>$base.'/logs/webhook_mp.log'","'path'=>$base.'/logs/erro_pagamento.log'"]:
    assert s.count(x)==1; s=s.replace(x, x.replace("$base.'/logs/","dirname($base).'/cartao_credito/logs/"))
open(p,'w',encoding='utf-8').write(s); print('ok configuracoes')
for fn in ['api_enviar_whatsapp.php','cartao_publico/index.php']:
    p=R+'/'+fn; s=open(p,encoding='utf-8').read(); n=s.count('Resumo_Pagamentos/pagamento.php')
    s=s.replace('Resumo_Pagamentos/pagamento.php','cartao_credito/pagamento.php'); open(p,'w',encoding='utf-8').write(s); print('ok',fn,n)
