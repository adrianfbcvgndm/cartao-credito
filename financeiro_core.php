<?php
require_once __DIR__ . '/rp_config.php';
/**
 * financeiro_core.php
 *
 * Núcleo central de regras financeiras e operacionais do addon MK-AUTH.
 *
 * Objetivo:
 * - Evitar lógica duplicada entre resumo_pagamento.php, planilha_financeira.php e gerenciamento_clientes.php
 * - Padronizar filtros de títulos pagos / em aberto / vencidos / deletados
 * - Centralizar métricas e consultas mais usadas
 *
 * Compatível com:
 * - mysqli
 * - include dentro do addon
 */

if (!function_exists('mkp_get_connection')) {
    function mkp_get_connection()
    {
        $conn = @$GLOBALS['LOADMYSQL'] ?? null;
        if ($conn && (!method_exists($conn, 'ping') || @$conn->ping())) {
            return $conn;
        }

        $conn = rp_db_connect();
        if ($conn && !$conn->connect_errno) {
            $conn->set_charset('utf8mb4');
            return $conn;
        }
        return null;
    }
}

if (!function_exists('mkp_month_range')) {
    function mkp_month_range($mes, $ano)
    {
        $mes = max(1, min(12, (int)$mes));
        $ano = max(2020, min(2040, (int)$ano));
        $inicio = sprintf('%04d-%02d-01', $ano, $mes);
        $fim = date('Y-m-d', strtotime('+1 month', strtotime($inicio)));
        return [$inicio, $fim];
    }
}

if (!function_exists('mkp_base_open_titles_where')) {
    function mkp_base_open_titles_where()
    {
        return "status != 'pago' AND valor > 0 AND (deltitulo = 0 OR deltitulo IS NULL)";
    }
}

if (!function_exists('mkp_normalize_payment_method')) {
    function mkp_normalize_payment_method($formapag)
    {
        $raw = strtolower(trim((string)$formapag));

        if ($raw === 'status.paid') return 'GERENCIANET';
        if (strpos($raw, 'pix') !== false && strpos($raw, 'efi') !== false) return 'PIX-EFIBANK';
        if (strpos($raw, 'pix') !== false && strpos($raw, 'gerencianet') !== false) return 'PIX GERENCIANET';
        if (strpos($raw, 'boleto') !== false) return 'GERENCIANET';
        if (strpos($raw, 'bolemix') !== false) return 'GERENCIANET';
        if (strpos($raw, 'gerencianet') !== false) return 'GERENCIANET';
        if (strpos($raw, 'dinheiro') !== false) return 'DINHEIRO';
        if (strpos($raw, 'cartao') !== false && strpos($raw, 'credito') !== false) return 'CARTAO DE CREDITO';
        if (strpos($raw, 'baixa') !== false && strpos($raw, 'administrador') !== false) return 'BAIXA PELO ADMINISTRADOR';
        return strtoupper($formapag ?: 'OUTROS');
    }
}

if (!function_exists('mkp_apply_gateway_fee')) {
    function mkp_apply_gateway_fee($formapag, $valor)
    {
        $valor = (float)$valor;
        $taxa = 0.0;
        $raw = strtolower((string)$formapag);

        if (
            strpos($raw, 'boleto') !== false ||
            strpos($raw, 'bolemix') !== false ||
            strpos($raw, 'gerencianet') !== false
        ) {
            $taxa = 2.37;
        } elseif (strpos($raw, 'pix') !== false) {
            $taxa = $valor * 0.0198;
        }

        $liquido = $valor - $taxa;
        if ($liquido < 0) $liquido = 0;

        return [
            'bruto' => $valor,
            'taxa' => round($taxa, 2),
            'liquido' => round($liquido, 2),
        ];
    }
}

