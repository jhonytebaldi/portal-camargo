<?php
/* =====================================================================
   recibos-omie/lib/Titulo.php — um título a pagar do Omie num formato
   único, venha do relatório .xlsx ("Finanças - Contas a Pagar") ou da
   API (PesquisarLancamentos). Interpreta a Observação nos dois padrões:
     novo:   RECIBO: 1375-P001 | FAVORECIDO: … | FUNCAO: DIRETOR (BONUS) | CLIENTE: … | CONSTRUTORA: … | IMOVEL: … | STATUS: … | VENDA: dd/mm/aaaa | COD: 1375 | COND: …
     antigo: COMISSÃO CORRETOR ⏎ CLIENTE: … ⏎ CONSTRUTORA: … ⏎ ENDEREÇO: … (ou linha solta) ⏎ CÓD: 1402 ⏎ VENDA: 11/08/2026
             (pode começar com a condição, ex. "ENTREGA DE CHAVES"; linhas separadas por ⏎ no xlsx e por "|" na API)
   ===================================================================== */
declare(strict_types=1);

final class Titulo
{
    /** cabeçalhos do relatório → chave interna */
    private const COLS = ['Situação' => 'situacao', 'Parcela' => 'parcela', 'Nota Fiscal' => 'nota_fiscal', 'Fornecedor (Nome Fantasia)' => 'fantasia',
        'Previsão de Pagamento' => 'previsao', 'Valor da Conta' => 'valor', 'Valor a Pagar' => 'valor_aberto', 'Valor Pago' => 'valor_pago', 'Categoria' => 'categoria',
        'Conta Corrente' => 'conta_corrente', 'Vencimento' => 'vencimento', 'Data de Emissão' => 'emissao', 'Fornecedor (Razão Social)' => 'razao', 'Fornecedor (CNPJ/CPF)' => 'doc', 'Observação' => 'observacao'];

    /** lê o relatório do Omie (.xlsx, aba "financas"). Devolve ['conta' => id|null, 'empresa_txt' => …, 'titulos' => [...]] */
    public static function doRelatorio(string $path): array
    {
        require_once __DIR__ . '/../../capa-financeira/lib/Xlsx.php';
        $x = Xlsx::open($path);
        $titulo = trim((string)($x->ref('C1') ?? '')) ?: trim((string)($x->ref('A1') ?? ''));
        if (!str_contains(mb_strtoupper($titulo), 'CONTAS A PAGAR')) throw new RuntimeException('Não parece o relatório "Finanças - Contas a Pagar" do Omie (título: ' . ($titulo ?: 'vazio') . ')');
        // linha de cabeçalho: a que tem "Fornecedor" e "Vencimento"
        $hdr = null; $map = [];
        for ($r = 1; $r <= min(10, $x->maxRow()); $r++) {
            $nomes = []; for ($c = 1; $c <= $x->maxCol(); $c++) $nomes[$c] = trim((string)($x->cell($r, $c) ?? ''));
            if (in_array('Vencimento', $nomes, true) && in_array('Observação', $nomes, true)) { $hdr = $r; foreach ($nomes as $c => $n) if (isset(self::COLS[$n])) $map[self::COLS[$n]] = $c; break; }
        }
        if (!$hdr) throw new RuntimeException('Cabeçalho do relatório não encontrado (esperava colunas Vencimento / Observação)');
        $up = mb_strtoupper($titulo);
        $conta = str_contains($up, 'VERTICAL') ? 'vertical' : (str_contains($up, 'MZ7') ? 'mz7' : (str_contains($up, 'CAMARGO') ? 'camargo' : null));
        $get = fn(int $r, string $k) => isset($map[$k]) ? $x->cell($r, $map[$k]) : null;
        $data = function ($v): ?string { if ($v instanceof DateTimeInterface) return $v->format('Y-m-d'); if (is_numeric($v)) { $d = Xlsx::serialToDate((float)$v); return $d ? $d->format('Y-m-d') : null; } return OmieApi::iso(trim((string)$v)); };
        $tits = [];
        for ($r = $hdr + 1; $r <= $x->maxRow(); $r++) {
            $valor = $get($r, 'valor'); $doc = trim((string)$get($r, 'doc'));
            if ($valor === null && $doc === '') continue;
            $tits[] = self::normalizar([
                'origem' => 'xlsx', 'conta' => $conta, 'omie_id' => null, 'cod_int' => '', 'situacao' => (string)$get($r, 'situacao'), 'parcela' => (string)$get($r, 'parcela'),
                'nota_fiscal' => (string)$get($r, 'nota_fiscal'), 'razao' => trim((string)$get($r, 'razao')) ?: trim((string)$get($r, 'fantasia')), 'fantasia' => (string)$get($r, 'fantasia'), 'doc' => $doc,
                'previsao' => $data($get($r, 'previsao')), 'vencimento' => $data($get($r, 'vencimento')), 'emissao' => $data($get($r, 'emissao')),
                'valor' => round((float)$valor, 2), 'valor_pago' => round((float)$get($r, 'valor_pago'), 2), 'categoria' => (string)$get($r, 'categoria'), 'conta_corrente' => (string)$get($r, 'conta_corrente'),
                'observacao' => str_replace("\r", '', (string)$get($r, 'observacao')),
            ]);
        }
        return ['conta' => $conta, 'empresa_txt' => $titulo, 'titulos' => $tits];
    }

