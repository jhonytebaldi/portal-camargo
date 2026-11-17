<?php
/* =====================================================================
   alterador-omie/lib/AoTitulo.php — título do Omie num formato único.

   O Omie devolve o mesmo título com nomes diferentes conforme o método:
   ListarContas… e ConsultarConta… (snake_case) e PesquisarLancamentos
   (húngaro, cabecTitulo). Tudo aqui vira o mesmo array "normalizado":

     tipo P|R · id · ci (cód. integração) · forn · cat · ncat (nº de categorias
     no rateio) · cc · venc · prev · emis · reg · doc · nf · parc · obs · valor
     · status · pago (bool) · deps [[cod, perc]] · proj · forma · fin
     · bol (boleto gerado) · bloq (bloqueado no Omie) · alt (última alteração)

   CAMPOS = o que o Alterador sabe alterar, com o nome no Omie, limites e o
   comportamento descoberto nos testes de 17/11/2026 (Vertical, títulos de teste).
   ===================================================================== */
declare(strict_types=1);

final class AoTitulo
{
    /**
     * campo => [rótulo, tipo (texto|data|cadastro|valor|deps|pix), omie (P), omie (R), max, bloqueado se pago?]
     * "bloqueado se pago": testado em título pago — vencimento, previsão e valor o Omie ACEITA mas IGNORA (responde
     * "alterado com sucesso" e não muda nada); conta corrente/fornecedor mexem na baixa. Os demais mudam normalmente.
     */
    public const CAMPOS = [
        'doc'   => ['Nº do documento', 'texto', 'numero_documento', 'numero_documento', 20, false],
        'nf'    => ['Nota Fiscal', 'texto', 'numero_documento_fiscal', 'numero_documento_fiscal', 20, false],
        'parc'  => ['Parcela', 'texto', 'numero_parcela', 'numero_parcela', 7, false],
        'obs'   => ['Observação', 'texto', 'observacao', 'observacao', 2000, false],
        'prev'  => ['Previsão', 'data', 'data_previsao', 'data_previsao', 0, true],
        'venc'  => ['Vencimento', 'data', 'data_vencimento', 'data_vencimento', 0, true],
        'emis'  => ['Emissão', 'data', 'data_emissao', 'data_emissao', 0, false],
        'reg'   => ['Registro', 'data', 'data_entrada', 'data_registro', 0, false],
        'cat'   => ['Categoria', 'cadastro', 'codigo_categoria', 'codigo_categoria', 0, false],
        'cc'    => ['Conta corrente', 'cadastro', 'id_conta_corrente', 'id_conta_corrente', 0, true],
        'forn'  => ['Fornecedor/Cliente', 'cadastro', 'codigo_cliente_fornecedor', 'codigo_cliente_fornecedor', 0, true],
        'proj'  => ['Projeto', 'cadastro', 'codigo_projeto', 'codigo_projeto', 0, false],
        'deps'  => ['Departamentos', 'deps', 'distribuicao', 'distribuicao', 0, false],
        'valor' => ['Valor', 'valor', 'valor_documento', 'valor_documento', 0, true],
        'pix'   => ['Forma de pagamento (Pix)', 'pix', 'cnab_integracao_bancaria', '', 0, true],
    ];

    public static function rotulo(string $campo, string $tipo = 'P'): string
    {
        if ($campo === 'forn') return $tipo === 'R' ? 'Cliente' : 'Fornecedor';
        return self::CAMPOS[$campo][0] ?? $campo;
    }
    public static function omieCampo(string $campo, string $tipo): string { return (string)(self::CAMPOS[$campo][$tipo === 'R' ? 3 : 2] ?? ''); }

    /** título pago/recebido (inteiro ou parcial) */
    public static function pago(array $t): bool
    {
        if ((bool)preg_match('/PAG|RECEB|LIQUID/i', (string)($t['status'] ?? ''))) return true;
        return ($t['_fonte'] ?? '') === 'pesquisa' && !empty($t['pago']);   // só a Pesquisa traz valor pago confiável
    }
    public static function cancelado(array $t): bool { return (bool)preg_match('/CANCEL/i', (string)($t['status'] ?? '')); }

