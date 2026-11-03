<?php
/* =====================================================================
   capa-financeira/vendas/lib/Pagamentos.php — etapa 4: motor dos
   pagamentos aos corretores (linhas 86–143 da capa).

   Regras (decididas com o Jhony em 03/11/2026):
   - Etapas ARRAS / 50% / 75% / 100%, medidas contra a COMISSÃO BASE dos
     corretores (5%); o fluxo do cliente (por data) enche primeiro essa comissão.
   - Arras = recebimentos marcados como arras (etapa 3); data = a do último deles.
     Se o arras já cobre 50%/75%/100%, esses gatilhos são unificados no arras.
   - Demais gatilhos: a parcela do cliente em que o acumulado atinge o %; o
     pagamento vale o % efetivamente preenchido naquela data (pode passar do
     gatilho). Uma mesma parcela que cruza dois gatilhos vira um pagamento só.
   - Bruto da etapa × (1 − % NF) = líquido; brinde e cesta saem do ÚLTIMO
     pagamento de comissão (o que fecha 100%).
   - Cada linha da etapa 2 recebe o seu % do líquido de cada etapa. DIRETOR:
     a parte dele sobre o arras e o restante inteiro na etapa que fecha 50%.
   - Bônus: quando o cliente quita tudo (data da última parcela).
   - Conclusão do processo: o desconto sai do 1º pagamento da linha se ele
     cobrir; senão do 2º — nunca parcial. Quem concluiu recebe na mesma etapa.
   - Data de pagamento: cliente paga até segunda → quinta da mesma semana; de
     terça a domingo → quinta da semana seguinte; quinta feriado → quarta.
   - Travas (etapa 3): datas automáticas só até a etapa que fecha 50%; as
     seguintes de comissão exigem data manual (o bônus segue a sugestão).
     Condição (ex.: ENTREGA DE CHAVES) também exige data manual.
   - Centavos: cada pagamento truncado em 2 casas; a sobra fica com a imobiliária.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/Venda.php';
require_once __DIR__ . '/Distribuicao.php';
require_once __DIR__ . '/Fluxo.php';

final class Pagamentos
{
    public const GATILHOS = ['50' => 0.5, '75' => 0.75, '100' => 1.0];

    /* ---------------- datas ---------------- */

    /** feriados 'Y-m-d' => nome */
    public static function feriadosPadrao(int $de, int $ate): array
    {
        $out = [];
        for ($y = $de; $y <= $ate; $y++) {
            foreach (['01-01' => 'Confraternização Universal', '03-09' => 'Aniversário de Joinville', '04-21' => 'Tiradentes', '05-01' => 'Dia do Trabalho',
                      '09-07' => 'Independência', '10-12' => 'Nossa Senhora Aparecida', '11-02' => 'Finados', '11-15' => 'Proclamação da República',
                      '11-20' => 'Consciência Negra', '12-25' => 'Natal'] as $md => $n) $out["$y-$md"] = $n;
            $p = self::pascoa($y);
            $out[$p->modify('-2 days')->format('Y-m-d')] = 'Sexta-feira Santa';
            $out[$p->modify('+60 days')->format('Y-m-d')] = 'Corpus Christi';
        }
        ksort($out);
        return $out;
    }

    /** domingo de Páscoa (algoritmo de Meeus/Jones/Butcher) */
    public static function pascoa(int $y): DateTimeImmutable
    {
        $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31); $dia = (($h + $l - 7 * $m + 114) % 31) + 1;
        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $mes, $dia));
    }

    /** data sugerida de pagamento ao corretor a partir da data em que o cliente paga */
    public static function dataPagamento(string $dataCliente, array $feriados): string
    {
        $d = new DateTimeImmutable($dataCliente);
        $dow = (int)$d->format('N');   // 1 = segunda … 7 = domingo
        $segunda = $d->modify('-' . ($dow - 1) . ' days');
        $quinta = $dow === 1 ? $segunda->modify('+3 days') : $segunda->modify('+10 days');
        if (isset($feriados[$quinta->format('Y-m-d')])) $quinta = $quinta->modify('-1 day');   // quinta feriado → quarta
        return $quinta->format('Y-m-d');
    }

    private static function trunca(float $v): float { return floor($v * 100 + 1e-6) / 100; }

    /* ---------------- etapas (tranches) ---------------- */

    /**
     * @return array{etapas: array<int, array>, erros: string[]}
     *   etapa = [chave, rotulo, gatilhos[], data_cliente, frac (da comissão base), bruto, liquido, ultima_comissao(bool), bonus(bool)]
     */
    public static function etapas(array $venda): array
    {
        $erros = [];
        $c1 = Venda::calc($venda);
        $B = (float)$c1['comissao_base'];
        $parc = Fluxo::porData($venda['e3']['parcelas'] ?? []);
        if (!$parc || $B <= 0) return ['etapas' => [], 'erros' => ['Sem fluxo do cliente (etapa 3) ou sem comissão (etapa 1).']];
        $etapas = []; $coberto = 0.0; $acum = 0.0;
        $pend = self::GATILHOS;
        // arras
        $arras = array_values(array_filter($parc, fn($p) => !empty($p['arras'])));
        if ($arras) {
            $A = array_sum(array_map(fn($p) => (float)$p['valor'], $arras));
            $dataA = max(array_map(fn($p) => (string)$p['data'], $arras));
            $f = min(1, $A / $B);
            $junto = [];
            foreach ($pend as $k => $g) if ($f + 1e-9 >= $g) { $junto[] = $k . '%'; unset($pend[$k]); }
            $etapas[] = ['chave' => 'arras' . ($junto ? '+' . implode('+', array_map(fn($x) => rtrim($x, '%'), $junto)) : ''), 'rotulo' => 'Arras' . ($junto ? ' + ' . implode(' + ', $junto) : ''),
                         'gatilhos' => $junto, 'data_cliente' => $dataA, 'frac' => $f, 'pct_preenchido' => $f];
            $coberto = $f; $acum = $A;
        }
        foreach ($parc as $p) {
            if (!$pend) break;
            if (!empty($p['arras'])) continue;
            $acum += (float)$p['valor'];
            $f = min(1, $acum / $B);
            $hit = [];
            foreach ($pend as $k => $g) if ($f + 1e-9 >= $g) { $hit[] = $k; unset($pend[$k]); }
            if ($hit) {
                $etapas[] = ['chave' => implode('+', $hit), 'rotulo' => implode(' + ', array_map(fn($x) => $x . '%', $hit)), 'gatilhos' => array_map(fn($x) => $x . '%', $hit),
                             'data_cliente' => (string)$p['data'], 'frac' => $f - $coberto, 'pct_preenchido' => $f];
                $coberto = $f;
            }
        }
        if ($pend) $erros[] = 'O fluxo do cliente não chega a 100% da comissão base dos corretores — confira a etapa 3.';
        $nf = (float)$venda['pct_nf'];
        $desc = (float)$c1['brinde'] + (float)$c1['cesta'];
        $n = count($etapas);
        foreach ($etapas as $i => &$e) {
            $e['bruto'] = $e['frac'] * $B;
            $e['liquido'] = $e['bruto'] * (1 - $nf) - ($i === $n - 1 ? $desc : 0);   // brinde + cesta saem do último
            $e['ultima_comissao'] = $i === $n - 1; $e['bonus'] = false;
            $e['tem50'] = in_array('50%', $e['gatilhos'], true);
        }
        unset($e);
        if ($c1['bonus'] > 0) {
            $quit = max(array_map(fn($p) => (string)$p['data'], $parc));
            $etapas[] = ['chave' => 'bonus', 'rotulo' => 'Bônus (quitação)', 'gatilhos' => [], 'data_cliente' => $quit, 'frac' => 0, 'pct_preenchido' => 1,
                         'bruto' => (float)$c1['bonus'], 'liquido' => (float)$c1['bonus_distribuicao'], 'ultima_comissao' => false, 'bonus' => true, 'tem50' => false];
        }
        return ['etapas' => $etapas, 'erros' => $erros];
    }

    /* ---------------- pagamentos ---------------- */

    public static function chave(string $etapa, string $natureza, string $cargo, ?int $pessoaId): string
    {
        return $etapa . '|' . $natureza . '|' . Venda::texto($cargo) . '|' . (int)$pessoaId;
    }

    /**
     * Monta todos os pagamentos. $e4 = dados gravados (datas manuais, condições, liberações).
     * @return array{etapas: array, pagamentos: array, erros: string[], avisos: string[], totais: array}
     */
    public static function calcular(array $venda, ?array $e4, array $feriados): array
    {
        $erros = []; $avisos = [];
        $r = self::etapas($venda);
        $etapas = $r['etapas']; $erros = array_merge($erros, $r['erros']);
        $e2 = $venda['e2'] ?? null;
        if (!$e2 || empty($e2['linhas'])) return ['etapas' => $etapas, 'pagamentos' => [], 'erros' => ['Sem distribuição (etapa 2).'], 'avisos' => [], 'totais' => []];
        $c1 = Venda::calc($venda);
        $travas = $venda['e3']['travas'] ?? [];
        $e4 = $e4 ?? ['etapas' => [], 'liberados' => []];
        $idx50 = null;
        foreach ($etapas as $i => $e) if ($e['tem50']) { $idx50 = $i; break; }
        // datas: sugerida, manual exigida?
        foreach ($etapas as $i => &$e) {
            $cfg = $e4['etapas'][$e['chave']] ?? [];
            $e['condicao'] = (string)($cfg['condicao'] ?? '');
            $e['data_manual'] = $cfg['data_manual'] ?? null;
            $e['data_sugerida'] = $e['data_cliente'] ? self::dataPagamento($e['data_cliente'], $feriados) : null;
            $porTrava = $travas && !$e['bonus'] && $idx50 !== null && $i > $idx50;
            $e['exige_manual'] = $porTrava || $e['condicao'] !== '';
            $e['motivo_manual'] = $porTrava ? 'trava: ' . implode(', ', array_map(fn($t) => Fluxo::TRAVAS[$t], $travas)) : ($e['condicao'] !== '' ? 'condição: ' . $e['condicao'] : '');
            $e['data'] = $e['data_manual'] ?: ($e['exige_manual'] ? null : $e['data_sugerida']);
        }
        unset($e);
        $com = array_values(array_filter($etapas, fn($e) => !$e['bonus']));
        $pags = [];
        $add = function (array $et, string $nat, array $l, float $valor, string $obs = '') use (&$pags) {
            $k = self::chave($et['chave'], $nat, $l['cargo'], $l['pessoa_id'] ?? null);
            if (isset($pags[$k])) { $pags[$k]['valor'] += $valor; if ($obs !== '') $pags[$k]['obs'] = trim($pags[$k]['obs'] . ' · ' . $obs, ' ·'); return; }
            $pags[$k] = ['chave' => $k, 'etapa' => $et['chave'], 'natureza' => $nat, 'cargo' => $l['cargo'], 'pessoa_id' => $l['pessoa_id'] ?? null, 'nome' => $l['nome'],
                         'pct' => (float)($l['pct'] ?? 0), 'valor' => $valor, 'obs' => $obs];
        };
        $valorFinal = (float)$c1['valor_final'];
        foreach ($e2['linhas'] as $l) {
            if ($l['bloco'] === 'comissao') {
                $p = (float)$l['pct'];
                if ($l['cargo'] === 'DIRETOR' && $idx50 !== null) {
                    $antes = 0.0;
                    foreach ($com as $i => $et) {
                        if ($i < $idx50) { $v = $p * $et['liquido']; $antes += $v; $add($et, 'COMISSAO', $l, $v); }
                        elseif ($i === $idx50) { $add($et, 'COMISSAO', $l, $p * $valorFinal - $antes, 'restante do diretor na etapa de 50%'); }
                    }
                } else {
                    foreach ($com as $et) $add($et, 'COMISSAO', $l, $p * $et['liquido']);
                }
            } elseif ($l['bloco'] === 'bonus') {
                foreach ($etapas as $et) if ($et['bonus']) $add($et, 'BONUS', $l, (float)$l['pct'] * (float)$c1['bonus_distribuicao']);
            }
        }
        // conclusão do processo: desconto no 1º pagamento da linha (se cobrir) senão no 2º; quem concluiu recebe na mesma etapa
        $conc = $e2['conclusao'] ?? null;
        if ($conc && !empty($conc['ativo'])) {
            $etapaReceb = null;
            foreach ($conc['descontos'] as $dsc) {
                [$cargo, $nome] = array_pad(explode('|', (string)$dsc['ref'], 2), 2, '');
                $linha = null;
                foreach ($e2['linhas'] as $l) if ($l['bloco'] === 'comissao' && Distribuicao::ref($l['cargo'], $l['nome']) === $dsc['ref']) { $linha = $l; break; }
                if (!$linha) { $erros[] = 'Conclusão do processo: desconto ligado a uma linha que não existe mais.'; continue; }
                $feito = false;
                foreach (array_slice($com, 0, 2) as $et) {
                    $k = self::chave($et['chave'], 'COMISSAO', $linha['cargo'], $linha['pessoa_id']);
                    if (isset($pags[$k]) && $pags[$k]['valor'] + 1e-9 >= (float)$dsc['valor']) {
                        $pags[$k]['valor'] -= (float)$dsc['valor'];
                        $pags[$k]['obs'] = trim($pags[$k]['obs'] . ' · descontado ' . Distribuicao::brl((float)$dsc['valor']) . ' (conclusão do processo)', ' ·');
                        $pags[$k]['desconto'] = (float)$dsc['valor'];
                        $etapaReceb = $etapaReceb === null ? $et : (array_search($et['chave'], array_column($com, 'chave')) > array_search($etapaReceb['chave'], array_column($com, 'chave')) ? $et : $etapaReceb);
                        $feito = true; break;
                    }
                }
                if (!$feito) $erros[] = "Conclusão do processo: nem o 1º nem o 2º pagamento de {$linha['nome']} ({$linha['cargo']}) cobrem o desconto de " . Distribuicao::brl((float)$dsc['valor']) . ' — ajuste o desconto na etapa 2.';
            }
            if ($etapaReceb) foreach ($conc['recebem'] as $x) $add($etapaReceb, 'COMISSAO', $x + ['pct' => 0], (float)$x['valor'], 'conclusão do processo');
        }
        // centavos: trunca cada pagamento; a sobra fica com a imobiliária
        $lib = $e4['liberados'] ?? [];
        $porEtapa = array_column($etapas, null, 'chave');
        foreach ($pags as $k => &$p) {
            $p['valor'] = self::trunca($p['valor']);
            $et = $porEtapa[$p['etapa']];
            $p['data'] = $et['data']; $p['condicao'] = $et['condicao'];
            $p['liberado'] = $lib[$k] ?? null;
            $p['alterado_depois'] = $p['liberado'] && (abs((float)$p['liberado']['valor'] - $p['valor']) > 0.005 || (string)$p['liberado']['data'] !== (string)$p['data']
                                  || (string)($p['liberado']['condicao'] ?? $p['condicao']) !== (string)$p['condicao']);
            if ($p['valor'] < -0.005) $erros[] = "Pagamento negativo para {$p['nome']} ({$p['cargo']}) na etapa {$et['rotulo']}.";
        }
        unset($p);
        $pags = array_values(array_filter($pags, fn($p) => $p['valor'] > 0.004 || $p['liberado']));
        // liberados que sumiram (o cálculo mudou depois de liberar)
        $vivos = array_column($pags, 'chave');
        foreach ($lib as $k => $x) if (!in_array($k, $vivos, true)) $avisos[] = 'Um pagamento já liberado (' . str_replace('|', ' · ', preg_replace('/\|\d+$/', '', $k)) . ', ' . Distribuicao::brl((float)$x['valor']) . ') não existe mais no cálculo atual — confira (se já foi ao Omie, ajuste lá).';
        // conferência com a etapa 2
        $calc2 = Distribuicao::calc($e2, $c1);
        $previsto = $calc2['pagos'];
        $total = array_sum(array_column($pags, 'valor'));
        if ($total - $previsto > 0.01) $erros[] = 'Os pagamentos somam ' . Distribuicao::brl($total) . ', MAIS que a distribuição da etapa 2 (' . Distribuicao::brl($previsto) . ').';
        elseif ($previsto - $total > 0.05 * max(1, count($pags))) $avisos[] = 'Os pagamentos somam ' . Distribuicao::brl($total) . ' e a distribuição da etapa 2 ' . Distribuicao::brl($previsto) . ' — diferença maior que os centavos de arredondamento.';
        foreach ($etapas as $e) if ($e['exige_manual'] && !$e['data_manual']) $avisos[] = 'Etapa ' . $e['rotulo'] . ': informe a data de pagamento (' . $e['motivo_manual'] . ').';
        return ['etapas' => $etapas, 'pagamentos' => $pags, 'erros' => $erros, 'avisos' => $avisos,
                'totais' => ['previsto' => $previsto, 'total' => $total, 'sobra_centavos' => $previsto - $total,
                             'liberado' => array_sum(array_map(fn($p) => $p['liberado'] ? $p['valor'] : 0, $pags))]];
    }
}
