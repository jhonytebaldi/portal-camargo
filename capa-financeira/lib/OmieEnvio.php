<?php
/* =====================================================================
   capa-financeira/lib/OmieEnvio.php — envio dos lançamentos confirmados
   direto para o Omie pela API (alternativa à planilha de importação).

   - catalogo():  cadastros da empresa no Omie (contas correntes, categorias,
                  departamentos, projetos) com cache em cf_config (6 h).
   - conferir():  monta o pedido (IncluirContaPagar/Receber) e lista erros
                  (bloqueiam a linha) e avisos — espelha as recusas que a
                  importação por planilha faria, antes de enviar.
   - enviar():    inclui o título, confere de volta (Consultar) e grava o
                  log em cf_envios_omie.
   - excluir():   consulta o status; se já baixado não exclui; senão
                  ExcluirContaPagar/Receber e log.
   Chave de tudo: codigo_lancamento_integracao = nosso código (1375-P001).
   ===================================================================== */
declare(strict_types=1);

final class OmieEnvio
{
    public const CACHE_SEG = 6 * 3600;

    /* ---------------- catálogo (cadastros do Omie) ---------------- */

    /** @return array{contas: array<string,int>, categorias: array<string,array>, departamentos: array<string,string>, projetos: array<string,int>, quando: int} */
    public static function catalogo(string $empresa, bool $forcar = false): array
    {
        $chave = 'omie_catalogo_' . $empresa;
        $c = cf_config($chave, null);
        if (!$forcar && is_array($c) && (time() - (int)($c['quando'] ?? 0)) < self::CACHE_SEG) return $c;
        $cat = ['contas' => [], 'contas_info' => [], 'categorias' => [], 'departamentos' => [], 'projetos' => [], 'quando' => time()];
        foreach (self::paginar($empresa, 'geral/contacorrente/', 'ListarContasCorrentes', 'ListarContasCorrentes') as $x) {
            $cat['contas'][CapaParser::key((string)$x['descricao'])] = (int)$x['nCodCC'];
            $cat['contas_info'][(int)$x['nCodCC']] = ['nome' => $x['descricao'], 'banco' => (string)($x['codigo_banco'] ?? ''), 'tipo' => (string)($x['tipo'] ?? '')];
        }
        foreach (self::paginar($empresa, 'geral/categorias/', 'ListarCategorias', 'categoria_cadastro') as $x) {
            $cat['categorias'][(string)$x['codigo']] = ['descricao' => $x['descricao'], 'lancavel' => ($x['totalizadora'] ?? 'N') !== 'S' && ($x['conta_inativa'] ?? 'N') !== 'S' && ($x['nao_exibir'] ?? 'N') !== 'S',
                                                        'despesa' => ($x['conta_despesa'] ?? '') === 'S', 'receita' => ($x['conta_receita'] ?? '') === 'S'];
        }
        foreach (self::paginar($empresa, 'geral/departamentos/', 'ListarDepartamentos', 'departamentos') as $x) {
            if (($x['inativo'] ?? 'N') === 'S') continue;
            $cat['departamentos'][CapaParser::key((string)$x['descricao'])] = (string)$x['codigo'];
        }
        try {
            foreach (self::paginar($empresa, 'geral/projetos/', 'ListarProjetos', 'cadastro') as $x) {
                if (($x['inativo'] ?? 'N') === 'S') continue;
                $cat['projetos'][CapaParser::key((string)$x['nome'])] = (int)$x['codigo'];
            }
        } catch (Throwable $e) { /* empresa sem projetos */ }
        cf_config_set($chave, $cat);
        return $cat;
    }

