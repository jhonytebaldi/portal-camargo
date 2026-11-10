<?php
/* =====================================================================
   busca-bancos/lib/Conferencia.php — confere uma planilha (a antiga, de
   entradas/saídas) contra os extratos, e cruza o extrato com o Omie.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/Busca.php';

final class BbConferencia
{
    public const MAX_LINHAS = 60000;      // linhas lidas da planilha
    public const MAX_CONFERIR = 20000;    // linhas efetivamente conferidas
    public const STATUS = ['ok' => 'Encontrada', 'ambigua' => 'Várias possíveis', 'nao' => 'Não encontrada', 'sem_dados' => 'Sem extrato na data',
        'pend_achou' => 'Pendente na planilha, mas está no banco', 'pendente' => 'Pendente (nada no banco)'];

    /** lê .xlsx ou .csv e devolve uma matriz de textos [linha][coluna] (base 0) */
    public static function lerPlanilha(string $caminho, string $nome): array
    {
        $out = [];
        if (preg_match('/\.xlsx$/i', $nome)) {
            require_once __DIR__ . '/../../capa-financeira/lib/Xlsx.php';
            $x = Xlsx::open($caminho);
            $mr = min($x->maxRow(), self::MAX_LINHAS); $mc = min($x->maxCol(), 40);
            for ($r = 1; $r <= $mr; $r++) { $lin = [];
                for ($c = 1; $c <= $mc; $c++) { $v = $x->cell($r, $c);
                    if ($v instanceof DateTimeInterface) $v = $v->format('d/m/Y');
                    elseif (is_float($v) || is_int($v)) $v = rtrim(rtrim(number_format((float)$v, 4, ',', ''), '0'), ',');
                    elseif (is_bool($v)) $v = $v ? 'sim' : 'não';
                    $lin[] = trim((string)($v ?? '')); }
                $out[] = $lin; }
        } elseif (preg_match('/\.(csv|txt)$/i', $nome)) {
            $bruto = (string)file_get_contents($caminho);
            if (!mb_check_encoding($bruto, 'UTF-8')) $bruto = mb_convert_encoding($bruto, 'UTF-8', 'Windows-1252');
            $bruto = preg_replace('/^\xEF\xBB\xBF/', '', $bruto) ?? $bruto;
            $l1 = strtok($bruto, "\n") ?: ''; $sep = substr_count($l1, ';') >= substr_count($l1, ',') ? (substr_count($l1, "\t") > substr_count($l1, ';') ? "\t" : ';') : ',';
            $h = fopen('php://memory', 'w+'); fwrite($h, $bruto); rewind($h);
            while (($lin = fgetcsv($h, 0, $sep, '"', '')) !== false && count($out) < self::MAX_LINHAS) $out[] = array_map(fn($v) => trim((string)$v), array_slice($lin, 0, 40));
            fclose($h);
        } else throw new RuntimeException('Envie a planilha em .xlsx ou .csv (se estiver em .xls antigo, salve como .xlsx).');
        while ($out && !array_filter(end($out), fn($v) => $v !== '')) array_pop($out);
        if (!$out) throw new RuntimeException('A planilha está vazia.');
        return $out;
    }

    public static function valor(string $s): ?float
    {
        $s = trim(str_replace(['R$', ' ', "\u{a0}"], '', $s));
        if ($s === '' || !preg_match('/\d/', $s)) return null;
        $neg = str_contains($s, '-') || preg_match('/^\(.*\)$/', $s); $s = preg_replace('/[^\d.,]/', '', $s) ?? '';
        if (str_contains($s, ',')) $s = str_replace(',', '.', str_replace('.', '', $s));
        elseif (substr_count($s, '.') > 1 || preg_match('/\.\d{3}$/', $s)) $s = str_replace('.', '', $s);
        if (!is_numeric($s)) return null;
        return round(($neg ? -1 : 1) * (float)$s, 2);
    }
    public static function data(string $s): ?string
    {
        $s = trim($s);
        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})/', $s, $m)) { $a = (int)(strlen($m[3]) === 2 ? '20' . $m[3] : $m[3]); return checkdate((int)$m[2], (int)$m[1], $a) ? sprintf('%04d-%02d-%02d', $a, $m[2], $m[1]) : null; }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
        return null;
    }

    /** palpite de linha de cabeçalho e de colunas: cab, data, valor, entrada, saida, receber, pagar, grupo, banco, texto[] */
    public static function adivinhar(array $m): array
    {
        $cab = 0; $melhor = -1;
        foreach (array_slice($m, 0, 15, true) as $i => $lin) { $n = 0; foreach ($lin as $v) if (preg_match('/\b(data|valor|nome|cliente|descri|hist|entrada|sa[ií]da|cr[eé]dito|d[eé]bito|favorecido|pagador)/iu', $v)) $n++; if ($n > $melhor) { $melhor = $n; $cab = $i; } }
        $r = ['cab' => $cab, 'data' => null, 'valor' => null, 'entrada' => null, 'saida' => null, 'receber' => null, 'pagar' => null, 'grupo' => null, 'banco' => null, 'texto' => []];
        foreach ($m[$cab] as $c => $v) { $n = BbTexto::norm($v);
            if ($r['data'] === null && preg_match('/\b(data|dt|dia)\b/', $n) && !preg_match('/venc|emiss/', $n)) $r['data'] = $c;
            elseif ($r['receber'] === null && preg_match('/\ba receber\b/', $n)) $r['receber'] = $c;
            elseif ($r['pagar'] === null && preg_match('/\ba pagar\b/', $n)) $r['pagar'] = $c;
            elseif ($r['entrada'] === null && preg_match('/\b(entrada|credito|recebido|receita)/', $n)) $r['entrada'] = $c;
            elseif ($r['saida'] === null && preg_match('/\b(saida|debito|pago|despesa)/', $n)) $r['saida'] = $c;
            elseif ($r['valor'] === null && preg_match('/\b(valor|vlr|total|quantia)\b/', $n)) $r['valor'] = $c;
            elseif ($r['banco'] === null && preg_match('/\b(orig|banco|conta bancaria|conta corrente)/', $n)) $r['banco'] = $c;
            elseif ($r['grupo'] === null && in_array($n, ['cliente', 'venda', 'comprador'], true)) { $r['grupo'] = $c; $r['texto'][] = $c; }
            elseif (preg_match('/\b(nome|cliente|descri|hist|favorecido|pagador|fornecedor|obs|referente)/', $n)) $r['texto'][] = $c;
        }
        if ($r['data'] === null) $r['data'] = self::colunaQue($m, $cab, fn($v) => self::data($v) !== null);
        if ($r['valor'] === null && $r['entrada'] === null && $r['saida'] === null) $r['valor'] = self::colunaQue($m, $cab, fn($v) => (bool)preg_match('/^-?\(?(R\$)?\s?\d[\d.]*,\d{2}\)?$/', $v));
        return $r;
    }
    private static function colunaQue(array $m, int $cab, callable $teste): ?int
    {
        $cont = [];
        foreach (array_slice($m, $cab + 1, 40) as $lin) foreach ($lin as $c => $v) if ($v !== '' && $teste($v)) $cont[$c] = ($cont[$c] ?? 0) + 1;
        if (!$cont) return null; arsort($cont); return (int)array_key_first($cont);
    }

    /**
     * Confere e grava. $p: cab, data, valor, entrada, saida, receber, pagar, grupo, banco, texto[], sinal (auto|entradas|saidas|qualquer),
     * tolv, told, contas[], empresas[], escopo (''|'vendas'), ano (para o escopo). Devolve o id da conferência.
     *
     * Escopo "vendas": só confere as vendas (coluna de agrupamento) que têm algum lançamento do ano $ano em diante
     * OU alguma linha pendente (só a receber / a pagar). Linhas pendentes com data já vencida também são procuradas
     * no banco — se aparecerem, é sinal de que aconteceu e não foi baixado na planilha.
     */
    public static function conferir(PDO $pdo, array $m, array $p, string $nome, ?int $usuario): int
    {
        $tolv = max(0.0, min(5.0, (float)($p['tolv'] ?? 0))); $told = max(0, min(90, (int)($p['told'] ?? 3)));
        $col = fn($k) => ($p[$k] ?? '') === '' || $p[$k] === null ? null : (int)$p[$k];
        $cData = $col('data'); $cValor = $col('valor'); $cEnt = $col('entrada'); $cSai = $col('saida'); $cRec = $col('receber'); $cPag = $col('pagar'); $cGrupo = $col('grupo'); $cBanco = $col('banco');
        $cTexto = array_map('intval', (array)($p['texto'] ?? []));
        if ($cValor === null && $cEnt === null && $cSai === null) throw new RuntimeException('Diga em qual coluna está o valor.');
        $sinal = (string)($p['sinal'] ?? 'auto'); $hoje = date('Y-m-d');
        $contas = array_filter(BbCobertura::contas($pdo), fn($c) => (empty($p['contas']) || in_array((int)$c['id'], array_map('intval', (array)$p['contas']), true)) && (empty($p['empresas']) || in_array($c['empresa'], (array)$p['empresas'], true)));
        if (!$contas) throw new RuntimeException('Nenhuma conta dentro do filtro escolhido.');
        $int = BbCobertura::intervalos($pdo); $ids = implode(',', array_map('intval', array_keys($contas)));
        $coberta = function (int $cid, string $d) use ($int): bool { foreach ($int[$cid] ?? [] as [$a, $b]) if ($d >= $a && $d <= $b) return true; return false; };

        // 1) linhas da planilha (realizadas e pendentes)
        $linhas = [];
        foreach ($m as $i => $lin) {
            if ($i <= (int)$p['cab']) continue;
            $v = null; $dir = 0; $prev = 0;
            if ($cEnt !== null && ($x = self::valor($lin[$cEnt] ?? '')) !== null && abs($x) > 0.004) { $v = abs($x); $dir = $x > 0 ? 1 : -1; }
            elseif ($cSai !== null && ($x = self::valor($lin[$cSai] ?? '')) !== null && abs($x) > 0.004) { $v = abs($x); $dir = $x > 0 ? -1 : 1; }
            elseif ($cValor !== null && ($x = self::valor($lin[$cValor] ?? '')) !== null && abs($x) > 0.004) { $v = abs($x); $dir = $sinal === 'entradas' ? 1 : ($sinal === 'saidas' ? -1 : ($sinal === 'auto' && $x < 0 ? -1 : 0)); }
            elseif ($cRec !== null && ($x = self::valor($lin[$cRec] ?? '')) !== null && abs($x) > 0.004) { $v = abs($x); $dir = $x > 0 ? 1 : -1; $prev = 1; }
            elseif ($cPag !== null && ($x = self::valor($lin[$cPag] ?? '')) !== null && abs($x) > 0.004) { $v = abs($x); $dir = $x > 0 ? -1 : 1; $prev = 1; }
            if ($v === null) continue;
            $textos = array_values(array_filter(array_map(fn($c) => $lin[$c] ?? '', $cTexto), fn($s) => $s !== ''));
            $linhas[] = ['n' => $i + 1, 'data' => $cData !== null ? self::data($lin[$cData] ?? '') : null, 'valor' => $v, 'dir' => $dir, 'prev' => $prev, 'textos' => $textos,
                'grupo' => $cGrupo !== null ? trim(preg_replace('/\s+/', ' ', $lin[$cGrupo] ?? '') ?? '') : '', 'banco' => $cBanco !== null ? trim($lin[$cBanco] ?? '') : '', 'orig' => $lin];
        }
        // escopo: só as vendas com lançamento do ano em diante ou com pendência
        if (($p['escopo'] ?? '') === 'vendas' && $cGrupo !== null) {
            $ano = (int)($p['ano'] ?? date('Y')) . '-01-01'; $dentro = [];
            foreach ($linhas as $l) if ($l['grupo'] !== '' && ($l['prev'] || ($l['data'] && $l['data'] >= $ano))) $dentro[mb_strtoupper($l['grupo'])] = 1;
            $linhas = array_values(array_filter($linhas, fn($l) => $l['grupo'] !== '' && isset($dentro[mb_strtoupper($l['grupo'])])));
        }
        // pendentes com data futura ainda não podem ter acontecido: ficam de fora
        $linhas = array_values(array_filter($linhas, fn($l) => !$l['prev'] || ($l['data'] && $l['data'] <= $hoje)));
        if (!$linhas) throw new RuntimeException('Não achei nenhuma linha para conferir com as colunas e o escopo indicados.');
        if (count($linhas) > self::MAX_CONFERIR) throw new RuntimeException('São ' . count($linhas) . ' linhas para conferir; o limite é ' . self::MAX_CONFERIR . '. Divida a planilha (por ano, por exemplo).');

        // 2) lançamentos do banco em memória, por valor em centavos
        $porValor = []; $L = [];
        foreach ($pdo->query("SELECT l.id, l.data, l.valor, l.busca, l.doc FROM bb_lanc l WHERE l.conta_id IN ($ids) AND l.anulada = 0 ORDER BY l.data, l.id") as $c) {
            $c['pal'] = null; $L[(int)$c['id']] = $c; $porValor[(int)round(abs((float)$c['valor']) * 100)][] = (int)$c['id'];
        }
        $tolC = (int)round($tolv * 100); $cand = [];
        foreach ($linhas as $k => $l) {
            $cent = (int)round($l['valor'] * 100); $toks = array_map([self::class, 'tokens'], $l['textos']); $doc = BbTexto::acharDoc(implode(' ', $l['textos']))[0] ?? '';
            $ts = $l['data'] ? strtotime($l['data']) : null; $cs = [];
            for ($x = $cent - $tolC; $x <= $cent + $tolC; $x++) foreach ($porValor[$x] ?? [] as $id) {
                $c = $L[$id];
                if (($l['dir'] > 0 && (float)$c['valor'] < 0) || ($l['dir'] < 0 && (float)$c['valor'] > 0)) continue;
                $dist = $ts !== null ? abs(strtotime($c['data']) - $ts) / 86400 : 0;
                if ($dist > $told + 0.01) continue;
                $nomeS = 0.0;
                foreach ($toks as $tok) { if (!$tok) continue; $bate = 0; $pal = null;
                    foreach ($tok as $t) { if (str_contains($c['busca'], $t)) { $bate++; continue; } $pal ??= explode(' ', $c['busca']); foreach ($pal as $w2) if (strlen($w2) >= 4 && str_starts_with($t, $w2)) { $bate += 0.8; break; } }
                    // nomes curtos pesam inteiro; textos longos (histórico) contam quantas palavras bateram
                    $nomeS = max($nomeS, count($tok) <= 6 ? $bate / count($tok) : min(1.0, $bate / 3) * 0.9);
                }
                if ($doc !== '' && $c['doc'] === $doc) $nomeS = 2.0;
                $cs[$id] = ['dist' => $dist, 'nome' => $nomeS, 'score' => $nomeS * 10 - $dist * 0.5 - abs($x - $cent) / 100, 'igual' => $c['data'] . '|' . $c['valor'] . '|' . $c['busca']];
            }
            uasort($cs, fn($a, $b) => $b['score'] <=> $a['score']);
            $cand[$k] = array_slice($cs, 0, 40, true);
        }

        // 3) atribuição: cada lançamento do banco casa com uma linha só; realizadas têm prioridade sobre pendentes
        $usado = []; $res = [];
        foreach ([0, 1] as $fase) do { $mudou = false;
            foreach ($linhas as $k => $l) {
                if (isset($res[$k]) || $l['prev'] !== $fase) continue;
                $livres = array_filter($cand[$k], fn($id) => !isset($usado[$id]), ARRAY_FILTER_USE_KEY);
                if (!$livres) continue;
                $idsL = array_keys($livres); $top = $livres[$idsL[0]]; $seg = isset($idsL[1]) ? $livres[$idsL[1]] : null;
                $unico = count($livres) === 1; $nomeClaro = $top['nome'] >= 0.5 && $top['nome'] - ($seg['nome'] ?? 0) >= 0.3; $iguais = count(array_unique(array_column($livres, 'igual'))) === 1;
                // um único candidato exatamente na data da planilha (e nenhum outro com nome melhor) também decide
                $naData = $l['data'] && $top['dist'] < 0.01 && count(array_filter($livres, fn($x) => $x['dist'] < 0.01)) === 1 && $top['nome'] >= max(array_column($livres, 'nome')) - 0.001;
                // linha pendente só casa com indício de nome (valor sozinho é coincidência fácil)
                if ($fase === 1 ? !($top['nome'] >= 0.5 && ($unico || $nomeClaro || $iguais || $naData)) : !($unico || $nomeClaro || $iguais || $naData)) continue;
                $id = $idsL[0]; $usado[$id] = 1; $mudou = true; $c = $L[$id];
                $partes = [abs(abs((float)$c['valor']) - $l['valor']) < 0.005 ? 'valor igual' : 'valor dentro da tolerância'];
                if ($l['data']) $partes[] = $c['data'] === $l['data'] ? 'mesma data' : 'data com ' . (int)round(abs(strtotime($c['data']) - strtotime($l['data'])) / 86400) . ' dia(s) de diferença';
                if ($l['textos']) $partes[] = $top['nome'] >= 0.5 ? 'nome confere' : ($top['nome'] > 0 ? 'nome confere em parte' : 'nome não confirmado pelo extrato');
                $res[$k] = [$fase ? 'pend_achou' : 'ok', $id, array_slice($idsL, 0, 8), ($fase ? 'na planilha está só como previsto, sem baixa; ' : '') . implode(', ', $partes)];
            }
        } while ($mudou);
        foreach ($linhas as $k => $l) {
            if (isset($res[$k])) continue;
            $livres = array_keys(array_filter($cand[$k], fn($id) => !isset($usado[$id]), ARRAY_FILTER_USE_KEY));
            if ($l['prev']) { $res[$k] = ['pendente', null, array_slice($livres, 0, 8), $livres ? 'há ' . count($livres) . ' lançamento(s) de mesmo valor perto da data, mas sem nome que confirme' : 'nada parecido no banco']; continue; }
            if ($livres) { $res[$k] = ['ambigua', null, array_slice($livres, 0, 8), count($livres) . ' lançamentos possíveis — escolha o certo']; continue; }
            $sem = [];
            if ($l['data']) foreach ($contas as $cid => $c) if (!$coberta($cid, $l['data'])) $sem[] = $c['apelido'];
            $dinheiro = preg_match('/dinheiro|especie|rateio/', BbTexto::norm($l['banco'])) ? '; a planilha diz "' . $l['banco'] . '": pode não ter passado por banco' : '';
            if ($l['data'] && count($sem) === count($contas)) $res[$k] = ['sem_dados', null, [], 'nenhuma conta tem extrato em ' . BbTexto::dataBr($l['data']) . $dinheiro];
            else $res[$k] = ['nao', null, [], ($cand[$k] ? 'o lançamento de mesmo valor já casou com outra linha' : 'nada com esse valor' . ($l['data'] ? ' perto da data' : '')) . $dinheiro . ($sem ? '; atenção: sem extrato nessa data em ' . implode(', ', array_slice($sem, 0, 4)) . (count($sem) > 4 ? '…' : '') : '')];
        }

        // 4) grava (em lotes)
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO bb_conf (nome, parametros, criado_por, criado_em) VALUES (?,?,?,?)')->execute([mb_substr($nome, 0, 200),
            json_encode(['cabecalho' => $m[(int)$p['cab']] ?? [], 'tolv' => $tolv, 'told' => $told, 'sinal' => $sinal, 'escopo' => (string)($p['escopo'] ?? ''), 'ano' => (int)($p['ano'] ?? 0), 'contas' => array_values(array_map(fn($c) => $c['apelido'], $contas))], JSON_UNESCAPED_UNICODE), $usuario, date('Y-m-d H:i:s')]);
        $cid = (int)$pdo->lastInsertId();
        foreach (array_chunk(array_keys($linhas), 200) as $lote) {
            $vals = [];
            foreach ($lote as $k) { $l = $linhas[$k];
                array_push($vals, $cid, $l['n'], $l['data'], ($l['dir'] < 0 ? -1 : 1) * $l['valor'], mb_substr(implode(' · ', $l['textos']), 0, 400), json_encode($l['orig'], JSON_UNESCAPED_UNICODE),
                    mb_substr($l['grupo'], 0, 160), mb_substr($l['banco'], 0, 60), $l['prev'], $res[$k][0], $res[$k][1], json_encode($res[$k][2]), mb_substr($res[$k][3], 0, 200)); }
            $pdo->prepare('INSERT INTO bb_conf_linhas (conf_id, linha, data, valor, texto, original, grupo, banco_planilha, previsto, status, lanc_id, candidatos, motivo) VALUES ' . implode(',', array_fill(0, count($lote), '(?,?,?,?,?,?,?,?,?,?,?,?,?)')))->execute($vals);
        }
        $pdo->commit();
        return $cid;
    }

    /** palavras úteis para comparar nomes (sem números soltos nem palavras vazias) */
    private static function tokens(string $texto): array
    {
        $vazias = ['de', 'da', 'do', 'das', 'dos', 'e', 'ltda', 'me', 'sa', 'eireli', 'pix', 'ted', 'pagamento', 'recebimento', 'comissao', 'ref', 'referente', 'parcela', 'recibo', 'xxxx', 'corretor', 'coordenador', 'rua', 'unidade', 'planta', 'pronto', 'venda', 'geminado', 'bonus', 'imovel', 'entregar'];
        return array_values(array_unique(array_filter(explode(' ', BbTexto::norm($texto)), fn($t) => strlen($t) >= 3 && !ctype_digit($t) && !in_array($t, $vazias, true))));
    }
}

