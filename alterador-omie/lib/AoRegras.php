<?php
/* =====================================================================
   alterador-omie/lib/AoRegras.php — calcula o "depois" de cada título.

   Funções puras (sem banco, sem API): recebem os títulos normalizados
   (AoTitulo), as alterações escolhidas na tela e devolvem, por título,
   os campos novos + avisos + bloqueios. A MESMA conta roda na prévia
   e de novo na hora de enviar (com o título relido do Omie) — se o
   resultado mudar entre as duas, o item vira "conflito" e não é enviado.

   Alteração (op), vindas da tela:
     texto:    {campo, modo: substituir|antes|depois|trocar, texto, sep, procurar}
     data:     {campo, modo: somar|fixa|dia|copiar, n, uteis, ajuste: manter|proximo|anterior, data, dia, de}
     cadastro: {campo, valor}             deps: {campo:'deps', itens:[[cod, perc]]}
     valor:    {campo:'valor', modo: fixo|percent|somar, n}
     pix:      {campo:'pix'}  → Transferência por chave PIX com a chave do cadastro do fornecedor
   Opções da rodada: ordem (venc|prev|emis|id|forn|tabela), seq {inicio, passo, digitos, reinicia: nada|forn},
     seqdata {inicio, passo, unidade: d|m}.
   Marcadores no texto: {atual} {seq} {seq:4} {n} {total} {data} {data:mm/aaaa} {venc} {prev} {emis} {reg}
     {venc:mm/aaaa} {forn} {doc} {nf} {parc} {valor} {cat} {id} {ci}
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/AoTitulo.php';

final class AoData
{
    public static function ler(string $s): ?DateTimeImmutable
    {
        $s = trim($s);
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $s, $m)) return checkdate((int)$m[2], (int)$m[1], (int)$m[3]) ? new DateTimeImmutable(sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1])) : null;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? new DateTimeImmutable($s) : null;
        return null;
    }
    public static function br(DateTimeImmutable $d): string { return $d->format('d/m/Y'); }
    /** formato com dd, mm, aaaa, aa (ex. "mm/aaaa") */
    public static function fmt(DateTimeImmutable $d, string $f): string
    {
        $f = $f !== '' ? $f : 'dd/mm/aaaa';
        return strtr($f, ['aaaa' => $d->format('Y'), 'AAAA' => $d->format('Y'), 'aa' => $d->format('y'), 'AA' => $d->format('y'), 'dd' => $d->format('d'), 'DD' => $d->format('d'), 'mm' => $d->format('m'), 'MM' => $d->format('m')]);
    }

    /** feriados que fecham banco em Joinville: nacionais + Carnaval (seg/ter) + Sexta Santa + Corpus Christi + aniversário de Joinville (09/03) */
    public static function feriados(int $ano): array
    {
        static $c = [];
        if (isset($c[$ano])) return $c[$ano];
        $f = [];
        foreach (['01-01', '03-09', '04-21', '05-01', '09-07', '10-12', '11-02', '11-15', '11-20', '12-25'] as $md) $f["$ano-$md"] = true;
        $pascoa = (new DateTimeImmutable("$ano-03-21"))->modify('+' . easter_days($ano) . ' days');
        foreach (['-48 days', '-47 days', '-2 days', '+60 days'] as $d) $f[$pascoa->modify($d)->format('Y-m-d')] = true;
        return $c[$ano] = $f;
    }
    public static function util(DateTimeImmutable $d): bool
    {
        return (int)$d->format('N') < 6 && !isset(self::feriados((int)$d->format('Y'))[$d->format('Y-m-d')]);
    }
    public static function somarUteis(DateTimeImmutable $d, int $n): DateTimeImmutable
    {
        $passo = $n >= 0 ? '+1 day' : '-1 day';
        for ($i = abs($n); $i > 0;) { $d = $d->modify($passo); if (self::util($d)) $i--; }
        return $d;
    }
    public static function ajustar(DateTimeImmutable $d, string $ajuste): DateTimeImmutable
    {
        if ($ajuste === 'proximo') while (!self::util($d)) $d = $d->modify('+1 day');
        elseif ($ajuste === 'anterior') while (!self::util($d)) $d = $d->modify('-1 day');
        return $d;
    }
    /** soma meses sem "pular" o mês (31/01 + 1 mês = 28/02) */
    public static function somarMeses(DateTimeImmutable $d, int $n): DateTimeImmutable
    {
        $dia = (int)$d->format('d');
        $p = $d->modify('first day of this month')->modify(($n >= 0 ? '+' : '') . $n . ' months');
        return $p->setDate((int)$p->format('Y'), (int)$p->format('m'), min($dia, (int)$p->format('t')));
    }
}