if (!function_exists('mkp_fetch_payments_by_day')) {
    function mkp_fetch_payments_by_day(mysqli $conn, $dataSQL)
    {
        $dados = [
            'pagamentos' => [],
            'totais_por_forma' => [],
            'total_geral' => 0.0,
            'total_qtd' => 0,
        ];

        $inicio = $dataSQL . ' 00:00:00';
        $fim = date('Y-m-d', strtotime($dataSQL . ' +1 day')) . ' 00:00:00';

        $stmt = $conn->prepare("
            SELECT datavenc, valorpag, coletor, formapag, login, datapag
            FROM sis_lanc
            WHERE datapag >= ?
              AND datapag < ?
              AND status = 'pago'
            ORDER BY formapag, login
        ");
        if (!$stmt) return $dados;

        $stmt->bind_param('ss', $inicio, $fim);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $forma = mkp_normalize_payment_method($row['formapag'] ?? '');
            $row['formapag_normalizado'] = $forma;
            $dados['pagamentos'][$forma][] = $row;
            $dados['totais_por_forma'][$forma] = ($dados['totais_por_forma'][$forma] ?? 0) + (float)$row['valorpag'];
            $dados['total_geral'] += (float)$row['valorpag'];
            $dados['total_qtd']++;
        }

        $stmt->close();
        return $dados;
    }
}

if (!function_exists('mkp_fetch_overdue_clients')) {
    function mkp_fetch_overdue_clients(mysqli $conn, $over45 = false, $limit = 50, $offset = 0)
    {
        $whereDias = $over45
            ? "DATEDIFF(CURDATE(), datavenc) > 45"
            : "DATEDIFF(CURDATE(), datavenc) BETWEEN 1 AND 45";

        $where = "datavenc < CURDATE() AND {$whereDias} AND " . mkp_base_open_titles_where();

        $ret = [
            'items' => [],
            'titulo' => $over45 ? 'Inadimplentes (mais de 45 dias)' : 'Inadimplentes (até 45 dias)',
            'total_clientes' => 0,
            'total_valor' => 0.0,
        ];

        $sqlCount = "SELECT COUNT(DISTINCT login) AS total FROM sis_lanc WHERE {$where}";
        $resCount = $conn->query($sqlCount);
        if ($resCount) {
            $ret['total_clientes'] = (int)($resCount->fetch_assoc()['total'] ?? 0);
        }

        $sql = "
            SELECT
                login,
                COUNT(*) AS qtd,
                MIN(datavenc) AS venc,
                SUM(valor) AS total,
                DATEDIFF(CURDATE(), MIN(datavenc)) AS dias
            FROM sis_lanc
            WHERE {$where}
            GROUP BY login
            ORDER BY dias ASC, total DESC
            LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $ret['items'][] = $row;
                $ret['total_valor'] += (float)$row['total'];
            }
        }

        return $ret;
    }
}

if (!function_exists('mkp_fetch_top_critical_overdue')) {
    function mkp_fetch_top_critical_overdue(array $inadimplentes, $limit = 10)
    {
        $tmp = [];
        foreach ($inadimplentes as $cli) {
            $dias = (int)($cli['dias'] ?? 0);
            $valor = (float)($cli['total'] ?? 0);
            $cli['prioridade'] = $dias * $valor;
            $tmp[] = $cli;
        }

        usort($tmp, function ($a, $b) {
            $pa = (float)($a['prioridade'] ?? 0);
            $pb = (float)($b['prioridade'] ?? 0);
            if ($pa === $pb) {
                return (float)($b['total'] ?? 0) <=> (float)($a['total'] ?? 0);
            }
            return $pb <=> $pa;
        });

        return array_slice($tmp, 0, (int)$limit);
    }
}