/** extrato do Omie × extrato do banco */
final class BbOmie
{
    /** movimentos do extrato do Omie: [['id','data','valor','texto','doc','situacao','categoria']] */
    public static function extrato(string $empresa, string $ncodcc, string $deIso, string $ateIso): array
    {
        require_once __DIR__ . '/../../recibos-omie/lib/OmieApi.php';
        $r = OmieApi::call($empresa, 'financas/extrato/', 'ListarExtrato', ['nCodCC' => (int)$ncodcc, 'dPeriodoInicial' => OmieApi::br($deIso), 'dPeriodoFinal' => OmieApi::br($ateIso), 'cExibirApenasSaldo' => 'N']);
        $out = [];
        foreach ($r['listaMovimentos'] ?? [] as $mv) {
            $d = OmieApi::iso((string)($mv['dDataLancamento'] ?? '')); $v = round((float)($mv['nValorDocumento'] ?? 0), 2);
            if (!$d || abs($v) < 0.005 || empty($mv['nCodLancamento'])) continue;   // linhas de saldo não têm lançamento
            $out[] = ['id' => (string)$mv['nCodLancamento'], 'data' => $d, 'valor' => $v, 'situacao' => (string)($mv['cSituacao'] ?? ''), 'categoria' => (string)($mv['cDesCategoria'] ?? ''),
                'texto' => trim((string)($mv['cRazCliente'] ?? '') ?: (string)($mv['cDesCliente'] ?? '')) . ((string)($mv['cObservacoes'] ?? '') !== '' ? ' — ' . $mv['cObservacoes'] : ''), 'doc' => preg_replace('/\D+/', '', (string)($mv['cDocCliente'] ?? ''))];
        }
        return ['mov' => $out, 'saldo' => $r['nSaldoAtual'] ?? null, 'conta' => (string)($r['cDescricao'] ?? '')];
    }

    /** casa por valor exato e data mais próxima (até $told dias). Devolve ['pares'=>[[banco, omie]], 'so_banco'=>[], 'so_omie'=>[]] */
    public static function cruzar(array $banco, array $omie, int $told): array
    {
        $porValor = [];
        foreach ($omie as $i => $o) $porValor[number_format($o['valor'], 2, '.', '')][] = $i;
        $usado = []; $pares = []; $soBanco = [];
        foreach ($banco as $b) {
            $melhor = null; $dist = PHP_INT_MAX;
            foreach ($porValor[number_format((float)$b['valor'], 2, '.', '')] ?? [] as $i) {
                if (isset($usado[$i])) continue;
                $d = (int)round(abs(strtotime($omie[$i]['data']) - strtotime($b['data'])) / 86400);
                if ($d <= $told && $d < $dist) { $melhor = $i; $dist = $d; }
            }
            if ($melhor === null) { $soBanco[] = $b; continue; }
            $usado[$melhor] = 1; $pares[] = [$b, $omie[$melhor]];
        }
        return ['pares' => $pares, 'so_banco' => $soBanco, 'so_omie' => array_values(array_filter($omie, fn($i) => !isset($usado[$i]), ARRAY_FILTER_USE_KEY))];
    }
}