final class AoRegras
{
    public const TEXTO_MODOS = ['substituir', 'antes', 'depois', 'trocar'];

    /** valida as alterações escolhidas (antes de olhar título). @return string[] erros */
    public static function validarOps(array $ops, string $tipos, array $cat): array
    {
        $e = []; $vistos = [];
        if (!$ops) $e[] = 'escolha pelo menos uma alteração';
        foreach ($ops as $i => $op) {
            $c = (string)($op['campo'] ?? ''); $def = AoTitulo::CAMPOS[$c] ?? null; $n = '#' . ($i + 1) . ' ' . ($def[0] ?? $c);
            if (!$def) { $e[] = "campo desconhecido: $c"; continue; }
            if (isset($vistos[$c])) $e[] = "$n: o mesmo campo aparece duas vezes — junte numa alteração só";
            $vistos[$c] = 1;
            switch ($def[1]) {
                case 'texto':
                    $m = (string)($op['modo'] ?? '');
                    if ($c === 'obs' && $m !== 'depois') $e[] = "$n: pela API o Omie só ACRESCENTA uma linha nova na observação (não substitui nem insere no início) — use \"inserir depois\" ou a alteração em lote da própria tela do Omie";
                    elseif (!in_array($m, self::TEXTO_MODOS, true)) $e[] = "$n: escolha como aplicar o texto";
                    if ($m === 'trocar' && trim((string)($op['procurar'] ?? '')) === '') $e[] = "$n: informe o trecho a procurar";
                    if ($m !== 'trocar' && trim((string)($op['texto'] ?? '')) === '') $e[] = "$n: o texto está vazio (o Omie ignora texto vazio — não dá para limpar o campo pela API)";
                    break;
                case 'data':
                    $m = (string)($op['modo'] ?? '');
                    if ($m === 'somar' && (int)($op['n'] ?? 0) === 0) $e[] = "$n: informe quantos dias somar (ou subtrair, com sinal de menos)";
                    elseif ($m === 'fixa' && !AoData::ler((string)($op['data'] ?? ''))) $e[] = "$n: data inválida";
                    elseif ($m === 'dia' && ((int)($op['dia'] ?? 0) < 1 || (int)($op['dia'] ?? 0) > 31)) $e[] = "$n: dia do mês inválido";
                    elseif ($m === 'copiar' && !in_array((string)($op['de'] ?? ''), ['venc', 'prev', 'emis', 'reg'], true)) $e[] = "$n: escolha de qual data copiar";
                    elseif (!in_array($m, ['somar', 'fixa', 'dia', 'copiar'], true)) $e[] = "$n: escolha a regra da data";
                    break;
                case 'cadastro':
                    $v = (string)($op['valor'] ?? '');
                    if ($v === '') { $e[] = "$n: escolha o novo valor"; break; }
                    if ($c === 'cat') {
                        $ct = $cat['cat'][$v] ?? null;
                        if (!$ct) $e[] = "$n: categoria $v não existe nesta empresa";
                        elseif (!$ct['lanc']) $e[] = "$n: categoria $v não é lançável (totalizadora ou inativa)";
                        elseif ($tipos === 'P' && !$ct['desp']) $e[] = "$n: $v não é categoria de despesa";
                        elseif ($tipos === 'R' && !$ct['rec']) $e[] = "$n: $v não é categoria de receita";
                        elseif ($tipos === 'PR') $e[] = "$n: categoria não pode ser alterada com pagar e receber na mesma rodada";
                    }
                    if ($c === 'cc' && !isset($cat['cc'][$v])) $e[] = "$n: conta corrente não encontrada";
                    if ($c === 'proj' && !isset($cat['proj'][$v])) $e[] = "$n: projeto não encontrado";
                    if ($c === 'forn' && !isset($cat['forn'][$v])) $e[] = "$n: cadastro não encontrado (atualize os títulos se acabou de cadastrar)";
                    break;
                case 'deps':
                    $it = (array)($op['itens'] ?? []); $soma = 0.0;
                    foreach ($it as $d) { if (!isset($cat['deps'][(string)($d[0] ?? '')])) $e[] = "$n: departamento " . ($d[0] ?? '?') . ' não encontrado'; $soma += (float)($d[1] ?? 0); }
                    if (!$it) $e[] = "$n: escolha pelo menos um departamento";
                    elseif (abs($soma - 100) > 0.01) $e[] = "$n: os percentuais somam " . rtrim(rtrim(number_format($soma, 2, ',', ''), '0'), ',') . '% (precisa ser 100%)';
                    break;
                case 'valor':
                    $m = (string)($op['modo'] ?? '');
                    if (!in_array($m, ['fixo', 'percent', 'somar'], true)) $e[] = "$n: escolha a regra do valor";
                    if (!is_numeric($op['n'] ?? null)) $e[] = "$n: informe o número";
                    break;
                case 'pix':
                    if ($tipos !== 'P') $e[] = "$n: forma de pagamento Pix só existe em contas a pagar";
                    break;
            }
        }
        return $e;
    }

