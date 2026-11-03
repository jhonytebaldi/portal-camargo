<?php
/* =====================================================================
   capa-financeira/vendas/lib/Distribuicao.php — etapa 2: distribuição
   das comissões e do bônus (linhas 29–56 da capa) + "Conclusão do
   processo" (quem terminou o processo no lugar do corretor recebe um
   valor que é descontado dos corretores envolvidos).

   Blocos (base de cálculo):
     comissao   → VALOR FINAL PARA CÁLCULO DAS COMISSÕES (etapa 1)
     bonus      → VALOR BÔNUS PARA DISTRIBUIÇÃO
     adm        → administrativos pós-processo, pagos com a margem da
                  imobiliária, sobre o valor final (ex.: integração QLV, pré-venda)
     adm_bonus  → administrativo sobre o bônus (ex.: bônus integração imob.)
   O bônus (bonus + adm_bonus) tem que fechar 100%.

   Guardado em cv_vendas.e2_dados (JSON):
     {linhas: [{bloco, cargo, pessoa_id, nome, pct}],
      conclusao: {ativo, valor, descricao, recebem: [{pessoa_id, nome, cargo, valor}],
                  descontos: [{ref: "CARGO|NOME", valor}]}}
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/Venda.php';

final class Distribuicao
{
    public const CARGOS = ['CORRETOR', 'CAPTADOR', 'INTEGRAÇÃO', 'PRÉ-VENDA', 'COORDENADOR', 'DIRETOR', 'FINANCEIRO', 'SAC', 'ADM'];
    public const BLOCOS = [
        'comissao' => ['Comissões', 'valor_final', 'sobre o valor final para cálculo das comissões'],
        'bonus' => ['Bônus', 'bonus_distribuicao', 'sobre o valor do bônus para distribuição'],
        'adm' => ['Administrativos pós-processo (comissão)', 'valor_final', 'pagos com a margem da imobiliária, sobre o valor final'],
        'adm_bonus' => ['Administrativos pós-processo (bônus)', 'bonus_distribuicao', 'pagos com a margem da imobiliária, sobre o bônus'],
    ];
    /** linhas que aparecem numa distribuição nova (só o cargo; nome e % a preencher) */
    public const MODELO = [
        ['comissao', 'CORRETOR'], ['comissao', 'CAPTADOR'], ['comissao', 'COORDENADOR'], ['comissao', 'INTEGRAÇÃO'], ['comissao', 'DIRETOR'], ['comissao', 'DIRETOR'],
        ['bonus', 'CORRETOR'], ['bonus', 'CAPTADOR'], ['bonus', 'COORDENADOR'], ['bonus', 'DIRETOR'],
        ['adm', 'INTEGRAÇÃO'], ['adm', 'PRÉ-VENDA'], ['adm_bonus', 'INTEGRAÇÃO'],
    ];
    /** sugestão de quem conclui o processo (editável na tela) */
    public const CONCLUSAO_PADRAO = ['valor' => 200.0, 'recebem' => [['nome' => 'Tainara Fagundes de Oliveira', 'cargo' => 'FINANCEIRO', 'valor' => 100.0], ['nome' => 'Cristina Rodrigues', 'cargo' => 'SAC', 'valor' => 100.0]]];

    public static function vazia(): array
    {
        return ['linhas' => array_map(fn($m) => ['bloco' => $m[0], 'cargo' => $m[1], 'pessoa_id' => null, 'nome' => '', 'pct' => null], self::MODELO),
                'conclusao' => ['ativo' => false, 'valor' => self::CONCLUSAO_PADRAO['valor'], 'descricao' => '', 'recebem' => [], 'descontos' => []]];
    }

    public static function ref(string $cargo, string $nome): string { return Venda::texto($cargo) . '|' . Venda::texto($nome); }

    /**
     * Lê a tela. $resolver(nome) → [pessoa_id, nome do cadastro] ou null quando a pessoa não está cadastrada.
     * @return array{0: array, 1: string[]} [dados, erros de formato]
     */
    public static function doFormulario(array $in, callable $resolver): array
    {
        $fmt = []; $linhas = [];
        foreach ((array)($in['linhas'] ?? []) as $i => $l) {
            $bloco = isset(self::BLOCOS[$l['bloco'] ?? '']) ? $l['bloco'] : 'comissao';
            $cargo = Venda::texto($l['cargo'] ?? '');
            $nome = Venda::texto($l['nome'] ?? '', false);
            $pct = Venda::percentual($l['pct'] ?? null);
            if ($pct === false) { $fmt[] = "Linha " . ($i + 1) . ": percentual ilegível."; $pct = null; }
            if ($nome === '' && $pct === null) continue;     // linha do modelo não usada
            $r = $nome !== '' ? $resolver($nome) : null;
            $linhas[] = ['bloco' => $bloco, 'cargo' => $cargo, 'pessoa_id' => $r[0] ?? null, 'nome' => $r[1] ?? $nome, 'pct' => $pct] + (isset($l['k']) ? ['k' => (int)$l['k']] : []);
        }
        $c = (array)($in['conclusao'] ?? []);
        $conc = ['ativo' => !empty($c['ativo']), 'valor' => null, 'descricao' => Venda::texto($c['descricao'] ?? '', false), 'recebem' => [], 'descontos' => []];
        $v = Venda::dinheiro($c['valor'] ?? null); if ($v === false) { $fmt[] = 'Conclusão do processo: valor ilegível.'; $v = null; }
        $conc['valor'] = $v;
        foreach ((array)($c['recebem'] ?? []) as $x) {
            $nome = Venda::texto($x['nome'] ?? '', false); $val = Venda::dinheiro($x['valor'] ?? null);
            if ($val === false) { $fmt[] = 'Conclusão do processo: valor ilegível para ' . $nome . '.'; $val = null; }
            if ($nome === '' && $val === null) continue;
            $r = $nome !== '' ? $resolver($nome) : null;
            $conc['recebem'][] = ['pessoa_id' => $r[0] ?? null, 'nome' => $r[1] ?? $nome, 'cargo' => Venda::texto($x['cargo'] ?? ''), 'valor' => $val];
        }
        foreach ((array)($c['descontos'] ?? []) as $x) {
            $val = Venda::dinheiro($x['valor'] ?? null);
            if ($val === false) { $fmt[] = 'Conclusão do processo: desconto ilegível.'; $val = null; }
            $ref = (string)($x['ref'] ?? '');
            if ($ref === '' && $val === null) continue;
            $conc['descontos'][] = ['ref' => $ref, 'valor' => $val];
        }
        if (!$conc['ativo']) { $conc['recebem'] = []; $conc['descontos'] = []; }
        return [['linhas' => $linhas, 'conclusao' => $conc], $fmt];
    }

    /** valores de cada linha + totais. $c1 = Venda::calc() da etapa 1 */
    public static function calc(array $d, array $c1): array
    {
        $desc = [];
        if (!empty($d['conclusao']['ativo'])) foreach ($d['conclusao']['descontos'] as $x) $desc[$x['ref']] = ($desc[$x['ref']] ?? 0) + (float)$x['valor'];
        $linhas = []; $tot = ['comissao' => 0.0, 'bonus' => 0.0, 'adm' => 0.0, 'adm_bonus' => 0.0]; $pct = $tot;
        $usado = [];
        foreach ($d['linhas'] as $l) {
            $base = $c1[self::BLOCOS[$l['bloco']][1]];
            $bruto = $base * (float)$l['pct'];
            $ref = self::ref($l['cargo'], $l['nome']);
            $dsc = 0.0;
            if ($l['bloco'] === 'comissao' && isset($desc[$ref]) && empty($usado[$ref])) { $dsc = $desc[$ref]; $usado[$ref] = true; }
            $linhas[] = $l + ['ref' => $ref, 'base' => $base, 'bruto' => $bruto, 'desconto' => $dsc, 'valor' => $bruto - $dsc];
            $tot[$l['bloco']] += $bruto - $dsc; $pct[$l['bloco']] += (float)$l['pct'];
        }
        $recebem = 0.0;
        if (!empty($d['conclusao']['ativo'])) foreach ($d['conclusao']['recebem'] as $x) $recebem += (float)$x['valor'];
        $pagos = $tot['comissao'] + $recebem + $tot['bonus'];
        $cmb = $c1['comissao_mais_bonus'];
        $saldo = $cmb - $pagos;
        $adm = $tot['adm'] + $tot['adm_bonus'];
        $porPessoa = [];
        foreach ($linhas as $l) if ($l['nome'] !== '') $porPessoa[$l['nome']] = ($porPessoa[$l['nome']] ?? 0) + $l['valor'];
        if (!empty($d['conclusao']['ativo'])) foreach ($d['conclusao']['recebem'] as $x) if ($x['nome'] !== '') $porPessoa[$x['nome']] = ($porPessoa[$x['nome']] ?? 0) + (float)$x['valor'];
        arsort($porPessoa);
        return ['linhas' => $linhas, 'tot' => $tot, 'pct' => $pct, 'recebem' => $recebem,
                'comissoes' => $tot['comissao'] + $recebem, 'bonus' => $tot['bonus'], 'pagos' => $pagos,
                'pct_pagos' => $cmb > 0 ? $pagos / $cmb : 0, 'saldo' => $saldo, 'pct_saldo' => $cmb > 0 ? $saldo / $cmb : 0,
                'adm' => $adm, 'pct_adm' => $cmb > 0 ? $adm / $cmb : 0, 'saldo_final' => $saldo - $adm, 'pct_saldo_final' => $cmb > 0 ? ($saldo - $adm) / $cmb : 0,
                'pct_bonus_total' => $pct['bonus'] + $pct['adm_bonus'], 'por_pessoa' => $porPessoa];
    }

    /** @return array{erros: string[], avisos: string[]} */
    public static function validar(array $d, array $c1): array
    {
        $e = []; $a = [];
        $calc = self::calc($d, $c1);
        $vistos = []; $temCorretor = false; $temBonus = false;
        $rot = fn(array $l) => self::BLOCOS[$l['bloco']][0] . ' · ' . ($l['cargo'] ?: '(sem cargo)') . ($l['nome'] !== '' ? ' · ' . $l['nome'] : '');
        foreach ($d['linhas'] as $l) {
            $q = $rot($l);
            if (!in_array($l['cargo'], self::CARGOS, true)) $e[] = "$q: escolha o cargo da lista.";
            if ($l['nome'] === '') $e[] = "$q: falta o nome.";
            elseif (!$l['pessoa_id']) $e[] = "$q: \"{$l['nome']}\" não está no cadastro de Pessoas — escolha da lista (ou cadastre a pessoa em Pessoas).";
            if ($l['pct'] === null || $l['pct'] <= 0) $e[] = "$q: falta o %.";
            elseif ($l['pct'] > 1) $e[] = "$q: % acima de 100%.";
            $k = $l['bloco'] . '|' . self::ref($l['cargo'], $l['nome']);
            if ($l['nome'] !== '' && isset($vistos[$k])) $e[] = "$q: linha repetida (mesmo bloco, cargo e pessoa).";
            $vistos[$k] = true;
            if ($l['bloco'] === 'comissao' && $l['cargo'] === 'CORRETOR') $temCorretor = true;
            if (in_array($l['bloco'], ['bonus', 'adm_bonus'], true)) $temBonus = true;
        }
        if (!$temCorretor) $e[] = 'Falta o CORRETOR no bloco de comissões.';
        if ($c1['bonus'] > 0) {
            if (abs($calc['pct_bonus_total'] - 1) > 0.00001) $e[] = 'O bônus tem que fechar 100% (bônus + administrativo do bônus) — está em ' . self::pctTxt($calc['pct_bonus_total']) . '.';
        } elseif ($temBonus) $e[] = 'Esta venda não tem bônus (etapa 1) — remova as linhas de bônus.';
        if ($calc['saldo'] < -0.005) $e[] = 'As comissões + bônus passam do que a imobiliária recebe (saldo negativo: ' . self::brl($calc['saldo']) . ').';
        elseif ($calc['saldo_final'] < -0.005) $e[] = 'Com os administrativos pós-processo, o saldo da imobiliária fica negativo (' . self::brl($calc['saldo_final']) . ').';

        $c = $d['conclusao'];
        if ($c['ativo']) {
            $q = 'Conclusão do processo';
            if (!$c['valor'] || $c['valor'] <= 0) $e[] = "$q: informe o valor total.";
            if ($c['descricao'] === '') $e[] = "$q: escreva a descrição (o que foi feito e por quê).";
            if (!$c['recebem']) $e[] = "$q: informe quem concluiu o processo.";
            if (count($c['recebem']) > 3) $e[] = "$q: no máximo 3 pessoas recebem.";
            $sr = 0.0;
            foreach ($c['recebem'] as $x) {
                if ($x['nome'] === '') $e[] = "$q: falta o nome de quem recebe.";
                elseif (!$x['pessoa_id']) $e[] = "$q: \"{$x['nome']}\" não está no cadastro de Pessoas.";
                if (!in_array($x['cargo'], self::CARGOS, true)) $e[] = "$q: escolha o cargo de " . ($x['nome'] ?: 'quem recebe') . '.';
                if (!$x['valor'] || $x['valor'] <= 0) $e[] = "$q: falta o valor de " . ($x['nome'] ?: 'quem recebe') . '.';
                $sr += (float)$x['valor'];
            }
            $refs = [];
            foreach ($calc['linhas'] as $l) if ($l['bloco'] === 'comissao') $refs[self::ref($l['cargo'], $l['nome'])] = $l;
            $sd = 0.0; $porRef = [];
            if (!$c['descontos']) $e[] = "$q: informe de quem o valor é descontado.";
            foreach ($c['descontos'] as $x) {
                $l = $refs[$x['ref']] ?? null;
                if (!$l) { $e[] = "$q: desconto ligado a uma linha que não existe mais nas comissões — escolha de novo."; continue; }
                if (!$x['valor'] || $x['valor'] <= 0) $e[] = "$q: falta o valor descontado de {$l['nome']}.";
                if (isset($porRef[$x['ref']])) $e[] = "$q: {$l['cargo']} {$l['nome']} aparece duas vezes nos descontos.";
                $porRef[$x['ref']] = true;
                if ((float)$x['valor'] > $l['bruto'] + 0.005) $e[] = "$q: o desconto de {$l['nome']} (" . self::brl((float)$x['valor']) . ') é maior que a comissão dele como ' . $l['cargo'] . ' (' . self::brl($l['bruto']) . ').';
                $sd += (float)$x['valor'];
            }
            if ($c['valor'] && abs($sr - $c['valor']) > 0.005) $e[] = "$q: quem recebe soma " . self::brl($sr) . ', mas o total é ' . self::brl((float)$c['valor']) . '.';
            if ($c['valor'] && $c['descontos'] && abs($sd - $c['valor']) > 0.005) $e[] = "$q: os descontos somam " . self::brl($sd) . ', mas o total é ' . self::brl((float)$c['valor']) . '.';
            if ((float)$c['valor'] !== self::CONCLUSAO_PADRAO['valor'] && $c['valor']) $a[] = "$q: valor diferente do padrão (R$ 200,00).";
        }
        return ['erros' => $e, 'avisos' => $a];
    }

    /** linhas legíveis para o histórico (comparação por conjunto) */
    public static function resumo(?array $d): array
    {
        if (!$d) return [];
        $out = [];
        foreach ($d['linhas'] as $l) $out[] = self::BLOCOS[$l['bloco']][0] . ' · ' . $l['cargo'] . ' · ' . $l['nome'] . ' · ' . self::pctTxt((float)$l['pct']);
        $c = $d['conclusao'];
        if ($c['ativo']) {
            $out[] = 'Conclusão do processo: ' . self::brl((float)$c['valor']) . ' — ' . $c['descricao'];
            foreach ($c['recebem'] as $x) $out[] = 'Conclusão · recebe · ' . $x['cargo'] . ' · ' . $x['nome'] . ' · ' . self::brl((float)$x['valor']);
            foreach ($c['descontos'] as $x) $out[] = 'Conclusão · descontado de · ' . str_replace('|', ' · ', $x['ref']) . ' · ' . self::brl((float)$x['valor']);
        }
        return $out;
    }

    /** [campo, antes, depois] para cv_historico */
    public static function diferencas(?array $antes, array $depois): array
    {
        $a = self::resumo($antes); $d = self::resumo($depois);
        $out = [];
        foreach (array_diff($a, $d) as $x) $out[] = ['Distribuição', $x, '(removido)'];
        foreach (array_diff($d, $a) as $x) $out[] = ['Distribuição', $antes ? '(novo)' : '', $x];
        return $out;
    }

    public static function brl(float $v): string { return ($v < 0 ? '−' : '') . 'R$ ' . number_format(abs($v), 2, ',', '.'); }
    public static function pctTxt(float $p): string { return rtrim(rtrim(number_format($p * 100, 3, ',', ''), '0'), ',') . '%'; }
}