    private static function deps(array $lista, string $kc, string $kp): array
    {
        $o = [];
        foreach ($lista as $d) if (isset($d[$kc]) && (string)$d[$kc] !== '') $o[] = [(string)$d[$kc], round((float)($d[$kp] ?? 0), 4)];
        return $o;
    }
    /** baixa total ou parcial — pela SITUAÇÃO. O valor_pag do Listar/Consultar NÃO é "valor já pago" (vem igual ao valor do
     *  documento em muitos títulos em aberto); só a Pesquisa traz o valor pago de verdade (resumo.nValPago). */
    private static function statusPago(string $st, float $pago): bool
    {
        return $pago > 0.004 || (bool)preg_match('/PAG|RECEB|LIQUID/i', $st);
    }

    /** de ListarContas… / ConsultarConta… */
    public static function deCadastro(array $x, string $tipo): array
    {
        $st = (string)($x['status_titulo'] ?? '');
        $pago = 0.0;
        $cnab = $x['cnab_integracao_bancaria'] ?? [];
        $info = $x['info'] ?? [];
        return [
            'tipo' => $tipo, 'id' => (int)($x['codigo_lancamento_omie'] ?? 0), 'ci' => (string)($x['codigo_lancamento_integracao'] ?? ''),
            'forn' => (int)($x['codigo_cliente_fornecedor'] ?? 0), 'cat' => (string)($x['codigo_categoria'] ?? ''), 'ncat' => max(1, count($x['categorias'] ?? [])),
            'cc' => (int)($x['id_conta_corrente'] ?? 0), 'venc' => (string)($x['data_vencimento'] ?? ''), 'prev' => (string)($x['data_previsao'] ?? ''),
            'emis' => (string)($x['data_emissao'] ?? ''), 'reg' => (string)($x[$tipo === 'R' ? 'data_registro' : 'data_entrada'] ?? ''),
            'doc' => (string)($x['numero_documento'] ?? ''), 'nf' => (string)($x['numero_documento_fiscal'] ?? ''), 'parc' => (string)($x['numero_parcela'] ?? ''),
            'obs' => (string)($x['observacao'] ?? ''), 'valor' => round((float)($x['valor_documento'] ?? 0), 2), 'status' => $st,
            'pago' => self::statusPago($st, $pago), 'deps' => self::deps($x['distribuicao'] ?? [], 'cCodDep', 'nPerDep'),
            'proj' => (int)($x['codigo_projeto'] ?? 0), 'forma' => (string)($cnab['codigo_forma_pagamento'] ?? ''), 'fin' => (string)($cnab['finalidade_transferencia'] ?? ''),
            'bol' => ($x['boleto']['cGerado'] ?? 'N') === 'S', 'bloq' => ($x['bloqueado'] ?? 'N') === 'S',
            'alt' => trim((string)($info['dAlt'] ?? $info['dInc'] ?? '') . ' ' . (string)($info['hAlt'] ?? $info['hInc'] ?? '')),
            '_fonte' => 'cadastro',
        ];
    }

    /** de PesquisarLancamentos (titulosEncontrados[]) — não traz forma de pagamento nem boleto */
    public static function dePesquisa(array $x): array
    {
        $c = $x['cabecTitulo'] ?? $x;
        $st = (string)($c['cStatus'] ?? '');
        $pago = (float)($x['resumo']['nValPago'] ?? 0);
        return [
            'tipo' => (string)($c['cNatureza'] ?? 'P'), 'id' => (int)($c['nCodTitulo'] ?? 0), 'ci' => (string)($c['cCodIntTitulo'] ?? ''),
            'forn' => (int)($c['nCodCliente'] ?? 0), 'cat' => (string)($c['cCodCateg'] ?? ''), 'ncat' => max(1, count($c['aCodCateg'] ?? [])),
            'cc' => (int)($c['nCodCC'] ?? 0), 'venc' => (string)($c['dDtVenc'] ?? ''), 'prev' => (string)($c['dDtPrevisao'] ?? ''),
            'emis' => (string)($c['dDtEmissao'] ?? ''), 'reg' => (string)($c['dDtRegistro'] ?? ''),
            'doc' => (string)($c['cNumTitulo'] ?? ''), 'nf' => (string)($c['cNumDocFiscal'] ?? ''), 'parc' => (string)($c['cNumParcela'] ?? ''),
            'obs' => (string)($c['observacao'] ?? ''), 'valor' => round((float)($c['nValorTitulo'] ?? 0), 2), 'status' => $st,
            'pago' => self::statusPago($st, $pago), 'deps' => self::deps($x['departamentos'] ?? [], 'cCodDepartamento', 'nDistrPercentual'),
            'proj' => (int)($c['cCodProjeto'] ?? 0), 'forma' => null, 'fin' => null, 'bol' => null, 'bloq' => null, 'alt' => '',
            '_fonte' => 'pesquisa',
        ];
    }