    /** ordena os títulos para a sequência. $ordem = venc|prev|emis|id|forn|tabela (tabela = como vieram) */
    public static function ordenar(array $titulos, string $ordem, array $nomesForn = []): array
    {
        if ($ordem === 'tabela' || $ordem === '') return array_values($titulos);
        $k = fn(array $t) => match ($ordem) {
            'venc', 'prev', 'emis' => (AoData::ler((string)$t[$ordem]) ?? new DateTimeImmutable('2999-12-31'))->format('Ymd'),
            'forn' => mb_strtolower((string)($nomesForn[(string)$t['forn']] ?? $t['forn'])) . '|' . (AoData::ler((string)$t['venc']) ?? new DateTimeImmutable('2999-12-31'))->format('Ymd'),
            default => '',
        };
        $l = array_values($titulos);
        usort($l, fn($a, $b) => [$k($a), $a['id']] <=> [$k($b), $b['id']]);
        return $l;
    }

    /** posição de cada título na sequência (reinicia por fornecedor se pedido). @return array<int, array{pos:int,total:int}> por id */
    public static function posicoes(array $ordenados, string $reinicia): array
    {
        $grupos = [];
        foreach ($ordenados as $t) $grupos[$reinicia === 'forn' ? (string)$t['forn'] : '*'][] = (int)$t['id'];
        $out = [];
        foreach ($grupos as $ids) foreach ($ids as $i => $id) $out[$id] = ['pos' => $i, 'total' => count($ids)];
        return $out;
    }

    private static function render(string $tpl, array $t, int $pos, int $total, array $opts, array $cat, string $campo): string
    {
        $seq = $opts['seq'] ?? []; $sd = $opts['seqdata'] ?? [];
        return preg_replace_callback('/\{(\w+)(?::([^}]*))?\}/u', function ($m) use ($t, $pos, $total, $seq, $sd, $cat, $campo) {
            $k = strtolower($m[1]); $arg = $m[2] ?? '';
            switch ($k) {
                case 'atual': return (string)($t[$campo] ?? '');
                case 'seq': $dig = $arg !== '' ? (int)$arg : (int)($seq['digitos'] ?? 3);
                    $v = (int)($seq['inicio'] ?? 1) + $pos * (int)($seq['passo'] ?? 1);
                    return str_pad((string)$v, max(1, $dig), '0', STR_PAD_LEFT);
                case 'n': return (string)((int)($seq['inicio'] ?? 1) + $pos * (int)($seq['passo'] ?? 1));
                case 'total': return $arg !== '' ? str_pad((string)$total, (int)$arg, '0', STR_PAD_LEFT) : (string)$total;
                case 'data':
                    $d0 = AoData::ler((string)($sd['inicio'] ?? '')); if (!$d0) return '{data?}';
                    $p = $pos * (int)($sd['passo'] ?? 1);
                    $d = ($sd['unidade'] ?? 'm') === 'd' ? $d0->modify("+$p days") : AoData::somarMeses($d0, $p);
                    return AoData::fmt($d, $arg !== '' ? $arg : (string)($sd['fmt'] ?? 'dd/mm/aaaa'));
                case 'venc': case 'prev': case 'emis': case 'reg':
                    $d = AoData::ler((string)$t[$k]); return $d ? AoData::fmt($d, $arg) : '';
                case 'forn': return (string)($cat['forn'][(string)$t['forn']] ?? '');
                case 'doc': case 'nf': case 'parc': case 'ci': return (string)$t[$k];
                case 'id': return (string)$t['id'];
                case 'valor': return number_format((float)$t['valor'], 2, ',', '.');
                case 'cat': return (string)$t['cat'];
            }
            return $m[0];
        }, $tpl);
    }