if (!function_exists('mkp_fetch_clients_financial_summary')) {
    function mkp_fetch_clients_financial_summary(mysqli $conn)
    {
        $ret = [
            'receita_mensal' => 0.0,
            'clientes_ativos' => 0,
            'faturas_vencidas' => 0,
            'valor_inadimplente' => 0.0,
            'total_faturas_abertas' => 0,
            'ticket_medio' => 0.0,
            'taxa_inadimplencia' => 0.0,
        ];

        $sqlBase = "
            SELECT
                COALESCE(SUM(CASE WHEN c.bloqueado = 'nao' AND c.cli_ativado = 's' THEN
                    (SELECT l.valor
                     FROM sis_lanc l
                     WHERE l.login = c.login
                       AND (l.deltitulo = 0 OR l.deltitulo IS NULL)
                     ORDER BY l.datavenc DESC
                     LIMIT 1)
                ELSE 0 END),0) AS receita_mensal,
                COALESCE(SUM(CASE WHEN c.bloqueado = 'nao' AND c.cli_ativado = 's' THEN 1 ELSE 0 END),0) AS clientes_ativos
            FROM sis_cliente c
        ";
        $r = $conn->query($sqlBase);
        if ($r) {
            $row = $r->fetch_assoc();
            $ret['receita_mensal'] = (float)($row['receita_mensal'] ?? 0);
            $ret['clientes_ativos'] = (int)($row['clientes_ativos'] ?? 0);
        }

        $sqlInad = "
            SELECT
                COALESCE(COUNT(CASE WHEN status != 'pago' AND datavenc < CURDATE() AND (deltitulo = 0 OR deltitulo IS NULL) THEN 1 END),0) AS faturas_vencidas,
                COALESCE(SUM(CASE WHEN status != 'pago' AND datavenc < CURDATE() AND (deltitulo = 0 OR deltitulo IS NULL) THEN valor ELSE 0 END),0) AS valor_inadimplente,
                COALESCE(COUNT(CASE WHEN status != 'pago' AND (deltitulo = 0 OR deltitulo IS NULL) THEN 1 END),0) AS total_faturas_abertas
            FROM sis_lanc
        ";
        $r = $conn->query($sqlInad);
        if ($r) {
            $row = $r->fetch_assoc();
            $ret['faturas_vencidas'] = (int)($row['faturas_vencidas'] ?? 0);
            $ret['valor_inadimplente'] = (float)($row['valor_inadimplente'] ?? 0);
            $ret['total_faturas_abertas'] = (int)($row['total_faturas_abertas'] ?? 0);
        }

        $ret['ticket_medio'] = $ret['clientes_ativos'] > 0
            ? $ret['receita_mensal'] / $ret['clientes_ativos']
            : 0;

        $ret['taxa_inadimplencia'] = $ret['total_faturas_abertas'] > 0
            ? ($ret['faturas_vencidas'] / $ret['total_faturas_abertas']) * 100
            : 0;

        return $ret;
    }
}