    private static function paginar(string $empresa, string $path, string $metodo, string $lista): array
    {
        $out = []; $pag = 1;
        do {
            try { $r = OmieApi::call($empresa, $path, $metodo, ['pagina' => $pag, 'registros_por_pagina' => 200]); }
            catch (RuntimeException $e) { if (preg_match('/n[ãa]o existem registros|Client-5113/iu', $e->getMessage())) break; throw $e; }
            foreach ($r[$lista] ?? [] as $x) $out[] = $x;
            $tot = (int)($r['total_de_paginas'] ?? 1); $pag++;
        } while ($pag <= $tot && $pag < 30);
        return $out;
    }

    /* ---------------- fornecedor / cliente ---------------- */

    /**
     * Localiza o cadastro no Omie: por CPF/CNPJ (exato) e, se não achar, pelo nome (razão social exata; senão a lista).
     * @return array{status: 'ok'|'so_nome'|'varios'|'nao', cadastro: ?array, msg: string}
     */
    public static function localizarCadastro(string $empresa, string $doc, string $nome): array
    {
        static $cache = [];
        $dig = preg_replace('/\D+/', '', $doc); $k = $empresa . '|' . $dig . '|' . CapaParser::key($nome);
        if (isset($cache[$k])) return $cache[$k];
        $achou = null;
        if (in_array(strlen($dig), [11, 14], true)) {
            foreach (self::listarClientes($empresa, ['cnpj_cpf' => $doc]) as $c) if (preg_replace('/\D+/', '', (string)($c['cnpj_cpf'] ?? '')) === $dig) { $achou = $c; break; }
            if ($achou) return $cache[$k] = ['status' => 'ok', 'cadastro' => $achou, 'msg' => 'cadastrado no Omie: ' . ($achou['razao_social'] ?? '') . ' (cód. ' . $achou['codigo_cliente_omie'] . ')'];
        }
        $lista = [];
        if ($nome !== '') {
            $lista = self::listarClientes($empresa, ['razao_social' => $nome]);
            $kn = CapaParser::key($nome);
            $exatos = array_values(array_filter($lista, fn($c) => CapaParser::key((string)($c['razao_social'] ?? '')) === $kn || CapaParser::key((string)($c['nome_fantasia'] ?? '')) === $kn));
            if ($exatos) $lista = $exatos;
        }
        if (count($lista) === 1) {
            $c = $lista[0]; $cd = (string)($c['cnpj_cpf'] ?? '');
            $r = $dig !== ''
                ? ['status' => 'so_nome', 'cadastro' => $c, 'msg' => "CPF/CNPJ $doc não está no Omie, mas existe cadastro com esse nome: {$c['razao_social']} (cód. {$c['codigo_cliente_omie']}" . ($cd ? ", doc. $cd" : ', sem documento') . ') — confira/corrija o documento no cadastro do Omie']
                : ['status' => 'ok', 'cadastro' => $c, 'msg' => "cadastrado no Omie: {$c['razao_social']} (cód. {$c['codigo_cliente_omie']}" . ($cd ? ", doc. $cd" : ', sem documento') . ')'];
        } elseif (count($lista) > 1) $r = ['status' => 'varios', 'cadastro' => null, 'msg' => count($lista) . ' cadastros com esse nome no Omie — informe o CPF/CNPJ para não dar ambiguidade'];
        else $r = ['status' => 'nao', 'cadastro' => null, 'msg' => 'NÃO encontrado no Omie' . ($dig !== '' ? " (nem pelo documento $doc nem pelo nome \"$nome\")" : " (pelo nome \"$nome\")") . ' — cadastre antes de enviar'];
        return $cache[$k] = $r;
    }

    private static function listarClientes(string $empresa, array $filtro): array
    {
        try { $r = OmieApi::call($empresa, 'geral/clientes/', 'ListarClientes', ['pagina' => 1, 'registros_por_pagina' => 50, 'apenas_importado_api' => 'N', 'clientesFiltro' => $filtro]); return $r['clientes_cadastro'] ?? []; }
        catch (RuntimeException $e) { if (preg_match('/n[ãa]o existem registros|Client-5113|n[ãa]o (foi )?(encontrad|localizad)/iu', $e->getMessage())) return []; throw $e; }
    }