    /** título vindo da API (item de titulosEncontrados) */
    public static function daApi(PDO $pdo, string $conta, array $t, array $categorias): array
    {
        $c = $t['cabecTitulo'] ?? $t; $res = $t['resumo'] ?? [];
        $forn = !empty($c['nCodCliente']) ? OmieApi::fornecedor($pdo, $conta, (int)$c['nCodCliente']) : [];
        return self::normalizar([
            'origem' => 'api', 'conta' => $conta, 'omie_id' => (int)($c['nCodTitulo'] ?? 0), 'cod_int' => (string)($c['cCodIntTitulo'] ?? ''), 'situacao' => self::situacao((string)($c['cStatus'] ?? '')),
            'parcela' => (string)($c['cNumParcela'] ?? ''), 'nota_fiscal' => (string)($c['cNumDocFiscal'] ?? ''), 'razao' => (string)($forn['razao_social'] ?? ''), 'fantasia' => (string)($forn['nome_fantasia'] ?? ''),
            'doc' => (string)($c['cCPFCNPJCliente'] ?? ($forn['cnpj_cpf'] ?? '')), 'previsao' => OmieApi::iso($c['dDtPrevisao'] ?? null), 'vencimento' => OmieApi::iso($c['dDtVenc'] ?? null), 'emissao' => OmieApi::iso($c['dDtEmissao'] ?? null),
            'valor' => round((float)($c['nValorTitulo'] ?? 0), 2), 'valor_pago' => round((float)($res['nValPago'] ?? 0), 2), 'categoria' => $categorias[(string)($c['cCodCateg'] ?? '')] ?? (string)($c['cCodCateg'] ?? ''),
            'conta_corrente' => '', 'observacao' => str_replace('|', "\n", (string)($c['observacao'] ?? '')), 'liquidado' => ($res['cLiquidado'] ?? 'N') === 'S',
        ]);
    }

    private static function situacao(string $s): string
    {
        $m = ['A VENCER' => 'A vencer', 'VENCE HOJE' => 'Vence hoje', 'ATRASADO' => 'Atrasado', 'PAGO' => 'Pago', 'PAGO PARC.' => 'Pago parcial', 'CANCELADO' => 'Cancelado'];
        return $m[strtoupper(trim($s))] ?? $s;
    }

