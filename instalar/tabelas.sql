-- Tabelas que o addon usa e que podem não existir num MK-Auth novo.
-- Pode rodar mais de uma vez (só cria o que falta).
CREATE TABLE IF NOT EXISTS integrar_cartao_mp (
  script text, acrescimo varchar(255), public_key varchar(255), access_token varchar(255),
  confirmacao_pagamento text, solicitacao_pagamento text, pagamento_recusado text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS personaliza_pg_pag (
  layout text, cor_botao_pg text, navbar text, caminho_logo text, imagem_cartao text,
  imagem_boleto text, imagem_pix text, exibir_pix text, exibir_cartao text, exibir_boleto text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

INSERT INTO personaliza_pg_pag (layout,cor_botao_pg,navbar,caminho_logo,imagem_cartao,imagem_boleto,imagem_pix,exibir_pix,exibir_cartao,exibir_boleto)
SELECT 'padrao','#dc3545','#3373ad','/mkfiles/logo.jpg','','','','sim','sim','sim' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM personaliza_pg_pag);

CREATE TABLE IF NOT EXISTS addon_msg_config (
  id int(11) NOT NULL AUTO_INCREMENT, chave varchar(100) NOT NULL, valor text DEFAULT NULL,
  atualizado_em timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id), UNIQUE KEY chave (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- addon_mp_webhook_audit, addon_autopay_clientes e addon_autopay_log o próprio addon cria.