    /* ---------------- montagem + conferência ---------------- */

    /**
     * Monta o pedido de inclusão e confere tudo o que a importação recusaria.
     * $m = resultado de Exportacao::montar (mesmas regras de negócio da planilha).
     * @return array{pedido: array, erros: string[], avisos: string[], cadastro: ?array}
     */
    public static function conferir(string $empresa, string $tipo, array $l, array $capa, ?array $p, array $emp, array $m, array $opts, array $cat, string $porQuem): array
    {
        $erros = $m['erros']; $avisos = $m['avisos']; $c = $m['cols'];
        $ped = ['codigo_lancamento_integracao' => (string)($l['codigo_integracao'] ?? '')];

        // fornecedor / cliente → código Omie
        $texto = (string)($c['C']['s'] ?? ''); $cadastro = null;
        if ($texto !== '') {
            $dig = preg_replace('/\D+/', '', $texto);
            $doc = in_array(strlen($dig), [11, 14], true) ? $texto : '';
            $nome = $doc !== '' ? ($tipo === 'P' ? (string)($p['razao_social'] ?: $p['nome'] ?? '') : Exportacao::clientePadrao((string)$capa['cliente'])) : $texto;
            $loc = self::localizarCadastro($empresa, $doc, $nome);
            $cadastro = $loc['cadastro'];
            if ($loc['status'] === 'ok') $ped['codigo_cliente_fornecedor'] = (int)$cadastro['codigo_cliente_omie'];
            elseif ($loc['status'] === 'so_nome') { $ped['codigo_cliente_fornecedor'] = (int)$cadastro['codigo_cliente_omie']; $avisos[] = ($tipo === 'P' ? 'fornecedor: ' : 'cliente: ') . $loc['msg']; }
            else $erros[] = ($tipo === 'P' ? 'fornecedor ' : 'cliente ') . $loc['msg'];
            if ($cadastro && ($cadastro['inativo'] ?? 'N') === 'S') $erros[] = 'cadastro inativo no Omie: ' . $cadastro['razao_social'];
        }

        // categoria: só o código; tem que existir e ser lançável
        // (na planilha a categoria vai pelo nome; na API é pelo código — aceita "2.02.91 Nome", "2.02.91" ou só o nome)
        $catTxt = trim((string)($l['categoria'] ?? ''));
        $cod = preg_match('/^(\d+(?:\.\d+)+)/', $catTxt, $mm) ? $mm[1] : '';
        if ($cod === '' || !isset($cat['categorias'][$cod])) {
            $descKey = CapaParser::key($cod !== '' ? trim(substr($catTxt, strlen($cod))) : $catTxt);
            $achados = array_keys(array_filter($cat['categorias'], fn($x) => CapaParser::key((string)$x['descricao']) === $descKey));
            if (count($achados) === 1) $cod = (string)$achados[0];
            elseif (count($achados) > 1) { $erros[] = "categoria \"$catTxt\" existe " . count($achados) . 'x no Omie (' . implode(', ', $achados) . ') — informe o código em Configurações'; $cod = ''; }
        }
        if ($cod === '') { if (!in_array(substr(end($erros) ?: '', 0, 9), ['categoria'], true)) $erros[] = "categoria \"$catTxt\" não existe no Omie desta empresa (nem pelo código nem pelo nome)"; }
        elseif (!isset($cat['categorias'][$cod])) $erros[] = "categoria $cod não existe no Omie desta empresa";
        elseif (!$cat['categorias'][$cod]['lancavel']) $erros[] = "categoria $cod é totalizadora/inativa no Omie — use uma categoria lançável";
        elseif ($tipo === 'P' && $cat['categorias'][$cod]['receita']) $erros[] = "categoria $cod é de receita — conta a pagar precisa de categoria de despesa";
        elseif ($tipo === 'R' && $cat['categorias'][$cod]['despesa']) $erros[] = "categoria $cod é de despesa — conta a receber precisa de categoria de receita";
        else $ped['codigo_categoria'] = $cod;

        // conta corrente → id
        $conta = trim((string)($c['E']['s'] ?? ''));
        if ($conta !== '') {
            $id = $cat['contas'][CapaParser::key($conta)] ?? null;
            if ($id) $ped['id_conta_corrente'] = $id; else $erros[] = "conta corrente \"$conta\" não existe no Omie desta empresa (existem: " . implode(', ', array_column($cat['contas_info'], 'nome')) . ')';
        }

        // valor e datas
        $valor = round((float)($l['valor'] ?? 0), 2);
        if ($valor <= 0) $erros[] = 'valor zerado ou negativo';
        $ped['valor_documento'] = $valor;
        $prev = (string)($l['data_prevista'] ?? '');
        if ($prev !== '') {
            $ano = (int)substr($prev, 0, 4); $hoje = (int)date('Y');
            if ($ano < 2000 || $ano > $hoje + 3) $erros[] = "vencimento com ano $ano — corrija a data prevista na revisão";
            $ped['data_vencimento'] = self::br($prev); $ped['data_previsao'] = self::br($prev);
        }
        if (!empty($c['I']['d'])) { $ped['data_emissao'] = self::br((string)$c['I']['d']); $ae = (int)substr((string)$c['I']['d'], 0, 4); if ($ae < 2000 || $ae > (int)date('Y') + 1) $erros[] = 'data de emissão com ano fora do razoável'; }
        if (!empty($c['J']['d'])) $ped['data_entrada'] = self::br((string)$c['J']['d']);

        // documento / NF / parcela
        $ped['numero_documento'] = mb_substr((string)$c['U']['s'], 0, 20);
        if (!empty($c['Y']['s'])) $ped['numero_documento_fiscal'] = mb_substr((string)$c['Y']['s'], 0, 20);
        if ($tipo === 'R' && !empty($l['parcela']) && !empty($l['total_parcelas'])) $ped['numero_parcela'] = sprintf('%03d/%03d', (int)$l['parcela'], (int)$l['total_parcelas']);
        else $ped['numero_parcela'] = '001/001';

        // projeto
        if (!empty($c['H']['s'])) {
            $pid = $cat['projetos'][CapaParser::key((string)$c['H']['s'])] ?? null;
            if ($pid) $ped['codigo_projeto'] = $pid; else $erros[] = 'projeto "' . $c['H']['s'] . '" não existe no Omie';
        }

        // departamento (rateio 100%)
        $depTxt = trim((string)(($tipo === 'P' ? ($c['AX']['s'] ?? '') : ($c['AP']['s'] ?? ''))));
        if ($depTxt !== '') {
            $dcod = $cat['departamentos'][CapaParser::key($depTxt)] ?? null;
            if ($dcod) $ped['distribuicao'] = [['cCodDep' => $dcod, 'cDesDep' => $depTxt, 'nValDep' => $valor, 'nPerDep' => 100]];
            else $erros[] = "departamento \"$depTxt\" (cadastro da pessoa) não existe no Omie — corrija em Pessoas (existem: " . implode(', ', array_slice(array_map(fn($k) => $k, array_keys($cat['departamentos'])), 0, 8)) . '…)';
        }

        // forma de pagamento (Pix) — só contas a pagar
        $pix = trim((string)($l['chave_pix'] ?? ''));
        if ($tipo === 'P' && $pix !== '' && $cadastro) {
            $modo = (string)cf_config('omie_pix_modo', 'TRA');
            if ($modo === 'TRA') $ped['cnab_integracao_bancaria'] = ['codigo_forma_pagamento' => 'TRA', 'finalidade_transferencia' => '01.3', 'pix_qrcode' => $pix,
                'cpf_cnpj_transferencia' => (string)($cadastro['cnpj_cpf'] ?? ''), 'nome_transferencia' => mb_substr((string)($cadastro['razao_social'] ?? ''), 0, 60)];
            elseif ($modo === 'PIX') $ped['cnab_integracao_bancaria'] = ['codigo_forma_pagamento' => 'PIX', 'pix_qrcode' => $pix];
            $pixCad = trim((string)($cadastro['dadosBancarios']['cChavePix'] ?? ''));
            if ($pixCad === '') $avisos[] = 'fornecedor sem chave Pix no cadastro do Omie (a chave vai no título e na observação)';
            elseif (preg_replace('/\D+/', '', $pixCad) !== preg_replace('/\D+/', '', $pix) && mb_strtolower($pixCad) !== mb_strtolower($pix)) $avisos[] = "chave Pix do cadastro no Omie ($pixCad) é diferente da nossa ($pix)";
        }

        // observação: quem enviou + padrão + Pix
        $obs = 'ENVIADO POR: ' . mb_strtoupper(self::semAcento($porQuem)) . ' ' . (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('d/m/Y H:i') . ' | ' . (string)($c['S']['s'] ?? '');
        if ($tipo === 'P' && $pix !== '') $obs .= ' | PIX: ' . $pix;
        $ped['observacao'] = $obs;

        // já existe no Omie? (mesmo código de integração) e possível duplicidade (mesmo doc + vencimento + valor)
        $existente = null;
        if ($ped['codigo_lancamento_integracao'] !== '' && !$erros) {
            $ex = self::consultar($empresa, $tipo, $ped['codigo_lancamento_integracao']);
            if ($ex) {
                // o código de integração é nosso: se o título já está lá com o mesmo valor e vencimento, é este lançamento (envio anterior
                // que deu erro de comunicação, p.ex.) → não reenvia, vincula. Se difere, é conflito e bloqueia.
                $bate = abs((float)($ex['valor_documento'] ?? 0) - $valor) < 0.005 && (string)($ex['data_vencimento'] ?? '') === ($ped['data_vencimento'] ?? '');
                if ($bate) { $existente = $ex; $avisos[] = 'já existe no Omie com este código (cód. ' . ($ex['codigo_lancamento_omie'] ?? '?') . ', status ' . ($ex['status_titulo'] ?? '?') . ') — será vinculado, não reenviado'; }
                else $erros[] = 'já existe no Omie um título com este código de integração mas com valor/vencimento DIFERENTES (cód. Omie ' . ($ex['codigo_lancamento_omie'] ?? '?') . ', ' . ($ex['valor_documento'] ?? '?') . ' venc. ' . ($ex['data_vencimento'] ?? '?') . ') — confira no Omie';
            } elseif ($cadastro && $prev !== '') {
                try {
                    $r = OmieApi::call($empresa, 'financas/pesquisartitulos/', 'PesquisarLancamentos', ['nPagina' => 1, 'nRegPorPagina' => 50, 'cNatureza' => $tipo, 'cCPFCNPJCliente' => (string)($cadastro['cnpj_cpf'] ?? ''), 'dDtVencDe' => self::br($prev), 'dDtVencAte' => self::br($prev)]);
                    foreach ($r['titulosEncontrados'] ?? [] as $t) { $h = $t['cabecTitulo'] ?? $t;
                        if (abs((float)($h['nValorTitulo'] ?? 0) - $valor) < 0.005) { $avisos[] = 'possível duplicidade: já há no Omie um título deste ' . ($tipo === 'P' ? 'fornecedor' : 'cliente') . ' com o mesmo valor e vencimento (cód. ' . ($h['nCodTitulo'] ?? '?') . ', doc. ' . ($h['cNumDocFiscal'] ?? $h['cCodIntTitulo'] ?? '') . ')'; break; } }
                } catch (RuntimeException $e) { /* sem títulos = ok */ }
            }
        }
        return ['pedido' => $ped, 'erros' => array_values(array_unique($erros)), 'avisos' => array_values(array_unique($avisos)), 'cadastro' => $cadastro, 'existente' => $existente];
    }

    /* ---------------- API: incluir / consultar / excluir ---------------- */

    private static function path(string $tipo): string { return $tipo === 'P' ? 'financas/contapagar/' : 'financas/contareceber/'; }
    private static function sufixo(string $tipo): string { return $tipo === 'P' ? 'ContaPagar' : 'ContaReceber'; }

    /** título pelo código de integração, ou null se não existe */
    public static function consultar(string $empresa, string $tipo, string $codigo): ?array
    {
        try { return OmieApi::call($empresa, self::path($tipo), 'Consultar' . self::sufixo($tipo), ['codigo_lancamento_integracao' => $codigo]); }
        catch (RuntimeException $e) { if (preg_match('/n[ãa]o cadastrado|n[ãa]o (foi )?(encontrad|localizad)|Client-105|Client-8020/iu', $e->getMessage())) return null; throw $e; }
    }

    public static function incluir(string $empresa, string $tipo, array $pedido): array
    {
        $r = OmieApi::call($empresa, self::path($tipo), 'Incluir' . self::sufixo($tipo), $pedido);
        OmieApi::esquecer($empresa, self::path($tipo), 'Consultar' . self::sufixo($tipo), ['codigo_lancamento_integracao' => (string)($pedido['codigo_lancamento_integracao'] ?? '')]);
        return $r;
    }
    /** conferência depois de incluir: consulta pelo código Omie (chamada diferente da pré-checagem, não cai no "consumo redundante") */
    public static function consultarPorOmieId(string $empresa, string $tipo, int $omieId): ?array
    {
        try { return OmieApi::call($empresa, self::path($tipo), 'Consultar' . self::sufixo($tipo), ['codigo_lancamento_omie' => $omieId]); }
        catch (RuntimeException $e) { if (preg_match('/n[ãa]o cadastrado|n[ãa]o (foi )?(encontrad|localizad)|Client-105|Client-103/iu', $e->getMessage())) return null; throw $e; }
    }
    public static function erroTemporario(string $msg): bool { return (bool)preg_match('/REDUNDANT|MISUSE|Client-6\]|bloqueada temporariamente|indispon[ií]vel|timed out|timeout/iu', $msg); }

    /** Exclui se ainda não foi baixado. @return array{ok: bool, msg: string} */
    public static function excluir(string $empresa, string $tipo, string $codigo): array
    {
        $t = self::consultar($empresa, $tipo, $codigo);
        if (!$t) return ['ok' => true, 'msg' => 'já não existia no Omie'];
        $st = (string)($t['status_titulo'] ?? ''); $pago = (float)($t['valor_pag'] ?? 0);
        if ($pago > 0 || in_array(strtoupper($st), ['PAGO', 'RECEBIDO', 'LIQUIDADO', 'PAGO PARC', 'RECEBIDO PARC'], true) || preg_match('/PAG|RECEB|LIQ/i', $st))
            return ['ok' => false, 'msg' => "não excluído: o título já tem baixa no Omie (status $st, pago " . number_format($pago, 2, ',', '.') . ') — trate manualmente'];
        $chave = $tipo === 'P' ? ['codigo_lancamento_integracao' => $codigo] : ['codigo_lancamento_integracao' => $codigo];
        OmieApi::call($empresa, self::path($tipo), 'Excluir' . self::sufixo($tipo), $chave);
        OmieApi::esquecer($empresa, self::path($tipo), 'Consultar' . self::sufixo($tipo), ['codigo_lancamento_integracao' => $codigo]);
        return ['ok' => true, 'msg' => 'excluído no Omie (status era ' . $st . ')'];
    }

    /* ---------------- util ---------------- */

    public static function br(string $iso): string { return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m) ? "$m[3]/$m[2]/$m[1]" : $iso; }
    private static function semAcento(string $s): string { $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s); return $t !== false ? preg_replace('/[^A-Za-z0-9 .\-]/', '', $t) : $s; }
}
