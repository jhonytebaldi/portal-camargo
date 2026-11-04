<?php
/* =====================================================================
   capa-financeira/vendas/lib/CapaXlsx.php — monta a planilha da capa a
   partir da venda gravada no portal (mesmo layout do MODELO da equipe).

   Diferenças propositais em relação ao modelo manual (correções):
     - linhas de compradores dinâmicas (1..n), com NASCIMENTO e E-MAIL;
     - fórmulas geradas com as referências certas (nada de SUM de 1 linha);
     - valores digitados com fundo amarelo claro, fórmulas sem fundo;
     - descrição padrão e "última edição" gravadas na própria planilha;
     - rótulos que o leitor de capas do portal reconhece.
   Blocos das etapas 2–4 entram aqui conforme forem construídos.
   ===================================================================== */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/XlsxWriter.php';
require_once __DIR__ . '/Venda.php';
require_once __DIR__ . '/Fluxo.php';

final class CapaXlsx
{
    private const ENT = ['fill' => 'FFF8E1', 'border' => true];   // célula digitada
    private const LAB = ['bold' => true];

    /** @param array $venda cv_carregar() · $pessoas nomes para a aba PESSOAS · $empresaNome · $ultima "dd/mm/aaaa hh:mm por Fulano" */
    /** @return array{receber: array<int,int>} linha da planilha → posição da parcela (ordem por data) */
    public static function gerar(array $venda, array $pessoas, string $empresaNome, string $ultima, string $destino, array $opts = []): array
    {
        $x = new XlsxWriter();
        $s = $x->planilha('MODELO');
        foreach (['A' => 46, 'B' => 44, 'C' => 14, 'D' => 20, 'E' => 30, 'F' => 18, 'G' => 18] as $c => $w) $x->largura($s, $c, $w);
        $brl = ['fmt' => 'brl'];
        $comp = $venda['compradores'];
        $calc = Venda::calc($venda);

        // ---- compradores ----
        $x->set($s, 'A1', 'CAPA DA VENDA #' . $venda['id'], ['bold' => true, 'size' => 12]);
        foreach (['B1' => 'NOME', 'C1' => 'NASCIMENTO', 'D1' => 'CPF/CNPJ', 'E1' => 'E-MAIL'] as $r => $t) $x->set($s, $r, $t, ['bold' => true, 'fill' => 'E7E6E6', 'border' => true]);
        $r = 2;
        foreach ($comp ?: [['nome' => '', 'documento' => '', 'email' => '', 'nascimento' => null]] as $i => $c) {
            $x->set($s, "A$r", 'COMPRADOR ' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT), self::LAB);
            $x->set($s, "B$r", $c['nome'], self::ENT);
            $x->set($s, "C$r", $c['nascimento'] ? ['d' => $c['nascimento']] : null, self::ENT + ['fmt' => 'data']);
            $x->set($s, "D$r", $c['documento'] ? Venda::docFormatado($c['documento']) : '', self::ENT);
            $x->set($s, "E$r", $c['email'], self::ENT);
            $r++;
        }
        // ---- imóvel ----
        $unEnd = trim($venda['endereco'] . ($venda['unidade'] !== '' ? ' - ' . Venda::unidadeFormatada($venda['unidade']) : ''), ' -');
        $linhas = [['CONSTRUTORA', $venda['construtora'], 'DATA DA VENDA', $venda['data_venda'] ? ['d' => $venda['data_venda']] : null, ['fmt' => 'data']],
                   ['BAIRRO', $venda['bairro'], 'EMPRESA', $empresaNome, []],
                   ['UNIDADE/ENDEREÇO', $unEnd, 'SITUAÇÃO', (string)$venda['status_imovel'], []],
                   ['COD. IMÓVEL', $venda['cod_imovel'] !== '' ? (int)$venda['cod_imovel'] : null, 'ÚLTIMA EDIÇÃO', $ultima, ['wrap' => true]]];
        foreach ($linhas as [$la, $va, $lf, $vf, $ef]) {
            $x->set($s, "A$r", $la, self::LAB); $x->set($s, "B$r", $va, self::ENT);
            $x->set($s, "F$r", $lf, self::LAB); $x->set($s, "G$r", $vf, ($lf === 'ÚLTIMA EDIÇÃO' ? [] : self::ENT) + $ef);
            $r++;
        }
        $r++;
        // ---- valores (linhas 9–27 do modelo) ----
        $R = [];   // nome lógico → linha
        $put = function (string $k, string $rot, mixed $b, array $eb = [], mixed $c = null, array $ec = [], ?string $d = null) use ($x, $s, &$r, &$R) {
            $R[$k] = $r;
            $x->set($s, "A$r", $rot, self::LAB);
            $x->set($s, "B$r", $b, $eb);
            if ($c !== null) $x->set($s, "C$r", $c, $ec);
            if ($d !== null) $x->set($s, "D$r", $d, ['italic' => true, 'color' => '6B7770']);
            $r++;
        };
        $num = fn($v) => $v === null ? 0.0 : (float)$v;
        $put('contrato', 'VALOR CONTRATO', $num($venda['valor_contrato']), self::ENT + $brl);
        $put('bonus_in', 'BÔNUS', $num($venda['valor_bonus']), self::ENT + $brl);
        $put('doc', 'DOCUMENTAÇÃO', $num($venda['documentacao']), self::ENT + $brl);
        $put('desc', 'DESCONTOS ADICIONAIS', $num($venda['descontos']), self::ENT + $brl);
        $b = function (string $k) use (&$R): string { return 'B' . $R[$k]; };    // por referência: $R cresce a cada linha
        $cc = function (string $k) use (&$R): string { return 'C' . $R[$k]; };
        $put('vgv', 'VALOR PARA CALCULO DA COMISSÃO DA IMOB e VGV', ['f' => "{$b('contrato')}-{$b('bonus_in')}-{$b('doc')}-{$b('desc')}", 'v' => $calc['valor_calculo']], $brl + ['bold' => true]);
        $R['base'] = $r;
        $put('base', 'COMISSÃO BASE CORRETORES', ['f' => "{$b('vgv')}*C{$r}", 'v' => $calc['comissao_base']], $brl, (float)$venda['pct_corretores'], self::ENT + ['fmt' => 'pct']);
        $R['fundo'] = $r;
        $put('fundo', 'COMISSÃO IMOB FUNDO DE MKT', ['f' => "{$b('vgv')}*C{$r}", 'v' => $calc['fundo']], $brl, (float)$venda['pct_fundo'], self::ENT + ['fmt' => 'pct']);
        $R['total'] = $r;
        $put('total', 'COMISSÃO TOTAL', ['f' => "{$b('vgv')}*C{$r}", 'v' => $calc['comissao_total']], $brl + ['bold' => true], ['f' => "{$cc('fundo')}+{$cc('base')}", 'v' => (float)$venda['pct_fundo'] + (float)$venda['pct_corretores']], ['fmt' => 'pct']);
        $R['nf'] = $r;
        $put('nf', 'DESCONTO NOTA FISCAL', ['f' => "C{$r}*{$b('base')}", 'v' => $calc['desconto_nf']], $brl, (float)$venda['pct_nf'], self::ENT + ['fmt' => 'pct']);
        $linhaTotalFinal = $r + 9;   // COMISSÃO + BÔNUS (linha calculada abaixo) — para os % de brinde/cesta
        $put('brinde', 'DESCONTO BRINDE', (float)$venda['brinde'], self::ENT + $brl, ['f' => "B{$r}/B{$linhaTotalFinal}", 'v' => $calc['pct_brinde']], ['fmt' => 'pct']);
        $put('cesta', 'DESCONTO CESTA BÁSICA', (float)$venda['cesta'], self::ENT + $brl, ['f' => "B{$r}/B{$linhaTotalFinal}", 'v' => $calc['pct_cesta']], ['fmt' => 'pct']);
        $r++;
        $put('final', 'VALOR FINAL PARA CÁLCULO DAS COMISSÕES', ['f' => "{$b('base')}-{$b('nf')}-{$b('cesta')}-{$b('brinde')}", 'v' => $calc['valor_final']], $brl + ['bold' => true, 'fill' => 'E3EDE8']);
        $r++;
        $put('bonus', 'VALOR BONUS', ['f' => $b('bonus_in'), 'v' => $calc['bonus']], $brl);
        $R['nfb'] = $r;
        $put('nfb', 'DESCONTO NF BONUS', ['f' => "B" . ($r - 1) . "*C{$r}", 'v' => $calc['desconto_nf_bonus']], $brl, (float)$venda['pct_nf_bonus'], self::ENT + ['fmt' => 'pct']);
        $put('bdist', 'VALOR BONUS PARA DISTRIBUIÇÃO', ['f' => "{$b('bonus')}-{$b('nfb')}", 'v' => $calc['bonus_distribuicao']], $brl + ['bold' => true, 'fill' => 'E3EDE8']);
        $r++;
        if ($r !== $linhaTotalFinal) throw new LogicException('layout da capa desalinhado');
        $put('cb', 'COMISSÃO + BÔNUS', ['f' => "{$b('total')}+{$b('bonus')}", 'v' => $calc['comissao_mais_bonus']], $brl + ['bold' => true, 'fill' => 'E3EDE8']);
        $r++;

