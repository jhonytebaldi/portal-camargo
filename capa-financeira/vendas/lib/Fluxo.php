<?php
/* =====================================================================
   capa-financeira/vendas/lib/Fluxo.php — etapa 3: fluxo de pagamento do
   cliente (linhas 58–77 da capa) e valores já recebidos.

   Cada parcela = uma conta a receber no Omie (aberta — alguém dá a baixa lá):
     data (prevista; para o que já entrou, a data em que entrou), valor,
     pagador (comprador 1 por padrão; outro comprador ou um terceiro —
     pai, irmão, empresa…), conta corrente onde o dinheiro cai (Sicoob por
     padrão), referência do contrato ("PARCELA 01"), já recebido?, arras?
   O total do fluxo tem que fechar com COMISSÃO + BÔNUS (etapa 1).
   Travas (perguntadas aqui): IR futuro, condicionado abaixo de 70%, pré-reserva
   — com qualquer uma, a etapa 4 só sugere datas até o gatilho de 50%.

   Guardado em cv_vendas.e3_dados (JSON):
     {travas: [..], conferido: bool, conferido_por, conferido_em,
      parcelas: [{data, valor, pagador: 'c0'|'c1'|…|'construtora'|'outro', outro_nome, outro_doc, conta, ref, recebido, arras}],
      quem_paga: {modo: comprador|construtora|outro, pagador, sinal}  — resposta do modal (padrão das parcelas novas),
      construtora_cad: {codigo, nome, doc} — cadastro no Omie (empresa da venda) de quando a construtora paga}
   Parcela paga pela CONSTRUTORA (recebe do cliente e repassa): digita-se a data do quadro resumo do contrato;
   a conta a receber (e os gatilhos da etapa 4) usam o REPASSE = data + 7 dias corridos → próximo dia útil.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/Venda.php';

final class Fluxo
{
    public const TRAVAS = ['IR_FUTURO' => 'Imposto de Renda Futuro', 'ABAIXO_70' => 'Condicionado abaixo de 70% da parcela necessária', 'PRE_RESERVA' => 'Pré-Reserva'];
    public const CONTAS_PADRAO = ['SICOOB', 'ASAAS', 'OMIE.CASH', 'INTER', 'SICREDI'];
    public const GATILHOS = [0.5, 0.75, 1.0];
    public const DIAS_ARRAS = 10;   // recebimentos até N dias depois da venda entram como arras por padrão
    public const DIAS_REPASSE = 7;  // construtora repassa N dias corridos depois do pagamento do cliente
    /** feriados ('Y-m-d' => nome) — definidos em _vendas.php (cf_config 'feriados') */
    public static array $feriados = [];

    /** data + N dias corridos; caindo em fim de semana/feriado, vai para o próximo dia útil */
    public static function repasse(?string $data): ?string
    {
        if (!$data) return null;
        $d = new DateTimeImmutable($data . ' 12:00:00');
        $d = $d->modify('+' . self::DIAS_REPASSE . ' days');
        while ((int)$d->format('N') >= 6 || isset(self::$feriados[$d->format('Y-m-d')])) $d = $d->modify('+1 day');
        return $d->format('Y-m-d');
    }
    /** data da conta a receber: a da parcela, ou a do repasse quando quem paga é a construtora */
    public static function vencimento(array $p): ?string
    {
        return ($p['pagador'] ?? '') === 'construtora' ? self::repasse($p['data'] ?? null) : ($p['data'] ?? null);
    }

    public static function vazio(): array
    {
        return ['travas' => [], 'conferido' => false, 'parcelas' => []];
    }

    /** @return array{0: array, 1: string[]} [dados, erros de formato] */
    public static function doFormulario(array $in): array
    {
        $fmt = [];
        $d = ['travas' => array_values(array_intersect(array_keys(self::TRAVAS), (array)($in['travas'] ?? []))),
              'conferido' => !empty($in['conferido']), 'ordem_manual' => !empty($in['ordem_manual']), 'parcelas' => []];
        foreach (array_values((array)($in['parcelas'] ?? [])) as $i => $p) {
            $data = Venda::data($p['data'] ?? '');
            if ($data === false) { $fmt[] = 'Parcela ' . ($i + 1) . ': data ilegível.'; $data = null; }
            $valor = Venda::dinheiro($p['valor'] ?? null);
            if ($valor === false) { $fmt[] = 'Parcela ' . ($i + 1) . ': valor ilegível.'; $valor = null; }
            $pag = (string)($p['pagador'] ?? 'c0');
            if ($pag !== 'outro' && $pag !== 'construtora' && !preg_match('/^c\d$/', $pag)) $pag = 'c0';
            if ($data === null && $valor === null && trim((string)($p['ref'] ?? '')) === '') continue;
            $d['parcelas'][] = ['data' => $data, 'valor' => $valor, 'pagador' => $pag,
                'outro_nome' => $pag === 'outro' ? Venda::texto($p['outro_nome'] ?? '') : '', 'outro_doc' => $pag === 'outro' ? Venda::docLimpo($p['outro_doc'] ?? '') : '',
                'conta' => Venda::texto($p['conta'] ?? ''), 'ref' => Venda::texto($p['ref'] ?? ''),
                'recebido' => !empty($p['recebido']), 'arras' => !empty($p['arras']) && !empty($p['recebido'])] + (isset($p['k']) ? ['k' => (int)$p['k']] : []);
        }
        $q = (array)($in['quem_paga'] ?? []);
        if (in_array($q['modo'] ?? '', ['comprador', 'construtora', 'outro'], true)) {
            $d['quem_paga'] = ['modo' => $q['modo'], 'pagador' => preg_replace('/[^a-z0-9]/', '', (string)($q['pagador'] ?? '')), 'sinal' => preg_replace('/[^a-z0-9]/', '', (string)($q['sinal'] ?? ''))];
        }
        $cc = (array)($in['construtora_cad'] ?? []);
        if (!empty($cc['codigo'])) $d['construtora_cad'] = ['codigo' => (int)$cc['codigo'], 'nome' => Venda::texto((string)($cc['nome'] ?? '')), 'doc' => Venda::docLimpo((string)($cc['doc'] ?? ''))];
        return [$d, $fmt];
    }

    /** nome e documento de quem paga a parcela ($d = dados da etapa 3, para o cadastro da construtora) */
    public static function pagador(array $p, array $compradores, ?array $d = null): array
    {
        if ($p['pagador'] === 'outro') return ['nome' => $p['outro_nome'], 'documento' => $p['outro_doc'], 'email' => '', 'comprador' => null];
        if ($p['pagador'] === 'construtora') {
            $cc = $d['construtora_cad'] ?? null;
            return ['nome' => (string)($cc['nome'] ?? 'CONSTRUTORA'), 'documento' => (string)($cc['doc'] ?? ''), 'email' => '', 'comprador' => null, 'construtora' => true, 'omie_codigo' => $cc['codigo'] ?? null];
        }
        $i = (int)substr($p['pagador'], 1);
        $c = $compradores[$i] ?? null;
        return ['nome' => (string)($c['nome'] ?? ''), 'documento' => (string)($c['documento'] ?? ''), 'email' => (string)($c['email'] ?? ''), 'comprador' => $c ? $i : null];
    }

    /** ordem do fluxo = a ordem gravada (a tela ordena por data por padrão, mas o usuário pode arrastar — ex.: balões no meio).
     *  É a ordem da planilha e da numeração das parcelas no Omie. */
    public static function ordenadas(array $parcelas): array
    {
        $out = [];
        // 'data' passa a ser a da conta a receber (repasse, se a construtora paga); a digitada fica em 'data_contrato'
        foreach (array_values($parcelas) as $i => $p) $out[] = ['data_contrato' => $p['data'] ?? null, 'data' => self::vencimento($p)] + $p + ['_i' => $i];
        return $out;
    }

    /** ordem cronológica (sem data por último; empate mantém a ordem gravada) — usada para os acumulados e gatilhos */
    public static function porData(array $parcelas): array
    {
        $o = self::ordenadas($parcelas);
        usort($o, fn($a, $b) => [($a['data'] ?? '9999'), $a['_i']] <=> [($b['data'] ?? '9999'), $b['_i']]);
        return $o;
    }

    /** % e acumulados; prévia dos gatilhos da etapa 4 (o fluxo enche primeiro a comissão base dos corretores) */
    public static function calc(array $d, array $c1, ?array $gatilhos = null): array
    {
        $total = (float)$c1['comissao_mais_bonus'];
        $base = (float)$c1['comissao_base'];
        $soma = 0.0; $rec = 0.0; $arras = 0.0; $acum = 0.0;
        $linhas = []; $gat = []; $pend = $gatilhos ?? self::GATILHOS;
        foreach (self::porData($d['parcelas']) as $p) {   // acumulado sempre em ordem de data, qualquer que seja a ordem da lista
            $v = (float)$p['valor'];
            $soma += $v; $acum += $v;
            if ($p['recebido']) $rec += $v;
            if ($p['arras']) $arras += $v;
            $pctBase = $base > 0 ? min(1, $acum / $base) : 0;
            $marca = [];
            foreach ($pend as $j => $g) if ($pctBase + 1e-9 >= $g) { $marca[] = (int)round($g * 100) . '%'; unset($pend[$j]); }
            if ($marca) $gat[] = ['quando' => $p['data'], 'gatilhos' => $marca, 'pct_base' => $pctBase];
            $linhas[] = ['i' => $p['_i'], 'k' => $p['k'] ?? null, 'venc' => $p['data'], 'repasse' => ($p['pagador'] ?? '') === 'construtora', 'pct' => $total > 0 ? $v / $total : 0, 'pct_acum' => $total > 0 ? $acum / $total : 0,
                         'pct_base' => $pctBase, 'gatilhos' => $marca];
        }
        usort($linhas, fn($a, $b) => $a['i'] <=> $b['i']);   // devolve na ordem da lista (índice = posição gravada)
        return ['total_fluxo' => $soma, 'total_esperado' => $total, 'diferenca' => $soma - $total, 'recebido' => $rec, 'devido' => $soma - $rec,
                'arras' => $arras, 'linhas' => $linhas, 'gatilhos' => $gat];
    }

    /** @return array{erros: string[], avisos: string[]} */
    public static function validar(array $d, array $c1, array $venda): array
    {
        $e = []; $a = [];
        $comp = $venda['compradores'] ?? [];
        $hoje = date('Y-m-d');
        if (!$d['parcelas']) $e[] = 'Informe as parcelas do fluxo de pagamento do cliente.';
        foreach ($d['parcelas'] as $i => $p) {
            $q = 'Parcela ' . ($i + 1) . ($p['ref'] !== '' ? ' (' . $p['ref'] . ')' : '');
            if (!$p['data']) $e[] = "$q: falta a data.";
            if (!$p['valor'] || $p['valor'] <= 0) $e[] = "$q: falta o valor.";
            $pg = self::pagador($p, $comp, $d);
            if ($p['pagador'] === 'construtora') {
                if (empty($d['construtora_cad']['codigo'])) $e[] = "$q: escolha o cadastro da construtora no Omie (botão \"quem paga\").";
            } elseif ($p['pagador'] === 'outro') {
                if ($p['outro_nome'] === '' || count(explode(' ', $p['outro_nome'])) < 2) $e[] = "$q: informe o nome completo de quem paga.";
                if ($p['outro_doc'] === '') $e[] = "$q: falta o CPF/CNPJ de quem paga.";
                elseif (!Venda::tipoDoc($p['outro_doc'])) $e[] = "$q: CPF/CNPJ de quem paga é inválido.";
            } elseif ($pg['comprador'] === null) $e[] = "$q: o comprador escolhido não existe mais na etapa 1 — escolha de novo.";
            if ($p['conta'] === '') $e[] = "$q: escolha a conta onde o dinheiro cai.";
            if ($p['recebido'] && $p['data'] && $p['data'] > $hoje) $e[] = "$q: marcada como já recebida, mas com data no futuro.";
            if ($p['data'] && !empty($venda['data_venda']) && $p['data'] < date('Y-m-d', strtotime($venda['data_venda'] . ' -60 days'))) $a[] = "$q: data bem antes da venda — confira.";
            if (!$p['recebido'] && $p['data'] && $p['data'] < $hoje) $a[] = "$q: data já passou e não está marcada como recebida.";
        }
        $c = self::calc($d, $c1);
        if ($d['parcelas'] && abs($c['diferenca']) > 0.009) {
            $e[] = 'O fluxo soma ' . self::brl($c['total_fluxo']) . ', mas a comissão + bônus da venda é ' . self::brl($c['total_esperado'])
                 . ' (' . ($c['diferenca'] > 0 ? 'sobra ' : 'falta ') . self::brl(abs($c['diferenca'])) . ').';
        }
        if ($d['parcelas'] && $c['arras'] <= 0) $a[] = 'Nenhum recebimento marcado como arras (sinal).';
        if (!$d['conferido']) $e[] = 'Marque que conferiu o fluxo com o contrato e o termo de honorários.';
        return ['erros' => $e, 'avisos' => $a];
    }

    public static function resumo(?array $d, array $comp): array
    {
        if (!$d) return [];
        $out = [];
        foreach (self::ordenadas($d['parcelas']) as $p) {
            $pg = self::pagador($p, $comp, $d);
            $out[] = 'Parcela ' . ($p['ref'] ?: '') . ' · ' . Venda::br($p['data_contrato']) . ($p['pagador'] === 'construtora' ? ' (repasse ' . Venda::br($p['data']) . ')' : '') . ' · ' . self::brl((float)$p['valor']) . ' · ' . $pg['nome'] . ' · ' . $p['conta']
                   . ($p['recebido'] ? ' · recebida' : '') . ($p['arras'] ? ' · arras' : '');
        }
        if (!empty($d['quem_paga'])) {
            $q = $d['quem_paga'];
            $rot = fn($x) => $x === 'construtora' ? 'construtora' : ($x === 'outro' ? 'outro pagador' : (preg_match('/^c(\d+)$/', (string)$x, $m) ? ($comp[(int)$m[1]]['nome'] ?? 'comprador') : (string)$x));
            $out[] = 'Quem paga as parcelas: ' . ($q['modo'] === 'construtora' ? 'construtora (' . ($d['construtora_cad']['nome'] ?? '?') . ') — sinal: ' . $rot($q['sinal']) : $rot($q['pagador']));
        }
        foreach ($d['travas'] as $t) $out[] = 'Trava: ' . self::TRAVAS[$t];
        if ($d['conferido']) $out[] = 'Conferido com contrato e termo de honorários';
        return $out;
    }

    public static function diferencas(?array $antes, array $depois, array $comp): array
    {
        $a = self::resumo($antes, $comp); $d = self::resumo($depois, $comp);
        $out = [];
        foreach (array_diff($a, $d) as $x) $out[] = ['Fluxo do cliente', $x, '(removido)'];
        foreach (array_diff($d, $a) as $x) $out[] = ['Fluxo do cliente', $antes ? '(novo)' : '', $x];
        // mesma lista em outra ordem (parcelas arrastadas): muda a numeração das parcelas no Omie
        if (!$out && $antes && $a !== $d) $out[] = ['Ordem das parcelas', 'ordem anterior', ($depois['ordem_manual'] ?? false) ? 'reorganizada à mão' : 'por data'];
        return $out;
    }

    public static function brl(float $v): string { return ($v < 0 ? '−' : '') . 'R$ ' . number_format(abs($v), 2, ',', '.'); }
}