    /**
     * Prévia completa: ordena, numera (títulos cancelados/bloqueados não ocupam número da sequência) e calcula cada um.
     * @return list<array{t: array, pos: int, total: int, r: array}> na ordem da sequência
     */
    public static function preparar(array $titulos, array $ops, array $opts, array $cat, array $fixosPorId = []): array
    {
        $ord = self::ordenar($titulos, (string)($opts['ordem'] ?? 'venc'), $cat['forn'] ?? []);
        $vivos = array_values(array_filter($ord, fn($t) => !AoTitulo::cancelado($t) && empty($t['bloq'])));
        $pos = self::posicoes($vivos, (string)($opts['seq']['reinicia'] ?? 'nada'));
        $out = [];
        foreach ($ord as $t) {
            $p = $pos[(int)$t['id']] ?? ['pos' => 0, 'total' => count($vivos)];
            $out[] = ['t' => $t, 'pos' => $p['pos'], 'total' => $p['total'], 'r' => self::calcular($t, $ops, $p['pos'], $p['total'], $opts, $cat, $fixosPorId[$t['tipo'] . $t['id']] ?? [])];
        }
        return $out;
    }

    /** "1/3" → "001/003" (formato que o Omie usa e devolve) */
    public static function normParcela(string $s): string
    {
        return preg_match('/^\s*(\d{1,3})\s*\/\s*(\d{1,3})\s*$/', $s, $m) ? sprintf('%03d/%03d', $m[1], $m[2]) : trim($s);
    }