    /** completa o registro com a observação interpretada, o fingerprint e o texto do recibo */
    public static function normalizar(array $t): array
    {
        $t['obs'] = self::interpretarObs($t['observacao'] ?? '');
        $t['doc_digitos'] = preg_replace('/\D+/', '', (string)$t['doc']) ?? '';
        $t['fingerprint'] = $t['omie_id'] ? 'omie:' . $t['conta'] . ':' . $t['omie_id']
            : 'fp:' . substr(sha1(implode('|', [$t['conta'] ?? '', $t['doc_digitos'], number_format((float)$t['valor'], 2, '.', ''), $t['vencimento'] ?? '', $t['categoria'] ?? '', $t['obs']['cod'] ?? '', $t['parcela'] ?? ''])), 0, 20);
        return $t;
    }

    /** @return array{recibo:?string, favorecido:?string, funcao:?string, natureza:string, cliente:?string, construtora:?string, imovel:?string, status:?string, venda:?string, cod:?string, cond:?string, parcela:?string, padrao:string} */
    public static function interpretarObs(string $obs): array
    {
        $o = ['recibo' => null, 'favorecido' => null, 'funcao' => null, 'natureza' => 'COMISSAO', 'cliente' => null, 'construtora' => null, 'imovel' => null, 'status' => null, 'venda' => null, 'cod' => null, 'cond' => null, 'parcela' => null, 'padrao' => 'vazio'];
        $obs = trim($obs);
        if ($obs === '') return $o;
        $novo = str_contains($obs, ' | ') && preg_match('/\b(RECIBO|FAVORECIDO|CLIENTE):/u', $obs);
        $partes = $novo ? preg_split('/\s*\|\s*/u', $obs) : preg_split('/\s*\n\s*/u', $obs);
        $o['padrao'] = $novo ? 'novo' : 'antigo';
        $rot = ['RECIBO' => 'recibo', 'FAVORECIDO' => 'favorecido', 'FUNCAO' => 'funcao', 'FUNÇÃO' => 'funcao', 'CLIENTE' => 'cliente', 'CONSTRUTORA' => 'construtora', 'IMOVEL' => 'imovel', 'IMÓVEL' => 'imovel', 'ENDERECO' => 'imovel', 'ENDEREÇO' => 'imovel',
                'STATUS' => 'status', 'VENDA' => 'venda', 'DATA DA VENDA' => 'venda', 'COD' => 'cod', 'CÓD' => 'cod', 'CODIGO' => 'cod', 'CÓDIGO' => 'cod', 'COND' => 'cond', 'CONDICAO' => 'cond', 'CONDIÇÃO' => 'cond', 'PARCELA' => 'parcela', 'FLUXO' => 'parcela'];
        $soltas = [];
        foreach ($partes as $p) {
            $p = trim($p); if ($p === '') continue;
            if (preg_match('/^([A-ZÀ-Ú ]{3,15}):\s*(.*)$/u', $p, $m) && isset($rot[mb_strtoupper(trim($m[1]))])) {
                $k = $rot[mb_strtoupper(trim($m[1]))]; $v = trim($m[2]);
                if ($k === 'funcao') { if (preg_match('/\(?\s*B[ÔO]NUS\s*\)?/iu', $v)) { $o['natureza'] = 'BONUS'; $v = trim(preg_replace('/\(?\s*B[ÔO]NUS\s*\)?/iu', '', $v)); } }
                if ($k === 'cod') $v = preg_replace('/\D+/', '', $v);
                if ($k === 'venda' && preg_match('/(\d{2})\/(\d{2})\/(\d{4})/', $v, $d)) $v = "$d[1]/$d[2]/$d[3]";
                $o[$k] = $v !== '' ? $v : null;
            } else $soltas[] = $p;
        }
        // linhas soltas do padrão antigo: "COMISSÃO CORRETOR", "BÔNUS CAPTADOR", condição ("ENTREGA DE CHAVES", "RESERVA 50%"), endereço sem rótulo
        foreach ($soltas as $s) {
            $u = mb_strtoupper(self::semAcento($s));
            if (preg_match('/^(COMISSAO|BONUS)\s+([A-Z][A-Z \-]{2,30})$/u', $u, $m)) {
                if ($m[1] === 'BONUS') $o['natureza'] = 'BONUS';
                $o['funcao'] = $o['funcao'] ?? (trim($m[2]) === 'GERENTE' ? 'COORDENADOR' : trim($m[2]));
            } elseif (preg_match('/^(COMISSAO|BONUS)$/u', $u)) { if ($u === 'BONUS') $o['natureza'] = 'BONUS'; }
            elseif (preg_match('/^(ENTREGA|RESERVA|AGUARD|SE ENTREGAR|APROVA)/u', $u)) $o['cond'] = $o['cond'] ?? $s;
            elseif (preg_match('/\d/', $s) || preg_match('/(APARTAMENTO|GEMINADO|CASA|SOBRADO|TERRENO|LOTE|SALA|UNIDADE|RUA|AV\.|AVENIDA)/u', $u)) $o['imovel'] = $o['imovel'] ?? $s;
        }
        return $o;
    }