if (!function_exists('mkp_fetch_month_financial_summary')) {
    function mkp_fetch_month_financial_summary(mysqli $conn, $mes, $ano)
    {
        list($dtInicio, $dtFim) = mkp_month_range($mes, $ano);

        $ret = [
            'inicio' => $dtInicio,
            'fim' => $dtFim,
            'previsto' => 0.0,
            'recebido_bruto' => 0.0,
            'recebido_liquido' => 0.0,
            'aberto' => 0.0,
            'qtd_aberto' => 0,
            'taxa_inadimplencia_financeira' => 0.0,
            'total_taxas' => 0.0,
            'entradas_detalhe' => [],
            'por_forma' => [],
            'cards_forma_pagamento' => [],
            'boletos_total' => 0,
            'boletos_pagos' => 0,
            'boletos_pendentes' => 0,
        ];

        $sqlPrev = "
            SELECT
                COALESCE(SUM(valor),0) AS previsto,
                COALESCE(SUM(CASE WHEN status = 'pago' THEN valorpag ELSE 0 END),0) AS recebido,
                COALESCE(SUM(CASE WHEN status != 'pago' THEN valor ELSE 0 END),0) AS aberto,
                COALESCE(SUM(CASE WHEN status != 'pago' THEN 1 ELSE 0 END),0) AS qtd_aberto
            FROM sis_lanc
            WHERE datavenc >= '{$dtInicio}'
              AND datavenc < '{$dtFim}'
        ";
        $r = $conn->query($sqlPrev);
        if ($r) {
            $row = $r->fetch_assoc();
            $ret['previsto'] = (float)($row['previsto'] ?? 0);
            $ret['recebido_bruto'] = (float)($row['recebido'] ?? 0);
            $ret['aberto'] = max(0, (float)($row['aberto'] ?? 0));
            $ret['qtd_aberto'] = (int)($row['qtd_aberto'] ?? 0);
            $ret['taxa_inadimplencia_financeira'] = $ret['previsto'] > 0
                ? ($ret['aberto'] / $ret['previsto']) * 100
                : 0;
        }

        $sqlEntradas = "
            SELECT login, formapag, valorpag, datapag, datavenc
            FROM sis_lanc
            WHERE datapag >= '{$dtInicio}'
              AND datapag < '{$dtFim}'
              AND datapag IS NOT NULL
              AND datapag != ''
              AND status = 'pago'
            ORDER BY datapag DESC
        ";
        $resEntradas = $conn->query($sqlEntradas);
        if ($resEntradas) {
            while ($r = $resEntradas->fetch_assoc()) {
                $fee = mkp_apply_gateway_fee($r['formapag'], $r['valorpag']);
                $tipo = mkp_normalize_payment_method($r['formapag']);

                $r['formapag_normalizado'] = $tipo;
                $r['valorpag_bruto'] = $fee['bruto'];
                $r['taxa'] = $fee['taxa'];
                $r['valorpag'] = $fee['liquido'];

                $ret['entradas_detalhe'][] = $r;
                $ret['recebido_liquido'] += $fee['liquido'];
                $ret['total_taxas'] += $fee['taxa'];

                if (!isset($ret['por_forma'][$tipo])) {
                    $ret['por_forma'][$tipo] = ['quantidade' => 0, 'total_bruto' => 0.0];
                }
                $ret['por_forma'][$tipo]['quantidade']++;
                $ret['por_forma'][$tipo]['total_bruto'] += $fee['bruto'];
            }
        }

        foreach ($ret['por_forma'] as $tipo => $info) {
            $ret['cards_forma_pagamento'][] = [
                'tipo_pagamento' => $tipo,
                'quantidade' => $info['quantidade'],
                'total_bruto' => $info['total_bruto'],
            ];
        }

        usort($ret['cards_forma_pagamento'], function ($a, $b) {
            return (float)$b['total_bruto'] <=> (float)$a['total_bruto'];
        });

        $sqlBoletos = "
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'pago' THEN 1 ELSE 0 END) AS pagos
            FROM sis_lanc
            WHERE datavenc >= '{$dtInicio}'
              AND datavenc < '{$dtFim}'
              AND (formapag LIKE '%boleto%' OR formapag LIKE '%bolemix%')
        ";
        $r = $conn->query($sqlBoletos);
        if ($r) {
            $row = $r->fetch_assoc();
            $ret['boletos_total'] = (int)($row['total'] ?? 0);
            $ret['boletos_pagos'] = (int)($row['pagos'] ?? 0);
            $ret['boletos_pendentes'] = max(0, $ret['boletos_total'] - $ret['boletos_pagos']);
        }

        return $ret;
    }
}

if (!function_exists('mkp_fetch_previous_month_liquid_entries')) {
    function mkp_fetch_previous_month_liquid_entries(mysqli $conn, $mes, $ano)
    {
        $mesAnt = (int)$mes - 1;
        $anoAnt = (int)$ano;
        if ($mesAnt < 1) {
            $mesAnt = 12;
            $anoAnt--;
        }

        list($dtInicioAnt, $dtFimAnt) = mkp_month_range($mesAnt, $anoAnt);

        $totalLiquido = 0.0;
        $res = $conn->query("
            SELECT formapag, valorpag
            FROM sis_lanc
            WHERE datapag >= '{$dtInicioAnt}'
              AND datapag < '{$dtFimAnt}'
              AND datapag IS NOT NULL
              AND datapag != ''
              AND status = 'pago'
        ");

        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $fee = mkp_apply_gateway_fee($r['formapag'], $r['valorpag']);
                $totalLiquido += $fee['liquido'];
            }
        }

        return $totalLiquido;
    }
}

