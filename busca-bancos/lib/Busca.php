<?php
/* =====================================================================
   busca-bancos/lib/Busca.php — cobertura dos extratos e a busca unificada.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/Importador.php';

final class BbCobertura
{
    /** contas com dados básicos, indexadas por id */
    public static function contas(PDO $pdo): array
    {
        $out = [];
        foreach ($pdo->query('SELECT c.*, (SELECT COUNT(*) FROM bb_lanc l WHERE l.conta_id = c.id) AS n_lanc FROM bb_contas c ORDER BY c.empresa, c.banco, c.apelido') as $c) $out[(int)$c['id']] = $c;
        return $out;
    }

    /** períodos cobertos por conta, já unidos: [conta_id => [[de, ate], ...]] */
    public static function intervalos(PDO $pdo): array
    {
        $out = [];
        foreach ($pdo->query('SELECT conta_id, periodo_de, periodo_ate FROM bb_arquivos ORDER BY conta_id, periodo_de, periodo_ate') as $a) {
            if ($a['periodo_ate'] < $a['periodo_de']) continue;   // gerado no 1º dia do período: não cobre nada ainda
            $cid = (int)$a['conta_id']; $n = count($out[$cid] ?? []);
            if ($n && $a['periodo_de'] <= date('Y-m-d', strtotime($out[$cid][$n - 1][1] . ' +1 day'))) $out[$cid][$n - 1][1] = max($out[$cid][$n - 1][1], $a['periodo_ate']);
            else $out[$cid][] = [$a['periodo_de'], $a['periodo_ate']];
        }
        return $out;
    }

    /** trechos de [de, ate] que NÃO estão cobertos */
    public static function buracos(array $intervalos, string $de, string $ate): array
    {
        $faltas = []; $cur = $de;
        foreach ($intervalos as [$a, $b]) {
            if ($b < $cur) continue;
            if ($a > $ate) break;
            if ($a > $cur) $faltas[] = [$cur, date('Y-m-d', strtotime($a . ' -1 day'))];
            $cur = date('Y-m-d', strtotime($b . ' +1 day'));
            if ($cur > $ate) break;
        }
        if ($cur <= $ate) $faltas[] = [$cur, $ate];
        return $faltas;
    }

    /** avisos de falta de dados para o período pedido: [['conta'=>…, 'faltas'=>[[de,ate]], 'tudo'=>bool]] */
    public static function avisos(PDO $pdo, array $contas, ?string $de, ?string $ate): array
    {
        $int = self::intervalos($pdo); $out = []; $hoje = date('Y-m-d', strtotime('-1 day'));   // hoje ainda não fechou em nenhum banco
        foreach ($contas as $cid => $c) {
            $i = $int[$cid] ?? [];
            if (!$i) { $out[] = ['conta' => $c, 'faltas' => [], 'tudo' => true]; continue; }
            $d = $de ?: $i[0][0]; $a = min($ate ?: $hoje, $hoje);
            if ($d > $a) continue;
            $f = self::buracos($i, $d, $a);
            if ($f) $out[] = ['conta' => $c, 'faltas' => $f, 'tudo' => count($f) === 1 && $f[0][0] === $d && $f[0][1] === $a];
        }
        return $out;
    }

    /** grade conta × mês: ['meses'=>['2026-01',…], 'linhas'=>[conta_id=>[mes=>['st'=>cheio|parcial|falta|fora, 'n'=>int, 'dias'=>txt]]]] */
    public static function grade(PDO $pdo, array $contas): array
    {
        $int = self::intervalos($pdo);
        if (!$int) return ['meses' => [], 'linhas' => []];
        $sil = self::silencios($pdo, $int);
        $min = min(array_map(fn($i) => $i[0][0], $int)); $hoje = date('Y-m-d', strtotime('-1 day'));
        $meses = []; for ($m = substr($min, 0, 7); $m <= substr($hoje, 0, 7); $m = date('Y-m', strtotime($m . '-01 +1 month'))) $meses[] = $m;
        $n = [];
        foreach ($pdo->query("SELECT conta_id, DATE_FORMAT(data, '%Y-%m') m, COUNT(*) n FROM bb_lanc GROUP BY conta_id, m") as $r) $n[(int)$r['conta_id']][$r['m']] = (int)$r['n'];
        $linhas = [];
        foreach ($contas as $cid => $c) {
            $i = $int[$cid] ?? []; $ini = $i ? $i[0][0] : null; $fim = $i ? end($i)[1] : null;
            foreach ($meses as $m) {
                $a = $m . '-01'; $b = min(date('Y-m-t', strtotime($a)), $hoje);
                $f = $i ? self::buracos($i, $a, $b) : [[$a, $b]];
                $st = !$f ? 'cheio' : ((count($f) === 1 && $f[0][0] === $a && $f[0][1] === $b) ? ($ini !== null && $a > $ini && $b < $fim ? 'falta' : 'fora') : 'parcial');
                $dias = implode(', ', array_map(fn($x) => $x[0] === $x[1] ? substr(BbTexto::dataBr($x[0]), 0, 5) : substr(BbTexto::dataBr($x[0]), 0, 5) . ' a ' . substr(BbTexto::dataBr($x[1]), 0, 5), $f));
                $sv = array_filter($sil[$cid]['trechos'] ?? [], fn($t) => $t['ate'] >= $a && $t['de'] <= $b);
                $silTxt = implode(', ', array_map(fn($t) => substr(BbTexto::dataBr(max($t['de'], $a)), 0, 5) . ' a ' . substr(BbTexto::dataBr(min($t['ate'], $b)), 0, 5), $sv));
                $linhas[$cid][$m] = ['st' => $st, 'n' => $n[$cid][$m] ?? 0, 'dias' => $dias, 'sil' => $silTxt];
            }
        }
        return ['meses' => $meses, 'linhas' => $linhas];
    }

    /**
     * Conferência pelos saldos: saldo final do extrato anterior + lançamentos do intervalo = saldo final deste.
     * Só compara extratos encostados (sem buraco entre eles). [conta_id => [['de','ate','esperado','informado','dif','arquivo'], …]]
     */
    public static function saldos(PDO $pdo): array
    {
        $out = []; $ant = [];
        $soma = $pdo->prepare('SELECT COALESCE(SUM(valor), 0) FROM bb_lanc WHERE conta_id = ? AND data > ? AND data <= ?');
        $q = $pdo->query('SELECT id, conta_id, nome, periodo_de, periodo_ate, saldo_final, COALESCE(saldo_data, periodo_ate) AS ref FROM bb_arquivos WHERE saldo_final IS NOT NULL ORDER BY conta_id, ref, id');
        foreach ($q as $a) {
            $cid = (int)$a['conta_id']; $p = $ant[$cid] ?? null;
            if ($p && $a['ref'] > $p['ref'] && $a['periodo_de'] <= date('Y-m-d', strtotime($p['periodo_ate'] . ' +1 day'))) {
                $soma->execute([$cid, $p['ref'], $a['ref']]);
                $esp = round((float)$p['saldo_final'] + (float)$soma->fetchColumn(), 2);
                $out[$cid][] = ['de' => $p['ref'], 'ate' => $a['ref'], 'esperado' => $esp, 'informado' => (float)$a['saldo_final'], 'dif' => round((float)$a['saldo_final'] - $esp, 2), 'arquivo' => $a['nome']];
            }
            if (!$p || $a['ref'] >= $p['ref']) $ant[$cid] = $a;
        }
        return $out;
    }

    /** feriados nacionais em que banco não compensa (fixos + Carnaval, Sexta-feira Santa e Corpus Christi) */
    public static function feriados(int $ano): array
    {
        static $cache = [];
        if (isset($cache[$ano])) return $cache[$ano];
        $f = array_map(fn($d) => "$ano-$d", ['01-01', '04-21', '05-01', '09-07', '10-12', '11-02', '11-15', '11-20', '12-25']);
        $a = $ano % 19; $b = intdiv($ano, 100); $c = $ano % 100; $d = intdiv($b, 4); $e = $b % 4; $g = intdiv(8 * $b + 13, 25);
        $h = (19 * $a + $b - $d - $g + 15) % 30; $j = intdiv($c, 4); $k = $c % 4; $l = (2 * $e + 2 * $j - $k - $h + 32) % 7; $m = intdiv($a + 11 * $h + 19 * $l, 433);
        $mes = intdiv($h + $l - 7 * $m + 90, 25); $dia = ($h + $l - 7 * $m + 33 * $mes + 19) % 32;
        $pascoa = strtotime(sprintf('%04d-%02d-%02d', $ano, $mes, $dia));
        foreach ([-48, -47, -2, 60] as $off) $f[] = date('Y-m-d', strtotime("$off days", $pascoa));
        return $cache[$ano] = array_flip($f);
    }
    public static function util(string $d): bool
    {
        return (int)date('N', strtotime($d)) <= 5 && !isset(self::feriados((int)substr($d, 0, 4))[$d]);
    }

    /**
     * Dias úteis seguidos SEM NENHUM lançamento dentro do período que os extratos dizem cobrir, quando isso é fora do normal
     * para a conta. O normal de cada conta = em quantos % dos dias úteis cobertos ela tem movimento (p, medido fora do próprio
     * trecho). Uma sequência de k dias úteis vazios é suspeita quando a chance de acontecer por acaso, (1-p)^k, fica abaixo de 1% (e k >= 3).
     * Pega o arquivo baixado antes do fim do período que ele declara, mesmo quando o banco não informa a data de geração.
     * [conta_id => ['p' => 0..1, 'k' => mínimo de dias úteis, 'trechos' => [['de','ate','uteis','fim'=>bool], …]]]
     */
    public static function silencios(PDO $pdo, ?array $int = null): array
    {
        $int ??= self::intervalos($pdo);
        $datas = [];
        foreach ($pdo->query('SELECT DISTINCT conta_id, data FROM bb_lanc') as $r) $datas[(int)$r['conta_id']][$r['data']] = 1;
        $out = [];
        foreach ($int as $cid => $is) {
            $tem = $datas[$cid] ?? [];
            $uteis = 0; $ativos = 0;
            foreach ($is as [$a, $b]) for ($d = $a; $d <= $b; $d = date('Y-m-d', strtotime($d . ' +1 day'))) if (self::util($d)) { $uteis++; if (isset($tem[$d])) $ativos++; }
            if ($uteis < 20 || $ativos < 3) continue;   // pouca história para saber o que é normal
            $p = $ativos / $uteis;
            $kDe = fn(float $q) => $q >= 0.999 ? 3 : max(3, (int)ceil(log(0.01) / log(1 - $q)));
            $k = $kDe($p);
            $trechos = [];
            foreach ($is as [$a, $b]) {
                $ini = null; $n = 0;
                for ($d = $a; ; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                    $acabou = $d > $b;
                    if (!$acabou && !isset($tem[$d])) { $ini ??= $d; if (self::util($d)) $n++; continue; }
                    // o normal é medido SEM o próprio trecho (senão um buraco grande baixa a média e se esconde)
                    if ($ini !== null && $n >= 3 && $uteis - $n >= 20 && $n >= $kDe($ativos / ($uteis - $n))) $trechos[] = ['de' => $ini, 'ate' => date('Y-m-d', strtotime($d . ' -1 day')), 'uteis' => $n, 'fim' => $acabou, 'inicio' => $ini === $is[0][0]];
                    $ini = null; $n = 0;
                    if ($acabou) break;
                }
            }
            $out[$cid] = ['p' => $p, 'k' => $k, 'trechos' => $trechos];
        }
        return $out;
    }

    /** trechos suspeitos que encostam em [de, ate] (null = tudo) */
    public static function silenciosNoPeriodo(array $sil, array $contas, ?string $de, ?string $ate): array
    {
        $out = [];
        foreach ($contas as $cid => $c) foreach ($sil[$cid]['trechos'] ?? [] as $t)
            if ((!$de || $t['ate'] >= $de) && (!$ate || $t['de'] <= $ate)) $out[] = $t + ['conta' => $c, 'p' => $sil[$cid]['p']];
        return $out;
    }

    /** acrescenta aos resultados de uma importação o aviso de dias sem lançamento fora do normal no período de cada arquivo */
    public static function anotarSilencios(PDO $pdo, array $resultados): array
    {
        if (!$resultados) return $resultados;
        $sil = self::silencios($pdo);
        foreach ($resultados as &$r) {
            if (!isset($r['conta']['id'], $r['de'], $r['ate']) || $r['ate'] < $r['de']) continue;
            $cid = (int)$r['conta']['id'];
            foreach ($sil[$cid]['trechos'] ?? [] as $t) if ($t['ate'] >= $r['de'] && $t['de'] <= $r['ate'])
                $r['avisos'][] = self::textoSilencio($t, $sil[$cid]['p']);
        }
        return $resultados;
    }
    public static function textoSilencio(array $t, float $p): string
    {
        return 'Nenhum lançamento de ' . BbTexto::dataBr($t['de']) . ' a ' . BbTexto::dataBr($t['ate']) . ' (' . $t['uteis'] . ' dias úteis), embora o extrato diga cobrir esses dias — '
            . 'esta conta costuma ter movimento em ' . (int)round(100 * $p) . '% dos dias úteis. '
            . self::motivoSilencio($t);
    }
    public static function motivoSilencio(array $t): string
    {
        if (!empty($t['inicio'])) return 'É antes do primeiro lançamento da conta: confira se ela já existia (pode ter sido aberta depois) ou se o extrato veio incompleto.';
        if (!empty($t['fim'])) return 'Fica no fim do período coberto: o extrato pode ter sido baixado antes do fim.';
        return 'Confira no banco se o extrato veio completo (ou se foi exportado com o período errado).';
    }

    /** o que cada conta informa: % de lançamentos com CPF/CNPJ, com nome e com nº de referência */
    public static function capacidades(PDO $pdo): array
    {
        $out = [];
        foreach ($pdo->query("SELECT conta_id, COUNT(*) n, SUM(doc <> '') d, SUM(contraparte <> '') c, SUM(checknum <> '') r FROM bb_lanc WHERE categoria <> 'tarifa' GROUP BY conta_id") as $r) {
            $n = max(1, (int)$r['n']);
            $out[(int)$r['conta_id']] = ['n' => (int)$r['n'], 'doc' => (int)round(100 * $r['d'] / $n), 'nome' => (int)round(100 * $r['c'] / $n), 'ref' => (int)round(100 * $r['r'] / $n)];
        }
        return $out;
    }
}