    /**
     * Calcula o resultado de UM título.
     * @param array $fixos valores fixos por campo (desfazer uma rodada: volta ao "antes" gravado)
     * @return array{novo: array, avisos: string[], bloqueios: array<string,string>, extra: array}
     */
    public static function calcular(array $t, array $ops, int $pos, int $total, array $opts, array $cat, array $fixos = []): array
    {
        $novo = []; $avisos = []; $bloq = []; $extra = [];
        $tipo = (string)$t['tipo'];
        if (AoTitulo::cancelado($t)) return ['novo' => [], 'avisos' => [], 'bloqueios' => ['*' => 'título cancelado no Omie — não pode ser alterado'], 'extra' => []];
        if (!empty($t['bloq'])) return ['novo' => [], 'avisos' => [], 'bloqueios' => ['*' => 'título bloqueado para alteração no Omie (integração/CNAB) — libere por lá antes'], 'extra' => []];
        if ($fixos) $ops = array_map(fn($c) => ['campo' => $c, '_fixo' => true], array_keys($fixos));
        foreach ($ops as $op) {
            $c = (string)$op['campo']; $def = AoTitulo::CAMPOS[$c]; $rot = AoTitulo::rotulo($c, $tipo);
            if ($c === 'pix' && $tipo !== 'P') continue;
            $antes = AoTitulo::valor($t, $c); $v = $antes;
            if (!empty($op['_fixo'])) { $v = $fixos[$c]; if ($c === 'pix') $op = ['campo' => 'pix']; else goto pronto; }
            switch ($def[1]) {
                case 'texto':
                    $r = trim(self::render((string)($op['texto'] ?? ''), $t, $pos, $total, $opts, $cat, $c));
                    $at = (string)$t[$c]; $sep = (string)($op['sep'] ?? ' ');
                    $m = (string)$op['modo'];
                    if ($c === 'obs') {
                        // o Omie acrescenta como LINHA NOVA (separador "|") e não repete uma linha igual à última
                        $linhas = $at === '' ? [] : explode('|', $at);
                        $v = ($r === '' || end($linhas) === $r) ? $at : ($at === '' ? $r : $at . '|' . $r);
                        if ($r !== '' && $v === $at) $avisos[] = 'a observação já termina com esse texto';
                        else $extra['obs_add'] = $r;   // o pedido leva só a linha nova
                        break;
                    }
                    if ($m === 'substituir') $v = $r;
                    elseif ($m === 'antes') $v = $at === '' ? $r : $r . $sep . $at;
                    elseif ($m === 'depois') $v = $at === '' ? $r : $at . $sep . $r;
                    elseif ($m === 'trocar') { $p = (string)$op['procurar']; $v = mb_stripos($at, $p) === false ? $at : str_ireplace($p, $r, $at); if ($v === $at) $avisos[] = "\"$p\" não aparece em $rot"; }
                    $v = trim((string)$v);
                    if ($c === 'parc') $v = self::normParcela($v);
                    break;
                case 'data':
                    $m = (string)$op['modo']; $d = null; $aj = (string)($op['ajuste'] ?? 'manter');
                    $base = AoData::ler((string)$t[$c]);
                    if ($m === 'somar') {
                        if (!$base) { $bloq[$c] = 'está vazia no título — não há data para somar'; continue 2; }
                        $n = (int)$op['n'];
                        $d = !empty($op['uteis']) ? AoData::somarUteis($base, $n) : AoData::ajustar($base->modify(($n >= 0 ? '+' : '') . $n . ' days'), $aj);
                    } elseif ($m === 'fixa') $d = AoData::ajustar(AoData::ler((string)$op['data']), $aj);
                    elseif ($m === 'dia') {
                        $ref = $base ?? AoData::ler((string)$t['venc']); if (!$ref) { $bloq[$c] = 'está vazia no título'; continue 2; }
                        $d = AoData::ajustar($ref->setDate((int)$ref->format('Y'), (int)$ref->format('m'), min((int)$op['dia'], (int)$ref->format('t'))), $aj);
                    } elseif ($m === 'copiar') {
                        $src = AoData::ler((string)$t[(string)$op['de']]); if (!$src) { $bloq[$c] = AoTitulo::rotulo((string)$op['de']) . ' está vazia no título'; continue 2; }
                        $n = (int)($op['n'] ?? 0); $d = AoData::ajustar($n ? $src->modify(($n >= 0 ? '+' : '') . $n . ' days') : $src, $aj);
                    }
                    $v = $d ? AoData::br($d) : $antes;
                    if ($d && !AoData::util($d)) $avisos[] = "$rot cai em " . (((int)$d->format('N') >= 6) ? 'fim de semana' : 'feriado') . ' (' . $v . ')';
                    break;
                case 'cadastro':
                    $v = in_array($c, ['cc', 'forn', 'proj'], true) ? (int)$op['valor'] : (string)$op['valor'];
                    if ($c === 'cat' && (int)($t['ncat'] ?? 1) > 1) { $bloq[$c] = 'título rateado entre ' . $t['ncat'] . ' categorias — altere no Omie'; continue 2; }
                    break;
                case 'deps':
                    $v = array_map(fn($d) => [(string)$d[0], round((float)$d[1], 4)], (array)$op['itens']);
                    break;
                case 'valor':
                    $n = (float)$op['n']; $b = (float)$t['valor'];
                    $v = round(match ((string)$op['modo']) { 'fixo' => $n, 'percent' => $b * (1 + $n / 100), default => $b + $n }, 2);
                    if ($v <= 0) { $bloq[$c] = 'o valor ficaria zero ou negativo'; continue 2; }
                    break;
                case 'pix':
                    $fc = $cat['pix'][(string)$t['forn']] ?? null;
                    if (!$fc || trim((string)($fc['chave'] ?? '')) === '') { $bloq[$c] = 'o cadastro do fornecedor no Omie não tem chave Pix'; continue 2; }
                    $v = 'TRA/01.3';
                    $extra['cnab'] = ['codigo_forma_pagamento' => 'TRA', 'finalidade_transferencia' => '01.3', 'pix_qrcode' => trim((string)$fc['chave']), 'banco_transferencia' => '', 'agencia_transferencia' => '', 'conta_corrente_transferencia' => '',
                                      'cpf_cnpj_transferencia' => (string)($fc['doc'] ?? ''), 'nome_transferencia' => mb_substr((string)($fc['nome'] ?? ''), 0, 60)];
                    break;
            }
            pronto:
            if (AoTitulo::igual($c, $antes, $v)) continue;
            // o Omie ignora texto vazio e rateio vazio: não dá para "limpar" (nem desfazer de volta para vazio) pela API
            if (in_array($def[1], ['texto', 'deps'], true) && (is_array($v) ? !$v : trim((string)$v) === '')) { $bloq[$c] = 'ficaria vazio — o Omie ignora valor vazio (para limpar o campo, use a tela do Omie)'; continue; }
            if (in_array($c, ['cat', 'cc', 'forn'], true) && !$v) { $bloq[$c] = 'ficaria vazio — o Omie não aceita'; continue; }
            // regras do Omie para título pago/recebido e boleto gerado (testes de 17/11/2026)
            if ($def[5] && AoTitulo::pago($t)) { $bloq[$c] = 'título já ' . ($tipo === 'R' ? 'recebido' : 'pago') . ' — o Omie não altera este campo depois da baixa (aceita o pedido e ignora)'; continue; }
            if ($def[5] && !empty($t['bol'])) { $bloq[$c] = 'boleto já gerado — o Omie trava este campo'; continue; }
            if ($def[4] > 0 && mb_strlen((string)$v) > $def[4]) { $bloq[$c] = 'ficaria com ' . mb_strlen((string)$v) . " caracteres (máximo do Omie: {$def[4]}): \"$v\""; continue; }
            if ($c === 'venc' || $c === 'emis') {
                $ve = AoData::ler($c === 'venc' ? (string)$v : (string)($novo['venc'] ?? $t['venc'])); $em = AoData::ler($c === 'emis' ? (string)$v : (string)($novo['emis'] ?? $t['emis']));
                if ($ve && $em && $ve < $em) $avisos[] = 'vencimento ficaria antes da emissão';
            }
            $novo[$c] = $v;
        }
        return ['novo' => $novo, 'avisos' => $avisos, 'bloqueios' => $bloq, 'extra' => $extra];
    }