/**
 * ============================================================
 * CALCULO DE VALOR DE FATURA (corrige bug #1 da auditoria)
 * ============================================================
 * Funcao compartilhada entre pagamento.php e webhook_mp.php
 * para garantir que ambos calculem o MESMO valor.
 *
 * Considera:
 *  - valor base da fatura
 *  - adicional (sis_mlanc, tipo != 'desconto')
 *  - desconto (sis_mlanc, tipo = 'desconto')
 *  - multa por atraso (% sobre valor_base)
 *  - juros mensais por dia de atraso
 *  - taxa do gateway (1x, 2x, 3x cartao)
 *
 * RETORNO: array com breakdown completo
 * ============================================================
 */
if (!function_exists('rp_arred_baixo')) {
    function rp_arred_baixo($n, $casas = 2) {
        $sign = $n > 0 ? 1 : -1;
        $base = pow(10, $casas);
        return floor(abs($n) * $base) / $base * $sign;
    }
}

if (!function_exists('rp_calcular_valor_fatura')) {
    /**
     * Calcula o valor que deve ser cobrado/recebido por uma fatura.
     *
     * @param array $params {
     *   valor_base:           float (valor + adicionais - descontos)
     *   multa_percent:        float (multa% por atraso, ex: 2.0)
     *   juros_percent_mensal: float (juros mensal, ex: 1.0)
     *   dias_atraso:          int   (>= 0)
     *   parcelas:             int   (1, 2 ou 3)
     *   taxa_1x_percent:      float (taxa do gateway no 1x, ex: 5.0)
     *   acrescimo_percent:    float (taxa percentual em 2x e 3x, ex: 5.0)
     * }
     * @return array {
     *   valor_base, multa_juros, valor_sem_taxa, taxa_gateway,
     *   valor_final, parcela_unitaria
     * }
     */
    function rp_calcular_valor_fatura($params) {
        $valor_base    = max(0.0, (float)($params['valor_base'] ?? 0));
        $multa_pct     = max(0.0, (float)($params['multa_percent'] ?? 0));
        $juros_pct_mes = max(0.0, (float)($params['juros_percent_mensal'] ?? 0));
        $dias_atraso   = max(0, (int)($params['dias_atraso'] ?? 0));
        $parcelas      = max(1, min(3, (int)($params['parcelas'] ?? 1)));
        $taxa_1x       = max(0.0, (float)($params['taxa_1x_percent'] ?? 0));
        $acrescimo     = max(0.0, (float)($params['acrescimo_percent'] ?? 0));

        // Multa + juros por atraso (so se houver atraso)
        $multa_juros = 0.00;
        if ($dias_atraso > 0) {
            $multa = ($valor_base / 100) * $multa_pct;
            $juros = ($valor_base / 100) * ($juros_pct_mes * $dias_atraso);
            $multa_juros = rp_arred_baixo($multa + $juros, 2);
        }

        $valor_sem_taxa = $valor_base + $multa_juros;

        // Taxa do gateway (depende das parcelas)
        $taxa_gateway = 0.00;
        if ($parcelas === 1) {
            // 1x: aplica taxa fixa do gateway (default 5%)
            $taxa_gateway = rp_arred_baixo(($valor_sem_taxa / 100) * $taxa_1x, 2);
        } elseif ($parcelas === 2) {
            // 2x: aplica acrescimo (uma vez)
            $taxa_gateway = rp_arred_baixo(($valor_sem_taxa / 100) * $acrescimo, 2);
        } elseif ($parcelas === 3) {
            // 3x: aplica acrescimo dobrado
            $taxa_gateway = rp_arred_baixo(($valor_sem_taxa / 100) * ($acrescimo * 2), 2);
        }

        $valor_final = round($valor_sem_taxa + $taxa_gateway, 2);
        $parcela_unitaria = round($valor_final / $parcelas, 2);

        return [
            'valor_base'        => round($valor_base, 2),
            'multa_juros'       => round($multa_juros, 2),
            'valor_sem_taxa'    => round($valor_sem_taxa, 2),
            'taxa_gateway'      => round($taxa_gateway, 2),
            'valor_final'       => $valor_final,
            'parcela_unitaria'  => $parcela_unitaria,
            'dias_atraso'       => $dias_atraso,
            'parcelas'          => $parcelas,
        ];
    }
}