final class BbBusca
{
    private const VAZIAS = ['de', 'da', 'do', 'das', 'dos', 'e', 'a', 'o'];
    /** texto pesquisável = o que veio do banco + a anotação feita na ferramenta */
    private const TEXTO = "CONCAT(l.busca, ' ', l.nota_busca)";

    /**
     * Entende a caixa única: ['valores'=>[float], 'docs'=>[dígitos], 'datas'=>[iso], 'refs'=>[dígitos], 'palavras'=>[norm], 'sinal'=>-1|0|1]
     *   1500 · 1.500,00 · R$1500 → valor      11/14 dígitos válidos → CPF/CNPJ
     *   15/03 · 15/03/2026       → data       5 a 10 dígitos → valor OU nº no texto
     */
    public static function interpretar(string $q, ?int $anoPadrao = null): array
    {
        $r = ['valores' => [], 'cheios' => [], 'docs' => [], 'datas' => [], 'refs' => [], 'palavras' => [], 'sinal' => 0];   // cheios = índices de valores digitados sem centavos
        $q = trim(preg_replace('/r\$\s*/iu', 'R$', $q) ?? '');
        // CPF/CNPJ digitado com pontuação
        $q = preg_replace_callback('/\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}|\d{3}\.\d{3}\.\d{3}-\d{2}/', fn($m) => preg_replace('/\D+/', '', $m[0]), $q) ?? $q;
        foreach (preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY) as $t) {
            if (preg_match('/^(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?$/', $t, $m)) {
                $ano = isset($m[3]) ? (int)(strlen($m[3]) === 2 ? '20' . $m[3] : $m[3]) : ($anoPadrao ?? (int)date('Y'));
                if (checkdate((int)$m[2], (int)$m[1], $ano)) { $r['datas'][] = sprintf('%04d-%02d-%02d', $ano, $m[2], $m[1]); continue; }
            }
            $dig = preg_replace('/\D+/', '', $t);
            if ($dig === $t && (strlen($t) === 11 || strlen($t) === 14) && BbTexto::docValido($t)) { $r['docs'][] = $t; continue; }
            if (preg_match('/^([+-])?(R\$)?(-)?(\d{1,3}(?:\.\d{3})+|\d+)(?:,(\d{1,2}))?$/', $t, $m)) {
                $int = str_replace('.', '', $m[4]); $temMoeda = ($m[2] ?? '') !== '' || isset($m[5]) || str_contains($m[4], '.');
                $v = (float)($int . '.' . str_pad($m[5] ?? '0', 2, '0'));
                if (($m[1] ?? '') === '-' || ($m[3] ?? '') === '-') $r['sinal'] = -1; elseif (($m[1] ?? '') === '+') $r['sinal'] = 1;
                if ($temMoeda || strlen($int) <= 4) { if (!isset($m[5])) $r['cheios'][] = count($r['valores']); $r['valores'][] = $v; continue; }
                if (strlen($int) <= 10) { $r['refs'][] = $int; continue; }
            }
            if (preg_match('/^\d+\.\d{1,2}$/', $t)) { $r['valores'][] = (float)$t; continue; }
            foreach (explode(' ', BbTexto::norm($t)) as $p) if ($p !== '' && !in_array($p, self::VAZIAS, true) && (strlen($p) > 1 || ctype_digit($p))) $r['palavras'][] = $p;
        }
        $r['palavras'] = array_values(array_unique($r['palavras']));
        return $r;
    }

    /** frase "Entendi: …" para mostrar embaixo da caixa */
    public static function explicar(array $i): array
    {
        $p = [];
        foreach ($i['valores'] as $k => $v) $p[] = in_array($k, $i['cheios'] ?? [], true) ? 'valor de ' . BbTexto::brl($v) . ' a ' . BbTexto::brl($v + 0.99) : 'valor ' . BbTexto::brl($v);
        foreach ($i['docs'] as $d) $p[] = (strlen($d) === 11 ? 'CPF ' : 'CNPJ ') . BbTexto::docFmt($d);
        foreach ($i['datas'] as $d) $p[] = 'data ' . BbTexto::dataBr($d);
        foreach ($i['refs'] as $d) $p[] = strlen($d) <= 7 ? 'valor de ' . BbTexto::brl((float)$d) . ' a ' . BbTexto::brl((float)$d + 0.99) . ' (ou nº ' . $d . ')' : 'nº ' . $d . ' (ou valor ' . BbTexto::brl((float)$d) . ')';
        if ($i['palavras']) $p[] = 'texto "' . implode(' ', $i['palavras']) . '"';
        return $p;
    }

    /**
     * Monta o WHERE. $f: q, de, ate, empresas[], contas[], tipo (E|S|''), vmin, vmax, tolv, told, ocultar_internas, ocultar_anuladas, origem, pendentes, parcial
     * Devolve ['where'=>sql, 'args'=>[], 'ordem'=>sql, 'interp'=>…, 'ocultos'=>[where,args] (o mesmo filtro, só os escondidos)]
     */
    public static function montar(array $f): array
    {
        $i = self::interpretar((string)($f['q'] ?? ''), !empty($f['de']) ? (int)substr((string)$f['de'], 0, 4) : null);
        $w = []; $a = [];
        $tolv = max(0.0, (float)($f['tolv'] ?? 0)); $told = max(0, (int)($f['told'] ?? 0));
        // valor digitado sem centavos (2394, R$ 2.394) vale para qualquer centavo: 2394,00 até 2394,99
        foreach ($i['valores'] as $k => $v) { $cheio = in_array($k, $i['cheios'] ?? [], true); $w[] = 'ABS(l.valor) BETWEEN ? AND ?'; $a[] = $v - $tolv - 0.004; $a[] = $v + ($cheio ? 0.99 : 0) + $tolv + 0.004; }
        foreach ($i['refs'] as $d) { $w[] = '(ABS(l.valor) BETWEEN ? AND ? OR ' . self::TEXTO . ' LIKE ?)'; $a[] = (float)$d - $tolv - 0.004; $a[] = (float)$d + 0.99 + $tolv + 0.004; $a[] = '%' . $d . '%'; }
        foreach ($i['docs'] as $d) { $w[] = '(l.doc = ? OR ' . self::TEXTO . ' LIKE ?)'; $a[] = $d; $a[] = '%' . $d . '%'; }
        foreach ($i['datas'] as $d) { $w[] = 'l.data BETWEEN ? AND ?'; $a[] = date('Y-m-d', strtotime("$d -$told days")); $a[] = date('Y-m-d', strtotime("$d +$told days")); }
        $ordem = 'l.data DESC, l.id DESC';
        if ($i['palavras']) {
            $conds = array_fill(0, count($i['palavras']), self::TEXTO . ' LIKE ?'); $likes = array_map(fn($p) => '%' . $p . '%', $i['palavras']);
            if (empty($f['parcial']) || count($conds) === 1) { $w[] = '(' . implode(' AND ', $conds) . ')'; $a = array_merge($a, $likes); }
            else {
                // correspondência parcial: pelo menos metade das palavras (nomes vêm cortados/mascarados dos bancos)
                $soma = '(' . implode(' + ', array_map(fn($c) => "($c)", $conds)) . ')';
                $w[] = $soma . ' >= ' . (int)ceil(count($conds) / 2); $a = array_merge($a, $likes);
                $ordem = $soma . ' DESC, ' . $ordem; $ordemArgs = $likes;
            }
        }
        if ($i['sinal'] < 0) $w[] = 'l.valor < 0'; elseif ($i['sinal'] > 0) $w[] = 'l.valor > 0';
        if (!$i['datas']) {
            if (!empty($f['de'])) { $w[] = 'l.data >= ?'; $a[] = $f['de']; }
            if (!empty($f['ate'])) { $w[] = 'l.data <= ?'; $a[] = $f['ate']; }
        }
        if (!empty($f['contas'])) { $w[] = 'l.conta_id IN (' . implode(',', array_map('intval', (array)$f['contas'])) . ')'; }
        if (!empty($f['empresas'])) { $w[] = 'c.empresa IN (' . implode(',', array_fill(0, count((array)$f['empresas']), '?')) . ')'; $a = array_merge($a, array_values((array)$f['empresas'])); }
        if (($f['tipo'] ?? '') === 'E') $w[] = 'l.valor > 0'; elseif (($f['tipo'] ?? '') === 'S') $w[] = 'l.valor < 0';
        if (($f['vmin'] ?? '') !== '') { $w[] = 'ABS(l.valor) >= ?'; $a[] = (float)$f['vmin']; }
        if (($f['vmax'] ?? '') !== '') { $w[] = 'ABS(l.valor) <= ?'; $a[] = (float)$f['vmax']; }
        if (in_array($f['origem'] ?? '', ['upload', 'drive', 'pdf', 'omie'], true)) { $w[] = 'ar.origem = ?'; $a[] = $f['origem']; }
        if (!empty($f['pendentes'])) $w[] = 'l.conferido = 0';
        $vis = [];
        if (!empty($f['ocultar_internas'])) $vis[] = 'l.interna = 0';   // padrão: mostra tudo (marcação é automática; só some se a pessoa pedir)
        if (!empty($f['ocultar_anuladas'])) $vis[] = 'l.anulada = 0';
        $base = $w ? implode(' AND ', $w) : '1=1';
        return [
            'where' => $base . ($vis ? ' AND ' . implode(' AND ', $vis) : ''), 'args' => $a, 'ordem' => $ordem, 'ordem_args' => $ordemArgs ?? [], 'interp' => $i,
            'ocultos' => $vis ? [$base . ' AND NOT (' . implode(' AND ', $vis) . ')', $a] : null,
        ];
    }

    public const FROM = 'FROM bb_lanc l JOIN bb_contas c ON c.id = l.conta_id JOIN bb_arquivos ar ON ar.id = l.arquivo_id';

    public static function contar(PDO $pdo, string $where, array $args): array
    {
        $st = $pdo->prepare('SELECT COUNT(*) n, COALESCE(SUM(l.conferido), 0) conf, COALESCE(SUM(CASE WHEN l.valor > 0 THEN l.valor END), 0) ent, COALESCE(SUM(CASE WHEN l.valor < 0 THEN l.valor END), 0) sai ' . self::FROM . ' WHERE ' . $where);
        $st->execute($args);
        $r = $st->fetch();
        return ['n' => (int)$r['n'], 'conf' => (int)$r['conf'], 'ent' => (float)$r['ent'], 'sai' => (float)$r['sai']];
    }

    public static function linhas(PDO $pdo, array $m, int $limite, int $pular = 0): array
    {
        $st = $pdo->prepare('SELECT l.*, c.banco, c.apelido, c.empresa, ar.origem, cu.nome AS conferido_nome ' . self::FROM . ' LEFT JOIN users cu ON cu.id = l.conferido_por WHERE ' . $m['where'] . ' ORDER BY ' . $m['ordem'] . ' LIMIT ' . (int)$limite . ' OFFSET ' . (int)$pular);
        $st->execute(array_merge($m['args'], $m['ordem_args']));
        return $st->fetchAll();
    }

    /** marca (ou desmarca) como conferidos TODOS os lançamentos da busca, não só a página aberta. Devolve quantos mudaram. */
    public static function conferirTodos(PDO $pdo, array $m, bool $v, int $usuario): int
    {
        $st = $pdo->prepare('UPDATE bb_lanc l JOIN bb_contas c ON c.id = l.conta_id JOIN bb_arquivos ar ON ar.id = l.arquivo_id
            SET l.conferido = ?, l.conferido_por = ?, l.conferido_em = ? WHERE l.conferido = ? AND (' . $m['where'] . ')');
        $st->execute(array_merge([$v ? 1 : 0, $v ? $usuario : null, $v ? date('Y-m-d H:i:s') : null, $v ? 0 : 1], $m['args']));
        return $st->rowCount();
    }

    /** agrupado por pessoa/empresa (CPF/CNPJ quando existe; senão o nome) */
    public static function agrupar(PDO $pdo, array $m, int $limite = 300): array
    {
        $chave = "CASE WHEN l.doc <> '' THEN l.doc WHEN l.contraparte <> '' THEN UPPER(l.contraparte) ELSE '' END";
        $st = $pdo->prepare("SELECT $chave AS chave, MAX(l.doc) doc, MAX(l.contraparte) nome, COUNT(*) n,
              COALESCE(SUM(CASE WHEN l.valor > 0 THEN l.valor END), 0) ent, COALESCE(SUM(CASE WHEN l.valor < 0 THEN l.valor END), 0) sai, MIN(l.data) de, MAX(l.data) ate, COUNT(DISTINCT l.conta_id) contas
              " . self::FROM . ' WHERE ' . $m['where'] . " GROUP BY chave ORDER BY (chave = ''), ABS(SUM(l.valor)) DESC LIMIT " . (int)$limite);
        $st->execute($m['args']);
        return $st->fetchAll();
    }
}
