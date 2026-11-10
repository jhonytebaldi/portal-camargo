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
        $int = self::intervalos($pdo); $out = []; $hoje = date('Y-m-d');
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
        $min = min(array_map(fn($i) => $i[0][0], $int)); $hoje = date('Y-m-d');
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
                $linhas[$cid][$m] = ['st' => $st, 'n' => $n[$cid][$m] ?? 0, 'dias' => $dias];
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

    /**
     * Entende a caixa única: ['valores'=>[float], 'docs'=>[dígitos], 'datas'=>[iso], 'refs'=>[dígitos], 'palavras'=>[norm], 'sinal'=>-1|0|1]
     *   1500 · 1.500,00 · R$1500 → valor      11/14 dígitos válidos → CPF/CNPJ
     *   15/03 · 15/03/2026       → data       5 a 10 dígitos → valor OU nº no texto
     */
    public static function interpretar(string $q, ?int $anoPadrao = null): array
    {
        $r = ['valores' => [], 'docs' => [], 'datas' => [], 'refs' => [], 'palavras' => [], 'sinal' => 0];
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
                if ($temMoeda || strlen($int) <= 4) { $r['valores'][] = $v; continue; }
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
        foreach ($i['valores'] as $v) $p[] = 'valor ' . BbTexto::brl($v);
        foreach ($i['docs'] as $d) $p[] = (strlen($d) === 11 ? 'CPF ' : 'CNPJ ') . BbTexto::docFmt($d);
        foreach ($i['datas'] as $d) $p[] = 'data ' . BbTexto::dataBr($d);
        foreach ($i['refs'] as $d) $p[] = strlen($d) <= 7 ? 'valor ' . BbTexto::brl((float)$d) . ' (ou nº ' . $d . ')' : 'nº ' . $d . ' (ou valor ' . BbTexto::brl((float)$d) . ')';
        if ($i['palavras']) $p[] = 'texto "' . implode(' ', $i['palavras']) . '"';
        return $p;
    }

    /**
     * Monta o WHERE. $f: q, de, ate, empresas[], contas[], tipo (E|S|''), vmin, vmax, tolv, told, internas, anuladas, origem, pendentes, parcial
     * Devolve ['where'=>sql, 'args'=>[], 'ordem'=>sql, 'interp'=>…, 'ocultos'=>[where,args] (o mesmo filtro, só os escondidos)]
     */
    public static function montar(array $f): array
    {
        $i = self::interpretar((string)($f['q'] ?? ''), !empty($f['de']) ? (int)substr((string)$f['de'], 0, 4) : null);
        $w = []; $a = [];
        $tolv = max(0.0, (float)($f['tolv'] ?? 0)); $told = max(0, (int)($f['told'] ?? 0));
        foreach ($i['valores'] as $v) { $w[] = 'ABS(l.valor) BETWEEN ? AND ?'; $a[] = $v - $tolv - 0.004; $a[] = $v + $tolv + 0.004; }
        foreach ($i['refs'] as $d) { $w[] = '(ABS(l.valor) BETWEEN ? AND ? OR l.busca LIKE ?)'; $a[] = (float)$d - $tolv - 0.004; $a[] = (float)$d + $tolv + 0.004; $a[] = '%' . $d . '%'; }
        foreach ($i['docs'] as $d) { $w[] = '(l.doc = ? OR l.busca LIKE ?)'; $a[] = $d; $a[] = '%' . $d . '%'; }
        foreach ($i['datas'] as $d) { $w[] = 'l.data BETWEEN ? AND ?'; $a[] = date('Y-m-d', strtotime("$d -$told days")); $a[] = date('Y-m-d', strtotime("$d +$told days")); }
        $ordem = 'l.data DESC, l.id DESC';
        if ($i['palavras']) {
            $conds = array_fill(0, count($i['palavras']), 'l.busca LIKE ?'); $likes = array_map(fn($p) => '%' . $p . '%', $i['palavras']);
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
        if (empty($f['internas'])) $vis[] = 'l.interna = 0';
        if (empty($f['anuladas'])) $vis[] = 'l.anulada = 0';
        $base = $w ? implode(' AND ', $w) : '1=1';
        return [
            'where' => $base . ($vis ? ' AND ' . implode(' AND ', $vis) : ''), 'args' => $a, 'ordem' => $ordem, 'ordem_args' => $ordemArgs ?? [], 'interp' => $i,
            'ocultos' => $vis ? [$base . ' AND NOT (' . implode(' AND ', $vis) . ')', $a] : null,
        ];
    }

    public const FROM = 'FROM bb_lanc l JOIN bb_contas c ON c.id = l.conta_id JOIN bb_arquivos ar ON ar.id = l.arquivo_id';

    public static function contar(PDO $pdo, string $where, array $args): array
    {
        $st = $pdo->prepare('SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN l.valor > 0 THEN l.valor END), 0) ent, COALESCE(SUM(CASE WHEN l.valor < 0 THEN l.valor END), 0) sai ' . self::FROM . ' WHERE ' . $where);
        $st->execute($args);
        $r = $st->fetch();
        return ['n' => (int)$r['n'], 'ent' => (float)$r['ent'], 'sai' => (float)$r['sai']];
    }

    public static function linhas(PDO $pdo, array $m, int $limite, int $pular = 0): array
    {
        $st = $pdo->prepare('SELECT l.*, c.banco, c.apelido, c.empresa, ar.origem ' . self::FROM . ' WHERE ' . $m['where'] . ' ORDER BY ' . $m['ordem'] . ' LIMIT ' . (int)$limite . ' OFFSET ' . (int)$pular);
        $st->execute(array_merge($m['args'], $m['ordem_args']));
        return $st->fetchAll();
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