    public static function semAcento(string $s): string
    {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s); return $t === false ? $s : preg_replace('/[^A-Za-z0-9 \-\/]/', '', $t);
    }

    /** procura a pessoa do dicionário (cf_pessoas) pelo CNPJ/CPF do fornecedor */
    public static function pessoaPorDoc(array $pessoas, string $doc): ?array
    {
        $d = preg_replace('/\D+/', '', $doc); if ($d === '') return null;
        foreach ($pessoas as $p) { if (preg_replace('/\D+/', '', (string)$p['cnpj']) === $d || preg_replace('/\D+/', '', (string)$p['cpf']) === $d) return $p; }
        return null;
    }

    /** "pessoa" para o recibo quando não está no dicionário: usa o fornecedor do Omie */
    public static function pessoaDoFornecedor(array $t): array
    {
        $d = $t['doc_digitos']; $razao = trim((string)$t['razao']) ?: trim((string)$t['fantasia']);
        $nome = $t['obs']['favorecido'] ?: ($razao ?: 'FORNECEDOR');
        return ['id' => null, 'nome' => $nome, 'razao_social' => $razao, 'cnpj' => strlen($d) === 14 ? $t['doc'] : '', 'cpf' => strlen($d) === 11 ? $t['doc'] : '', 'pagar_por' => strlen($d) === 11 ? 'CPF' : 'CNPJ'];
    }

    /** linha no formato que Recibo::gerar espera (mesmo modelo dos recibos da capa) */
    public static function linhaRecibo(array $t, string $numero, array $pessoa): array
    {
        $o = $t['obs'];
        return ['id' => $t['fingerprint'], 'codigo_integracao' => $numero, 'valor' => $t['valor'], 'natureza' => $o['natureza'], 'funcao' => $o['funcao'] ?: self::funcaoDaCategoria((string)$t['categoria']),
            'cf_raw' => $pessoa['nome'], 'capa_cliente' => $o['cliente'] ?? '', 'capa_construtora' => $o['construtora'] ?? '', 'unidade' => $o['imovel'] ?? '', 'capa_unidade' => '',
            'status_imovel' => $o['status'] ?? '', 'capa_data_venda' => $o['venda'] ? OmieApi::iso($o['venda']) : null, 'capa_cod' => $o['cod'] ?? '', 'data_prevista' => $t['previsao'] ?: $t['vencimento']];
    }

    public static function funcaoDaCategoria(string $cat): string
    {
        $u = mb_strtoupper(self::semAcento($cat));
        foreach (['CORRETOR', 'CAPTADOR', 'DIRETOR', 'GERENTE', 'PRE-VENDA', 'PRE VENDA'] as $f) if (str_contains($u, $f)) return $f === 'GERENTE' ? 'COORDENADOR' : $f;
        if (str_contains($u, 'BONIFICA')) return 'BONUS';
        return '';
    }
}