        // ---- descrição padrão + status das etapas ----
        $x->set($s, "A$r", 'DESCRIÇÃO PADRÃO (contas a receber)', self::LAB);
        $x->set($s, "B$r", Venda::descricao($venda, $comp), ['wrap' => true, 'valign' => 'top']);
        $x->mesclar($s, "B$r:G$r"); $x->altura($s, $r, 34); $r++;
        $x->set($s, "A$r", 'MODELO DE DESCRIÇÃO (contas a pagar)', self::LAB);
        $x->set($s, "B$r", Venda::descricaoPagar('NOME', 'CARGO', 'COMISSAO', Venda::descricao($venda, $comp)), ['wrap' => true, 'valign' => 'top', 'italic' => true]);
        $x->mesclar($s, "B$r:G$r"); $x->altura($s, $r, 34); $r += 2;

        $st = ['rascunho' => 'em preenchimento', 'concluida' => 'concluída', 'conferida' => 'conferida'];
        if (!empty($venda['e2']['linhas'])) {
            $r = self::blocoDistribuicao($x, $s, $r, $venda, $R, $calc, $empresaNome);
        }
        $temFluxo = !empty($venda['e3']['parcelas']);
        if ($temFluxo) $r = self::blocoFluxo($x, $s, $r, $venda, $calc, $R);
        // etapa 4: pagamentos (no arquivo para importar, só os LIBERADOS — o leitor de capas cobra condição de linha do bloco sem lançamento)
        $pag = $opts['pagamentos'] ?? null;
        $pagLinhas = $pag ? array_values(array_filter($pag['pagamentos'], fn($p) => empty($opts['para_importar']) || $p['liberado'])) : [];
        $temPag = (bool)($pag['pagamentos'] ?? []);
        if ($temPag) $r = self::blocoPagamentos($x, $s, $r, $pag, $pagLinhas, !empty($opts['para_importar']));
        // (rótulos dos marcadores não podem começar com "FLUXO DE PAGAMENTO"/"PAGAMENTO PARA CORRETORES": o leitor de capas procura esses blocos)
        foreach (array_filter([2 => empty($venda['e2']['linhas']) ? 'ETAPA 2 — DISTRIBUIÇÃO DAS COMISSÕES' : null, 3 => $temFluxo ? null : 'ETAPA 3 — FLUXO DO CLIENTE E RECEBIDOS', 4 => $temPag ? null : 'ETAPA 4 — PAGAMENTOS AOS CORRETORES']) as $n => $rot) {
            $x->set($s, "A$r", $rot, ['bold' => true, 'fill' => 'E7E6E6']);
            $x->set($s, "B$r", 'ainda não preenchida no portal (' . ($st[$venda["e{$n}_status"]] ?? $venda["e{$n}_status"]) . ')', ['italic' => true, 'color' => '6B7770']);
            $x->mesclar($s, "B$r:G$r");
            $r += 2;
        }