    /** valor de um campo no título normalizado (para comparar) */
    public static function valor(array $t, string $campo): mixed
    {
        if ($campo === 'pix') return ($t['forma'] ?? '') . '/' . ($t['fin'] ?? '');
        return $t[$campo] ?? null;
    }

    /** a fonte consegue mostrar este campo? (PesquisarLancamentos não traz forma de pagamento) */
    public static function fonteMostra(array $t, string $campo): bool
    {
        return !($campo === 'pix' && ($t['_fonte'] ?? '') === 'pesquisa');
    }

    public static function igual(string $campo, mixed $a, mixed $b): bool
    {
        if ($campo === 'deps') {
            $n = fn($l) => array_map(fn($d) => [(string)$d[0], round((float)$d[1], 2)], (array)$l);
            $x = $n($a); $y = $n($b); usort($x, fn($p, $q) => strcmp($p[0], $q[0])); usort($y, fn($p, $q) => strcmp($p[0], $q[0]));
            return $x === $y;
        }
        if ($campo === 'valor') return abs((float)$a - (float)$b) < 0.005;
        if (in_array($campo, ['cc', 'forn', 'proj'], true)) return (int)$a === (int)$b;
        return trim((string)$a) === trim((string)$b);
    }

    /** um valor (do formato normalizado) para o pedido do Alterar */
    public static function paraOmie(string $campo, mixed $v, string $tipo, array $extra = []): array
    {
        $k = self::omieCampo($campo, $tipo);
        return match ($campo) {
            'deps' => [$k => array_map(fn($d) => ['cCodDep' => (string)$d[0], 'nPerDep' => (float)$d[1]], (array)$v)],
            'cc', 'forn', 'proj' => [$k => (int)$v],
            'valor' => [$k => round((float)$v, 2)],
            'pix' => [$k => $extra['cnab'] ?? []],
            default => [$k => (string)$v],
        };
    }

    /** exibição curta de um valor (para prévia, histórico e mensagens) */
    public static function mostrar(string $campo, mixed $v, array $nomes = []): string
    {
        if ($v === null) return '—';
        return match ($campo) {
            'valor' => 'R$ ' . number_format((float)$v, 2, ',', '.'),
            'deps' => $v ? implode(' + ', array_map(fn($d) => ($nomes['deps'][(string)$d[0]] ?? $d[0]) . ' ' . rtrim(rtrim(number_format((float)$d[1], 2, ',', ''), '0'), ',') . '%', (array)$v)) : '(nenhum)',
            'cat' => $v === '' ? '—' : $v . (isset($nomes['cat'][(string)$v]) ? ' ' . $nomes['cat'][(string)$v] : ''),
            'cc' => (string)($nomes['cc'][(string)$v] ?? ($v ?: '—')),
            'forn' => (string)($nomes['forn'][(string)$v] ?? ($v ?: '—')),
            'proj' => $v ? (string)($nomes['proj'][(string)$v] ?? $v) : '—',
            'obs' => (string)$v === '' ? '(vazio)' : (mb_strlen((string)$v) > 140 ? '…' . mb_substr((string)$v, -140) : (string)$v),   // só o fim: a linha nova entra no fim
            'pix' => (string)$v === 'TRA/01.3' ? 'Transferência por chave PIX' : ((string)$v === '/' ? '—' : (string)$v),
            default => (string)$v === '' ? '(vazio)' : (string)$v,
        };
    }
}
