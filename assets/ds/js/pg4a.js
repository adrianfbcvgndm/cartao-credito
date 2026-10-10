/* pg4a.js — modelo 4A (02/10/2026): marca os campos válidos com ✓ verde e atualiza a taxa do cartão no resumo
   quando muda a parcela. Não mexe no fluxo do Mercado Pago (pagamento.php continua dono do submit). */
(function(){
  function $(id){return document.getElementById(id);}
  function dig(v){return (v||'').replace(/\D/g,'');}
  function luhn(n){var s=0,d=false;for(var i=n.length-1;i>=0;i--){var x=+n[i];if(d){x*=2;if(x>9)x-=9;}s+=x;d=!d;}return s%10===0;}
  function cpf(v){v=dig(v);if(v.length!==11||/^(\d)\1+$/.test(v))return false;for(var t=9;t<11;t++){var s=0;for(var i=0;i<t;i++)s+=v[i]*(t+1-i);if(((10*s)%11)%10!=v[t])return false;}return true;}
  var regras={
    pgInputNum:function(v){v=dig(v);return v.length>=13&&v.length<=19&&luhn(v);},
    pgInputName:function(v){return /\S+\s+\S+/.test((v||'').trim());},
    pgInputExp:function(v){var m=(v||'').match(/^(\d{2})\/(\d{2})$/);if(!m)return false;var mm=+m[1],aa=2000+(+m[2]),h=new Date();return mm>=1&&mm<=12&&(aa>h.getFullYear()||(aa===h.getFullYear()&&mm>=h.getMonth()+1));},
    pgInputCvv:function(v){return /^\d{3,4}$/.test(v||'');},
    pgDocNum:function(v){var d=dig(v);return d.length===14||cpf(d);},
    pgEmail:function(v){return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test((v||'').trim());}
  };
  Object.keys(regras).forEach(function(id){var el=$(id);if(!el)return;
    var f=function(){el.classList.toggle('pg-ok',!!regras[id](el.value));};
    ['input','change','blur'].forEach(function(e){el.addEventListener(e,f);});f();});
  // taxa do cartão no resumo acompanha a parcela escolhida
  var lin=document.querySelector('[data-base-mj]'),tx=$('pgResumoTaxa'),lbl=$('pgResumoTaxaLbl');
  function br(v){return 'R$ '+v.toFixed(2).replace('.',',').replace(/\B(?=(\d{3})+(?!\d))/g,'.');}
  document.querySelectorAll('.pg-parcela-pill').forEach(function(p){p.addEventListener('click',function(){
    if(!lin||!tx)return;var t=parseFloat(p.getAttribute('data-total'))||0,b=parseFloat(lin.getAttribute('data-base-mj'))||0,n=p.getAttribute('data-parcela');
    tx.textContent=br(Math.max(0,t-b));if(lbl)lbl.textContent=n==='1'?'Taxa do cartão':'Taxa do cartão + juros ('+n+'x)';});});
})();
/* 03/10: valor do topo acompanha a aba — no cartão inclui a taxa; no Pix/Boleto, não */
(function(){var v=document.getElementById('pgTopoValor');if(!v)return;
  document.querySelectorAll('.pg-tab').forEach(function(t){t.addEventListener('click',function(){
    v.textContent=(t.getAttribute('data-tab')==='cartao')?v.getAttribute('data-cartao'):v.getAttribute('data-sem-taxa');});});})();