if (!function_exists('rp_buscar_dados_fatura_pagamento')) {
    /**
     * Busca dados completos da fatura no banco para usar em calculos.
     * Inclui valor base com adicionais/descontos e dias de atraso.
     *
     * @return array|null  ['valor_base'=>X, 'dias_atraso'=>Y, 'multa_pct'=>Z, ...]
     */
    function rp_buscar_dados_fatura_pagamento($conn, $titulo) {
        if (!$conn || !$titulo || empty($titulo['id'])) return null;

        $id_titulo  = (int) $titulo['id'];
        $valor_base = (float) ($titulo['valor'] ?? 0);
        $datavenc   = $titulo['datavenc'] ?? date('Y-m-d');
        $numconta   = $titulo['numconta'] ?? '';

        // Adicionais (multas/lancamentos extras NAO descontos)
        $r = $conn->query("SELECT COALESCE(SUM(valor),0) as t
                           FROM sis_mlanc
                           WHERE idlanc = $id_titulo AND tipo != 'desconto'");
        if ($r) {
            $valor_base += (float) $r->fetch_assoc()['t'];
        }

        // Descontos
        $r = $conn->query("SELECT COALESCE(SUM(valor),0) as t
                           FROM sis_mlanc
                           WHERE idlanc = $id_titulo AND tipo = 'desconto'");
        if ($r) {
            $valor_base -= (float) $r->fetch_assoc()['t'];
        }
        $valor_base = max(0, $valor_base);

        // Multa e juros configurados em sis_boleto
        $multa_pct  = 0.0;
        $juros_pct  = 0.0;
        $numconta_safe = $conn->real_escape_string((string)$numconta);
        if (!empty($numconta_safe)) {
            $r = $conn->query("SELECT multa, juros FROM sis_boleto
                               WHERE id = '$numconta_safe' LIMIT 1");
            if ($r && $row = $r->fetch_assoc()) {
                $multa_pct = (float) $row['multa'];
                $juros_pct = (float) $row['juros'];
            }
        }

        // Dias de atraso
        $hoje = date('Y-m-d');
        $dias_atraso = 0;
        if ($datavenc < $hoje) {
            $d1 = new DateTime($datavenc);
            $d2 = new DateTime($hoje);
            $dias_atraso = (int) $d1->diff($d2)->days;
        }

        return [
            'valor_base'           => $valor_base,
            'multa_percent'        => $multa_pct,
            'juros_percent_mensal' => $juros_pct,
            'dias_atraso'          => $dias_atraso,
            'datavenc'             => $datavenc,
        ];
    }
}

if (!function_exists('rp_buscar_taxas_cartao')) {
    /**
     * Busca taxas do cartao (gateway) configuradas no banco.
     * Usa integrar_cartao_mp.acrescimo + addon_msg_config.taxa_1x_percent (se existir).
     */
    function rp_buscar_taxas_cartao($conn) {
        $taxa_1x_percent   = 5.0;  // default 5%
        $acrescimo_percent = 5.0;  // default 5%

        if (!$conn) return ['taxa_1x_percent'=>$taxa_1x_percent, 'acrescimo_percent'=>$acrescimo_percent];

        $r = $conn->query("SELECT acrescimo FROM integrar_cartao_mp LIMIT 1");
        if ($r && $row = $r->fetch_assoc()) {
            $acrescimo_percent = (float) ($row['acrescimo'] ?? 5.0);
        }

        // Permite override via addon_msg_config (chave taxa_1x_percent)
        $r = $conn->query("SELECT valor FROM addon_msg_config WHERE chave = 'taxa_1x_percent' LIMIT 1");
        if ($r && $row = $r->fetch_assoc()) {
            /* 11/10/2026: 0 também vale (provedor que não cobra taxa à vista); vazio = padrão 5% */
            if (trim((string)$row['valor']) !== '' && is_numeric(str_replace(',', '.', $row['valor']))) $taxa_1x_percent = max(0.0, (float)str_replace(',', '.', $row['valor']));
        }

        return [
            'taxa_1x_percent'   => $taxa_1x_percent,
            'acrescimo_percent' => $acrescimo_percent,
        ];
    }
}

/**
 * ============================================================
 * AUTH - Trava de login MK-AUTH (corrige bug #3 da auditoria)
 * ============================================================
 */
if (!function_exists('rp_require_mkauth_session')) {
    /**
     * Verifica se o usuario tem login ativo no MK-AUTH.
     *
     * O MK-AUTH usa cookies proprios para auth (nao $_SESSION nativo):
     *   - MKASESSID         = ID da sessao customizada
     *   - _admin-<hash>-MKA = token de admin logado (existe SOMENTE se logado)
     *   - campoCLI          = lembra que tipo de login foi feito
     *
     * Estrategia: verificar a presenca do cookie _admin-* que so
     * existe enquanto o admin esta logado.
     *
     * Pode ser desativado via $cfg['skip_auth'] = true em rp_config_local.php
     */
    function rp_require_mkauth_session() {
        // Permite desativar via config local (debug/dev)
        if (defined('RP_SKIP_AUTH') && RP_SKIP_AUTH === true) {
            // ATENCAO: imprime aviso bem visivel se skip_auth ativo em modo NAO-cli e NAO-AJAX
            if (php_sapi_name() !== 'cli'
                && empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                && (!isset($GLOBALS['rp_skip_warned']) || !$GLOBALS['rp_skip_warned'])) {

                $GLOBALS['rp_skip_warned'] = true;

                $log_file = __DIR__ . '/logs/seguranca.log';
                @mkdir(dirname($log_file), 0775, true);
                $marker = __DIR__ . '/logs/.skip_auth_last';
                $last = is_file($marker) ? (int)file_get_contents($marker) : 0;
                if (time() - $last > 3600) {
                    @file_put_contents($marker, time());
                    @file_put_contents($log_file,
                        '[' . date('Y-m-d H:i:s') . "] ⚠ SKIP_AUTH ATIVO. ip=" . ($_SERVER['REMOTE_ADDR'] ?? 'cli') . "\n",
                        FILE_APPEND);
                    if (function_exists('rp_alertar_telegram')) {
                        @rp_alertar_telegram(
                            "🚨 ALERTA: Resumo_Pagamentos acessivel SEM LOGIN (skip_auth=true). Desative em rp_config_local.php.",
                            'erro', ['skip_auth' => true]
                        );
                    }
                }

                if (!headers_sent()) {
                    echo '<div style="background:#dc2626;color:white;padding:14px 20px;font-family:system-ui;font-weight:700;text-align:center;border-bottom:3px solid #7f1d1d;position:sticky;top:0;z-index:99999">
                        🚨 ALERTA — Modo DEBUG ativo (skip_auth=true). Addon acessivel SEM LOGIN.
                    </div>';
                }
            }
            return;
        }

        $logado = rp_validar_sessao_mkauth();

        if (!$logado) {
            // Apaga cookies invalidos pra forcar limpeza
            foreach ($_COOKIE as $name => $value) {
                if (preg_match('/^_admin-.+-MKA$/i', $name) || in_array($name, ['_login-MKA', '_senha-MKA'])) {
                    setcookie($name, '', time() - 3600, '/');
                }
            }

            $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                       strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
            if ($is_ajax) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'erro' => 'Sessao expirada. Faca login novamente.']);
                exit;
            }

            // Log da tentativa
            $log_file = __DIR__ . '/logs/seguranca.log';
            @mkdir(dirname($log_file), 0775, true);
            @file_put_contents($log_file,
                '[' . date('Y-m-d H:i:s') . '] BLOQUEIO: acesso sem auth. ip=' . ($_SERVER['REMOTE_ADDR'] ?? '?') .
                ' uri=' . ($_SERVER['REQUEST_URI'] ?? '?') . "\n",
                FILE_APPEND);

            header('Location: /admin/login.php');
            exit;
        }
    }
}