        // ---- PESSOAS (lista para validação) ----
        $p = $x->planilha('PESSOAS');
        $x->largura($p, 'A', 40);
        foreach (array_values($pessoas) as $i => $nome) $x->set($p, 'A' . ($i + 1), $nome);
        // ---- lançamentos (o que vai para o Omie) — mesmo layout que o leitor de capas lê ----
        $mapa = ['receber' => [], 'pagar' => []];
        $liberados = $pag ? array_values(array_filter($pag['pagamentos'], fn($p) => $p['liberado'] && $p['liberado']['data'])) : [];
        foreach ($liberados as &$lp) { [$lp['valor'], $lp['data']] = self::valorData($lp); $lp['condicao'] = (string)($lp['liberado']['condicao'] ?? $lp['condicao']); }
        unset($lp);
        usort($liberados, fn($a, $b) => [$a['data'], $a['natureza'], $a['cargo'], $a['nome']] <=> [$b['data'], $b['natureza'], $b['cargo'], $b['nome']]);
        if ($temFluxo) {
            $comp = $venda['compradores'];
            $desc = Venda::descricao($venda, $comp);
            $ordem = Fluxo::ordenadas($venda['e3']['parcelas']);
            $x->set($s, "A$r", 'MODELO DE DESCRIÇÃO', ['bold' => true]);
            $x->set($s, "B$r", Venda::descricaoPagar('NOME', 'CARGO', 'COMISSAO', $desc), ['italic' => true]);
            $r++;
            $hdr = $r + 1; $ini = $hdr + 1; $fim = $ini + count($ordem) + count($liberados) - 1;
            $tot = array_sum(array_map(fn($p) => (float)$p['valor'], $ordem));
            $totP = array_sum(array_map(fn($p) => (float)$p['valor'], $liberados));
            $x->set($s, "E$r", ['f' => "SUM(E$ini:E$fim)", 'v' => $tot], ['fmt' => 'brl', 'bold' => true]);
            $x->set($s, "F$r", ['f' => "SUM(F$ini:F$fim)", 'v' => $totP], ['fmt' => 'brl', 'bold' => true]);
            foreach (['A' => 'DATA', 'B' => 'CONTA', 'C' => 'Cliente e fornecedor', 'D' => 'histórico', 'E' => 'A RECEBER ', 'F' => 'A PAGAR '] as $c => $t) $x->set($s, "$c$hdr", $t, ['bold' => true, 'fill' => 'E7E6E6', 'border' => true]);
            $r = $ini;
            foreach ($ordem as $j => $p) {
                $pg = Fluxo::pagador($p, $comp);
                $x->set($s, "A$r", $p['data'] ? ['d' => $p['data']] : null, ['fmt' => 'data']);
                $x->set($s, "B$r", $p['conta']);
                $x->set($s, "C$r", $pg['nome']);
                $x->set($s, "D$r", $desc);
                $x->set($s, "E$r", round((float)$p['valor'], 2), ['fmt' => 'brl']);
                $mapa['receber'][$r] = $j;
                $r++;
            }
            foreach ($liberados as $p) {   // contas a pagar: só pagamentos liberados pelo financeiro
                $x->set($s, "A$r", ['d' => $p['data']], ['fmt' => 'data']);
                $x->set($s, "C$r", $p['nome']);
                $x->set($s, "D$r", Venda::descricaoPagar($p['nome'], $p['cargo'], $p['natureza'], $desc));
                $x->set($s, "F$r", round((float)$p['valor'], 2), ['fmt' => 'brl']);
                if ($p['condicao'] !== '') $x->set($s, "G$r", $p['condicao']);
                $mapa['pagar'][$r] = $p['chave'];
                $r++;
            }
        }
        $x->salvar($destino);
        return $mapa;
    }

    /** linhas 86–109 do modelo: PAGAMENTO PARA CORRETORES (cargo, nome, %, valor, data, condição) por etapa */
    private static function blocoPagamentos(XlsxWriter $x, string $s, int $r, array $pag, array $linhas, bool $paraImportar): int
    {
        $hd = ['bold' => true, 'fill' => 'E7E6E6', 'border' => true];
        $x->set($s, "A$r", 'PAGAMENTO PARA CORRETORES', ['bold' => true]);
        $x->mesclar($s, "A$r:E$r"); $r++;
        foreach (['A' => 'CARGO', 'B' => 'NOME', 'C' => '%', 'D' => 'VALOR', 'E' => 'DATA', 'F' => 'CONDIÇÃO', 'G' => 'ETAPA', 'H' => 'SITUAÇÃO'] as $c => $t) $x->set($s, "$c$r", $t, $hd);
        $r++;
        $rot = array_column($pag['etapas'], 'rotulo', 'chave');
        $ini = $r;
        foreach ($linhas as $p) {
            [$valor, $data] = self::valorData($p);
            $x->set($s, "A$r", $p['cargo'], ['bold' => true]);
            $x->set($s, "B$r", $p['nome']);
            if ($p['pct'] > 0) $x->set($s, "C$r", (float)$p['pct'], ['fmt' => 'pct']);
            $x->set($s, "D$r", $valor, ['fmt' => 'brl']);
            $x->set($s, "E$r", $data ? ['d' => $data] : 'data manual', $data ? ['fmt' => 'data'] : ['italic' => true, 'color' => 'B4512F']);
            if ($p['condicao'] !== '' && ($p['liberado'] || !$paraImportar)) $x->set($s, "F$r", $p['condicao']);
            $x->set($s, "G$r", $rot[$p['etapa']] ?? $p['etapa']);
            $x->set($s, "H$r", trim(($p['liberado'] ? 'LIBERADO' : 'aguardando liberação') . ($p['obs'] !== '' ? ' · ' . $p['obs'] : '')), ['italic' => true, 'color' => '6B7770']);
            $r++;
        }
        $x->set($s, "A$r", 'TOTAL', ['bold' => true]);
        $x->set($s, "D$r", $r > $ini ? ['f' => "SUM(D$ini:D" . ($r - 1) . ')', 'v' => array_sum(array_map(fn($p) => self::valorData($p)[0], $linhas))] : 0.0, ['fmt' => 'brl', 'bold' => true]);
        return $r + 2;
    }

    /** pagamento liberado vale o que o financeiro liberou (se o cálculo mudou depois, é preciso liberar de novo) */
    private static function valorData(array $p): array
    {
        return $p['liberado'] ? [round((float)$p['liberado']['valor'], 2), $p['liberado']['data']] : [round((float)$p['valor'], 2), $p['data']];
    }

    /** linhas 58–77 do modelo: fluxo de pagamento do cliente, recebidos e valor ainda devido (etapa 3) */
    private static function blocoFluxo(XlsxWriter $x, string $s, int $r, array $venda, array $c1, array $R): int
    {
        require_once __DIR__ . '/Fluxo.php';
        $d = $venda['e3'];
        $comp = $venda['compradores'];
        $ordem = Fluxo::ordenadas($d['parcelas']);
        $calc = Fluxo::calc($d, $c1);
        $brl = ['fmt' => 'brl']; $hd = ['bold' => true, 'fill' => 'E7E6E6', 'border' => true];
        $x->set($s, "A$r", 'FLUXO DE PAGAMENTO DO CLIENTE', ['bold' => true]);
        $x->mesclar($s, "A$r:E$r"); $r++;
        foreach (['A' => 'DESCRIÇÃO', 'B' => 'VALOR', 'C' => '%', 'D' => '% ACUMULADO', 'E' => 'DATA', 'F' => 'PAGO PARA', 'G' => 'REFERÊNCIA'] as $c => $t) $x->set($s, "$c$r", $t, $hd);
        $r++;
        $ini = $r; $fim = $ini + count($ordem) - 1; $totRow = $fim + 1;
        foreach ($ordem as $j => $p) {
            $pg = Fluxo::pagador($p, $comp);
            $l = $calc['linhas'][$j];
            $x->set($s, "A$r", $pg['nome'], ['fill' => 'FFF8E1', 'border' => true]);
            $x->set($s, "B$r", round((float)$p['valor'], 2), $brl + ['fill' => 'FFF8E1', 'border' => true]);
            $x->set($s, "C$r", ['f' => "B$r/\$B\$$totRow", 'v' => $l['pct']], ['fmt' => 'pct']);
            $x->set($s, "D$r", ['f' => $r === $ini ? "C$r" : "C$r+D" . ($r - 1), 'v' => $l['pct_acum']], ['fmt' => 'pct']);
            $x->set($s, "E$r", $p['data'] ? ['d' => $p['data']] : null, ['fmt' => 'data', 'fill' => 'FFF8E1', 'border' => true]);
            $x->set($s, "F$r", $p['conta'], ['fill' => 'FFF8E1', 'border' => true]);
            $x->set($s, "G$r", $p['ref'], ['fill' => 'FFF8E1', 'border' => true]);   // G = rótulo da parcela (vai para a observação no Omie)
            $marca = array_filter([$p['recebido'] ? 'RECEBIDA' : '', $p['arras'] ? 'ARRAS' : '', $l['gatilhos'] ? 'atinge ' . implode(' / ', $l['gatilhos']) . ' da comissão' : '']);
            if ($marca) $x->set($s, "H$r", implode(' · ', $marca), ['italic' => true, 'color' => '2F5D4F']);
            $r++;
        }
        $x->set($s, "A$r", 'TOTAL', ['bold' => true]);
        $x->set($s, "B$r", ['f' => "SUM(B$ini:B$fim)", 'v' => $calc['total_fluxo']], $brl + ['bold' => true]);
        $x->set($s, "C$r", ['f' => "B$r/B{$R['cb']}", 'v' => $calc['total_esperado'] > 0 ? $calc['total_fluxo'] / $calc['total_esperado'] : 0], ['fmt' => 'pct']);
        $x->set($s, "D$r", 'deve bater com COMISSÃO + BÔNUS', ['italic' => true, 'color' => '6B7770']);
        $r += 2;
        if ($d['travas']) {
            $x->set($s, "A$r", 'TRAVAS', ['bold' => true, 'color' => 'B4512F']);
            $x->set($s, "B$r", implode(' · ', array_map(fn($t) => Fluxo::TRAVAS[$t], $d['travas'])), ['color' => 'B4512F']);
            $x->mesclar($s, "B$r:G$r"); $r += 2;
        }
        // recebidos
        $x->set($s, "A$r", 'COMPROVANTES DE RECEBIMENTO DA IMOB CAMARGO', ['bold' => true]);
        $x->mesclar($s, "A$r:E$r"); $r++;
        foreach (['A' => 'DESCRIÇÃO', 'B' => 'VALOR', 'E' => 'DATA', 'G' => 'REFERÊNCIA'] as $c => $t) $x->set($s, "$c$r", $t, $hd);
        $r++;
        $iniR = $r;
        foreach ($ordem as $p) if ($p['recebido']) {
            $pg = Fluxo::pagador($p, $comp);
            $x->set($s, "A$r", $pg['nome']);
            $x->set($s, "B$r", round((float)$p['valor'], 2), $brl);
            $x->set($s, "E$r", $p['data'] ? ['d' => $p['data']] : null, ['fmt' => 'data']);
            $x->set($s, "G$r", $p['ref']);
            if ($p['arras']) $x->set($s, "H$r", 'ARRAS', ['italic' => true, 'color' => '2F5D4F']);
            $r++;
        }
        $x->set($s, "A$r", 'TOTAL', ['bold' => true]);
        $x->set($s, "B$r", $r > $iniR ? ['f' => "SUM(B$iniR:B" . ($r - 1) . ')', 'v' => $calc['recebido']] : 0.0, $brl + ['bold' => true]);
        $totRec = $r; $r += 2;
        $x->set($s, "A$r", 'VALOR AINDA DEVIDO', ['bold' => true]);
        $x->set($s, "B$r", ['f' => "B$totRow-B$totRec", 'v' => $calc['devido']], $brl + ['bold' => true, 'fill' => 'E3EDE8']);
        $r += 2;
        if (!empty($d['conferido'])) {
            $x->set($s, "A$r", 'CONFERIDO COM CONTRATO E TERMO', ['bold' => true]);
            $x->set($s, "B$r", trim(($d['conferido_por'] ?? '') . ' ' . ($d['conferido_em'] ?? '')));
            $r += 2;
        }
        return $r;
    }

    /** linhas 29–56 do modelo: comissões, bônus, saldo e administrativos (etapa 2). Devolve a próxima linha livre. */
    private static function blocoDistribuicao(XlsxWriter $x, string $s, int $r, array $venda, array $R, array $c1, string $empresaNome): int
    {
        require_once __DIR__ . '/Distribuicao.php';
        $d = $venda['e2'];
        $calc = Distribuicao::calc($d, $c1);
        $brl = ['fmt' => 'brl']; $hd = ['bold' => true, 'fill' => 'E7E6E6', 'border' => true];
        $fin = '$B$' . $R['final']; $bon = '$B$' . $R['bdist']; $cb = '$B$' . $R['cb'];
        $cab = function (string $a) use ($x, $s, &$r, $hd) {
            foreach (['A' => $a, 'B' => 'VALOR', 'E' => 'NOME', 'F' => '%', 'G' => 'Valor'] as $c => $t) $x->set($s, "$c$r", $t, $hd);
            $r++;
        };
        $linha = function (string $rot, array $l, string $base) use ($x, $s, &$r, $brl) {
            $f = $base . '*F' . $r . ($l['desconto'] > 0 ? '-' . self::n($l['desconto']) : '');
            $x->set($s, "A$r", $rot, ['bold' => true]);
            $x->set($s, "B$r", ['f' => "G$r", 'v' => $l['valor']], $brl);
            if ($l['desconto'] > 0) $x->set($s, "D$r", 'descontado ' . Distribuicao::brl($l['desconto']) . ' (conclusão do processo)', ['italic' => true, 'color' => 'B4512F']);
            if (($l['obs'] ?? '') !== '') $x->set($s, "D$r", $l['obs'], ['italic' => true, 'fill' => 'FFF8E1', 'border' => true]);
            $x->set($s, "E$r", mb_strtoupper($l['nome'] !== '' ? $l['nome'] : $l['recebe'] ?? '', 'UTF-8'), ['fill' => 'FFF8E1', 'border' => true]);
            $x->set($s, "F$r", (float)$l['pct'], ['fmt' => 'pct', 'fill' => 'FFF8E1', 'border' => true]);
            $x->set($s, "G$r", ['f' => $f, 'v' => $l['valor']], $brl);
            $r++;
        };
        $porBloco = fn(string $b) => array_values(array_map(fn($l) => $l + ['recebe' => $empresaNome], array_filter($calc['linhas'], fn($l) => $l['bloco'] === $b)));

        // comissões + quem concluiu o processo
        $cab('DESCRIÇÃO');
        $ini = $r;
        foreach ($porBloco('comissao') as $l) $linha($l['cargo'], $l, $fin);
        if (!empty($d['conclusao']['ativo'])) foreach ($d['conclusao']['recebem'] as $rc) {
            $x->set($s, "A$r", $rc['cargo'], ['bold' => true]);
            $x->set($s, "B$r", ['f' => "G$r", 'v' => (float)$rc['valor']], $brl);
            $x->set($s, "D$r", 'conclusão do processo', ['italic' => true, 'color' => '6B7770']);
            $x->set($s, "E$r", mb_strtoupper($rc['nome'], 'UTF-8'), ['fill' => 'FFF8E1', 'border' => true]);
            $x->set($s, "G$r", (float)$rc['valor'], $brl + ['fill' => 'FFF8E1', 'border' => true]);
            $r++;
        }
        $fimCom = $r - 1; $r++;
        // bônus
        $iniB = $r + 1;
        if ($porBloco('bonus')) {
            $cab('BONUS');
            foreach ($porBloco('bonus') as $l) $linha($l['cargo'], $l, $bon);
            $x->set($s, "E$r", 'TOTAL', ['bold' => true]);
            $x->set($s, "F$r", ['f' => "SUM(F$iniB:F" . ($r - 1) . ')', 'v' => $calc['pct']['bonus']], ['fmt' => 'pct', 'bold' => true]);
            $x->set($s, "G$r", ['f' => "SUM(G$iniB:G" . ($r - 1) . ')', 'v' => $calc['bonus']], $brl + ['bold' => true]);
            $fimB = $r - 1; $r += 2;
            $somaB = "+SUM(B$iniB:B$fimB)";
        } else $somaB = '';
        // totais
        $x->set($s, "A$r", 'COMISSÕES PAGAS + BÔNUS', ['bold' => true]);
        $x->set($s, "B$r", ['f' => "SUM(B$ini:B$fimCom)$somaB", 'v' => $calc['pagos']], $brl + ['bold' => true, 'fill' => 'E3EDE8']);
        $x->set($s, "C$r", ['f' => "B$r/$cb", 'v' => $calc['pct_pagos']], ['fmt' => 'pct']);
        $rp = $r; $r += 2;
        $x->set($s, "A$r", 'SALDO PRA IMOBILIÁRIA', ['bold' => true]);
        $x->set($s, "B$r", ['f' => "$cb-B$rp", 'v' => $calc['saldo']], $brl + ['bold' => true, 'fill' => 'E3EDE8']);
        $x->set($s, "C$r", ['f' => "B$r/$cb", 'v' => $calc['pct_saldo']], ['fmt' => 'pct']);
        $r += 2;
        // administrativos
        $adm = array_merge($porBloco('adm'), $porBloco('adm_bonus'));
        if ($adm) {
            $x->set($s, "A$r", 'RETENÇÕES PARA IMOBILIÁRIA — FICAM COM A EMPRESA DA VENDA (' . $empresaNome . '); NÃO SÃO CONTAS A PAGAR', ['bold' => true, 'wrap' => true]);
            $x->mesclar($s, "A$r:G$r"); $x->altura($s, $r, 30); $r++;
            $cab('DESCRIÇÃO'); $x->set($s, 'D' . ($r - 1), 'OBSERVAÇÃO', ['bold' => true, 'fill' => 'E7E6E6', 'border' => true]);
            $iniA = $r;
            foreach ($porBloco('adm') as $l) $linha($l['cargo'], $l, $fin);
            foreach ($porBloco('adm_bonus') as $l) $linha('BONUS - ' . $l['cargo'] . ' IMOB', $l, $bon);
            $x->set($s, "A$r", 'TOTAL', ['bold' => true]);
            $x->set($s, "B$r", ['f' => "SUM(B$iniA:B" . ($r - 1) . ')', 'v' => $calc['adm']], $brl + ['bold' => true]);
            $x->set($s, "C$r", ['f' => "B$r/$cb", 'v' => $calc['pct_adm']], ['fmt' => 'pct']);
            $r += 2;
        }
        if (!empty($d['conclusao']['ativo'])) {
            $desc = array_map(fn($y) => str_replace('|', ' ', $y['ref']) . ' ' . Distribuicao::brl((float)$y['valor']), $d['conclusao']['descontos']);
            $x->set($s, "A$r", 'CONCLUSÃO DO PROCESSO', ['bold' => true]);
            $x->set($s, "B$r", Distribuicao::brl((float)$d['conclusao']['valor']) . ' — ' . $d['conclusao']['descricao'] . ' · descontado de: ' . implode('; ', $desc), ['wrap' => true, 'valign' => 'top']);
            $x->mesclar($s, "B$r:G$r"); $x->altura($s, $r, 30); $r += 2;
        }
        return $r;
    }

    private static function n(float $f): string { return rtrim(rtrim(sprintf('%.4F', $f), '0'), '.'); }
}