    /** resumo legível das alterações (histórico) */
    public static function resumo(array $ops, array $nomes = []): string
    {
        $p = [];
        foreach ($ops as $op) {
            $c = (string)($op['campo'] ?? ''); $r = AoTitulo::rotulo($c, '');
            $p[] = match (AoTitulo::CAMPOS[$c][1] ?? '') {
                'texto' => $r . ': ' . (['substituir' => 'substituir por', 'antes' => 'inserir antes', 'depois' => 'inserir depois', 'trocar' => 'trocar "' . ($op['procurar'] ?? '') . '" por'][$op['modo'] ?? ''] ?? '') . ' "' . ($op['texto'] ?? '') . '"',
                'data' => $r . ': ' . match ($op['modo'] ?? '') { 'somar' => ((int)$op['n'] >= 0 ? '+' : '') . (int)$op['n'] . (!empty($op['uteis']) ? ' dias úteis' : ' dias'), 'fixa' => 'data fixa ' . $op['data'], 'dia' => 'dia ' . $op['dia'] . ' do mês', 'copiar' => 'igual a ' . AoTitulo::rotulo((string)$op['de']) . (!empty($op['n']) ? ' ' . ((int)$op['n'] >= 0 ? '+' : '') . (int)$op['n'] . ' dias' : ''), default => '' },
                'valor' => 'Valor: ' . match ($op['modo'] ?? '') { 'fixo' => 'fixo ' . $op['n'], 'percent' => $op['n'] . '%', default => '+' . $op['n'] },
                'deps' => 'Departamentos: ' . count((array)($op['itens'] ?? [])) . ' item(ns)',
                'pix' => 'Forma de pagamento: Pix (chave do cadastro)',
                default => $r . ': ' . AoTitulo::mostrar($c, in_array($c, ['cc', 'forn', 'proj'], true) ? (int)($op['valor'] ?? 0) : (string)($op['valor'] ?? ''), $nomes),
            };
        }
        return implode(' · ', $p);
    }
}