if (!function_exists('rp_validar_sessao_mkauth')) {
    /**
     * Validacao da sessao MK-AUTH.
     *
     * O MK-AUTH original usa cookies httponly setados durante o login.
     * Esses cookies sao:
     *   - _login-MKA   : nome do usuario logado
     *   - _admin-HASH-MKA : token de admin (so existe se logou como admin)
     *
     * Esses cookies sao gerados pelo PROPRIO MK-AUTH com segredo interno e
     * sao httponly (nao podem ser lidos/forjados via JS).
     *
     * Estrategia: se tem o cookie _admin-*-MKA preenchido E o _login-MKA,
     * eh um admin logado. Verifica no banco que o login existe como bonus.
     */
    function rp_validar_sessao_mkauth() {
        /* 01/10/2026: antes bastava EXISTIR o cookie _admin-*-MKA, só que a própria tela de
           login do MK-Auth cria esse cookie para qualquer visitante (é o cookie da sessão PHP).
           Agora a sessão por trás do cookie tem que estar LOGADA: o arquivo de sessão do PHP
           tem MKA_Logado + MKA_Usuario, ou o cookie bate com sis_acesso.sesid de um acesso ativo. */
        $vals = [];
        foreach (($_COOKIE ?? []) as $name => $value) {
            if (preg_match('/^_admin-.+-MKA$/i', $name) && is_string($value) && preg_match('/^[A-Za-z0-9,-]{20,128}$/', $value)) $vals[] = $value;
        }
        if (!$vals) return false;
        $dir = (string)(ini_get('session.save_path') ?: '/var/tmp');
        if (strpos($dir, ';') !== false) $dir = substr($dir, strrpos($dir, ';') + 1);
        $dir = rtrim($dir, '/');
        foreach ($vals as $v) {
            foreach (array_unique([$dir, '/var/tmp']) as $d) {
                $f = $d . '/sess_' . $v;
                if (is_readable($f)) {
                    $c = (string)@file_get_contents($f, false, null, 0, 65536);
                    if (preg_match('/MKA_Logado\|s:[1-9]\d*:"[^"]+"/', $c) && preg_match('/MKA_Usuario\|s:[1-9]\d*:/', $c)) return true;
                }
            }
        }
        $conn = @rp_db_connect();
        if (!$conn || $conn->connect_errno) return false;
        $in = implode(',', array_map(function ($v) use ($conn) { return "'" . $conn->real_escape_string($v) . "'"; }, $vals));
        $r = @$conn->query("SELECT 1 FROM sis_acesso WHERE ativo='sim' AND sesid<>'' AND sesid IN ($in) LIMIT 1");
        return ($r && $r->num_rows > 0);
    }
}

if (!function_exists('rp_session_has_login_marker')) {
    /**
     * Heuristica adicional: percorre TODAS as chaves de $_SESSION
     * procurando padroes que indicam login.
     */
    function rp_session_has_login_marker($session) {
        if (empty($session) || !is_array($session)) return false;
        foreach ($session as $k => $v) {
            // Variaveis nao vazias com nomes que sugerem auth
            if (!empty($v) && preg_match('/^(logado|login|user|usuario|admin|nivel|auth|sess)/i', $k)) {
                return true;
            }
        }
        return false;
    }
}
