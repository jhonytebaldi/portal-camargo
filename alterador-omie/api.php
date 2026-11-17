<?php
/* =====================================================================
   alterador-omie/api.php — ações JSON da tela do Alterador Omie.
   GET  a=estado|titulos|rodada|historico|exportar
   POST a=atualizar|previa|criar|enviar|conferir|parar|desfazer|reconferir|receita_salvar|receita_excluir
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
require_login();
ao_instalar_se_preciso();   // admin: cria tabelas e registra a ferramenta antes da checagem de acesso (1ª visita)
$u = require_tool('alterador-omie');
$pdo = db();
$a = (string)($_GET['a'] ?? $_POST['a'] ?? '');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$in = [];
if ($post) {
    csrf_check();
    $in = json_decode((string)($_POST['dados'] ?? '{}'), true) ?: [];
    $a = (string)($in['a'] ?? $a);
}
session_write_close();   // requisições longas (leitura do Omie) não travam as outras abas
@set_time_limit(90);

try {
    /* ---------------- leitura ---------------- */
    if ($a === 'estado') {
        $emp = ao_empresa((string)($_GET['emp'] ?? ''));
        $cat = null; $erroCat = '';
        try { $cat = AoCache::catalogo($emp); } catch (Throwable $e) { $erroCat = $e->getMessage(); }
        $lista = fn(array $m) => array_map(fn($k, $v) => ['id' => (string)$k] + $v, array_keys($m), $m);
        $rec = $pdo->query('SELECT r.id, r.nome, r.ops, r.opts, us.nome AS por FROM ao_receitas r LEFT JOIN users us ON us.id = r.criado_por ORDER BY r.nome')->fetchAll();
        ao_json(['emp' => $emp, 'empresas' => ao_empresas(), 'cache' => ['P' => AoCache::estado($emp, 'P'), 'R' => AoCache::estado($emp, 'R')],
            'catalogo' => $cat ? ['cat' => $lista($cat['cat']), 'cc' => $lista($cat['cc']), 'deps' => $lista($cat['deps']), 'proj' => $lista($cat['proj']), 'quando' => $cat['quando']] : null,
            'erro_catalogo' => $erroCat, 'agora' => time(),
            'receitas' => array_map(fn($r) => ['id' => (int)$r['id'], 'nome' => $r['nome'], 'por' => $r['por'], 'ops' => json_decode($r['ops'], true), 'opts' => json_decode($r['opts'], true)], $rec)]);
    }
    if ($a === 'titulos') {
        $emp = ao_empresa((string)($_GET['emp'] ?? ''));
        $tipo = ($_GET['tipo'] ?? 'P') === 'R' ? 'R' : 'P';
        $j = AoCache::titulos($emp, $tipo);
        $forn = [];
        try { $forn = AoCache::cadastros($emp); } catch (Throwable $e) {}
        $rows = []; $nomes = [];
        foreach ($j['itens'] ?? [] as $t) { unset($t['_fonte']); $rows[] = $t; $k = (string)$t['forn']; if (!isset($nomes[$k])) $nomes[$k] = $forn[$k]['nome'] ?? ''; }
        ao_json(['tipo' => $tipo, 'estado' => AoCache::estado($emp, $tipo), 'rows' => $rows, 'forn' => $nomes]);
    }
    if ($a === 'forn') {
        $emp = ao_empresa((string)($_GET['emp'] ?? ''));
        $q = mb_strtolower(trim((string)($_GET['q'] ?? ''))); $dig = preg_replace('/\D+/', '', $q);
        $out = [];
        if (mb_strlen($q) >= 2) foreach (AoCache::cadastros($emp) as $id => $c) {
            if ($c['inativo']) continue;
            $ok = str_contains(mb_strtolower($c['nome'] . ' ' . $c['fant']), $q) || ($dig !== '' && strlen($dig) >= 3 && str_contains(preg_replace('/\D+/', '', $c['doc']), $dig));
            if ($ok) { $out[] = ['id' => (string)$id, 'nome' => $c['nome'], 'doc' => $c['doc']]; if (count($out) >= 25) break; }
        }
        ao_json(['itens' => $out]);
    }
    if ($a === 'historico') {
        $rs = $pdo->query("SELECT r.*, us.nome AS por,
                (SELECT COUNT(*) FROM ao_itens i WHERE i.rodada_id = r.id) AS n,
                (SELECT COUNT(*) FROM ao_itens i WHERE i.rodada_id = r.id AND i.status IN ('conferido','ja_ok')) AS ok,
                (SELECT COUNT(*) FROM ao_itens i WHERE i.rodada_id = r.id AND i.status = 'aviso') AS avisos,
                (SELECT COUNT(*) FROM ao_itens i WHERE i.rodada_id = r.id AND i.status IN ('erro','nao_aplicado','conflito')) AS erros,
                (SELECT COUNT(*) FROM ao_itens i WHERE i.rodada_id = r.id AND i.status IN ('pendente','enviado')) AS abertos
            FROM ao_rodadas r LEFT JOIN users us ON us.id = r.criado_por ORDER BY r.id DESC LIMIT 60")->fetchAll();
        ao_json(['rodadas' => array_map(fn($r) => ['id' => (int)$r['id'], 'empresa' => $r['empresa'], 'tipos' => $r['tipos'], 'resumo' => $r['resumo'], 'por' => $r['por'],
            'em' => date('d/m/Y H:i', strtotime($r['criado_em'])), 'desfaz' => $r['desfaz_id'] ? (int)$r['desfaz_id'] : null, 'n' => (int)$r['n'], 'ok' => (int)$r['ok'],
            'avisos' => (int)$r['avisos'], 'erros' => (int)$r['erros'], 'abertos' => (int)$r['abertos'], 'status' => $r['status']], $rs)]);
    }
    if ($a === 'rodada') {
        ao_json(ao_rodada_view($pdo, (int)($_GET['id'] ?? 0)));
    }
    if ($a === 'exportar') {
        $v = ao_rodada_view($pdo, (int)($_GET['id'] ?? 0));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="alterador-omie-rodada-' . $v['rodada']['id'] . '.csv"');
        $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
        fputcsv($o, ['Tipo', 'Código Omie', 'Cód. integração', 'Fornecedor/Cliente', 'Campo', 'Antes', 'Pedido', 'Situação', 'Mensagem'], ';');
        foreach ($v['itens'] as $i) {
            $campos = $i['campos'] ?: [['rot' => '', 'antes' => '', 'alvo' => '']];
            foreach ($campos as $c) fputcsv($o, [$i['tipo'] === 'R' ? 'Receber' : 'Pagar', $i['omie_id'], $i['ci'], $i['nome'], $c['rot'], $c['antes'], $c['alvo'], $i['status_rot'], $i['msg']], ';');
        }
        fclose($o); exit;
    }
    if (!$post) ao_json(['erro' => 'ação inválida'], 400);

    /* ---------------- escrita ---------------- */
    if ($a === 'atualizar') {
        $emp = ao_empresa((string)($in['emp'] ?? ''));
        $tipo = ($in['tipo'] ?? 'P') === 'R' ? 'R' : 'P';
        $modo = in_array($in['modo'] ?? '', ['auto', 'incremental', 'completa'], true) ? $in['modo'] : 'auto';
        $prazo = microtime(true) + 20;
        OmieEnvio::$prazo = $prazo;
        $msgs = [];
        if (!empty($in['cadastros'])) {
            try { AoCache::catalogo($emp, $modo !== 'auto'); }
            catch (Throwable $e) { $msgs[] = 'categorias/contas: ' . $e->getMessage(); }
            try { AoCache::cadastros($emp, $modo !== 'auto'); }
            catch (OmieContinuar $e) { ao_json(['ok' => true, 'continuar' => true, 'msg' => 'lendo os cadastros de clientes/fornecedores… (' . $e->getMessage() . ')']); }
            catch (Throwable $e) { $msgs[] = 'cadastros: ' . $e->getMessage(); }
        }
        $r = AoCache::atualizar($emp, $tipo, $modo, $prazo);
        if ($msgs) $r['msg'] .= ' · ' . implode(' · ', $msgs);
        $r['estado'] = AoCache::estado($emp, $tipo);
        ao_json($r);
    }
    if ($a === 'previa' || $a === 'criar') {
        $emp = ao_empresa((string)($in['emp'] ?? ''));
        [$titulos, $tipos] = ao_titulos_escolhidos($emp, (array)($in['itens'] ?? []));
        $ops = array_values((array)($in['ops'] ?? []));
        $opts = ao_opts((array)($in['opts'] ?? []));
        $ctx = AoCache::contexto($emp);
        $erros = AoRegras::validarOps($ops, $tipos, $ctx);
        if ($erros) ao_json(['erros' => $erros]);
        $res = AoRegras::preparar($titulos, $ops, $opts, $ctx);
        if ($a === 'previa') ao_json(['linhas' => array_map(fn($x) => ao_linha_previa($x, $ctx), $res), 'resumo' => AoRegras::resumo($ops)]);
        $id = ao_criar_rodada($pdo, $u, $emp, $tipos, $ops, $opts, $res, $ctx, null);
        ao_json(ao_rodada_view($pdo, $id));
    }
    if ($a === 'enviar' || $a === 'conferir') {
        $it = ao_item($pdo, (int)($in['item'] ?? 0));
        $rod = $pdo->query('SELECT * FROM ao_rodadas WHERE id = ' . (int)$it['rodada_id'])->fetch();
        $emp = ao_empresa((string)$rod['empresa']);
        $esperado = $a === 'enviar' ? 'pendente' : 'enviado';
        if ($it['status'] !== $esperado) ao_json(['item' => ao_item_view($it, null), 'msg' => 'item já está como ' . $it['status']]);
        $ctx = AoCache::contexto($emp);
        $r = $a === 'enviar' ? AoExec::enviar($pdo, $emp, $rod, $it, $ctx) : AoExec::conferir($pdo, $emp, $it, $ctx);
        $pdo->prepare('UPDATE ao_itens SET status = ?, msg = ? WHERE id = ?')->execute([$r['status'], $r['msg'], $it['id']]);
        if (!empty($r['parar'])) $pdo->prepare("UPDATE ao_rodadas SET status = 'pausada' WHERE id = ?")->execute([$rod['id']]);
        ao_fechar_se_terminou($pdo, (int)$rod['id']);
        $it = ao_item($pdo, (int)$it['id']);
        ao_json(['item' => ao_item_view($it, AoCache::nomes($ctx)), 'tentar_em' => $r['tentar_em'] ?? null, 'parar' => !empty($r['parar'])]);
    }
    if ($a === 'parar') {
        $id = (int)($in['rodada'] ?? 0);
        $pdo->prepare("UPDATE ao_itens SET status = 'pulado', msg = 'não enviado (rodada interrompida por você)' WHERE rodada_id = ? AND status = 'pendente'")->execute([$id]);
        ao_fechar_se_terminou($pdo, $id);
        ao_json(ao_rodada_view($pdo, $id));
    }
    if ($a === 'retomar') {
        $id = (int)($in['rodada'] ?? 0);
        $pdo->prepare("UPDATE ao_rodadas SET status = 'executando' WHERE id = ?")->execute([$id]);
        ao_json(ao_rodada_view($pdo, $id));
    }
    if ($a === 'reconferir') {
        $id = (int)($in['rodada'] ?? 0);
        $pdo->prepare("UPDATE ao_itens SET status = 'enviado', passo = 3, falhas = 0, msg = 'reconferindo…' WHERE rodada_id = ? AND status IN ('aviso','nao_aplicado') AND enviado_em > 0")->execute([$id]);
        $pdo->prepare("UPDATE ao_rodadas SET status = 'executando' WHERE id = ?")->execute([$id]);
        ao_json(ao_rodada_view($pdo, $id));
    }
    if ($a === 'desfazer') {
        $id = (int)($in['rodada'] ?? 0);
        $rod = $pdo->query('SELECT * FROM ao_rodadas WHERE id = ' . $id)->fetch();
        if (!$rod) ao_json(['erro' => 'rodada não encontrada'], 404);
        $emp = ao_empresa((string)$rod['empresa']);
        $st = $pdo->prepare("SELECT * FROM ao_itens WHERE rodada_id = ? AND status IN ('conferido','aviso') AND payload IS NOT NULL"); $st->execute([$id]);
        $fixos = []; $escolha = []; $campos = []; $semVolta = [];
        foreach ($st->fetchAll() as $i) {
            $antes = json_decode((string)$i['antes'], true) ?: []; $pay = json_decode((string)$i['payload'], true) ?: [];
            $f = [];
            foreach ($pay as $c => $v) {
                if ($c === 'obs' || $c === 'pix') { $semVolta[$c] = true; continue; }
                $f[$c] = $antes[$c] ?? null; $campos[$c] = true;
            }
            if ($f) { $fixos[$i['tipo'] . $i['omie_id']] = $f; $escolha[] = [$i['tipo'], (int)$i['omie_id']]; }
        }
        if (!$fixos) ao_json(['erros' => ['não há o que desfazer nesta rodada' . ($semVolta ? ' (observação e forma de pagamento Pix não têm como voltar pela API)' : '')]]);
        [$titulos, $tipos] = ao_titulos_escolhidos($emp, $escolha);
        $ctx = AoCache::contexto($emp);
        $ops = array_map(fn($c) => ['campo' => $c], array_keys($campos));
        $res = AoRegras::preparar($titulos, [], ['ordem' => 'tabela'], $ctx, $fixos);
        $nid = ao_criar_rodada($pdo, $u, $emp, $tipos, $ops, ['ordem' => 'tabela'], $res, $ctx, $id, $fixos);
        $v = ao_rodada_view($pdo, $nid);
        if ($semVolta) $v['aviso'] = 'Observação e forma de pagamento Pix não voltam: o Omie só acrescenta linhas na observação e não devolve a chave Pix antiga.';
        ao_json($v);
    }
    if ($a === 'receita_salvar') {
        $nome = trim((string)($in['nome'] ?? ''));
        if ($nome === '' || !($in['ops'] ?? null)) ao_json(['erro' => 'dê um nome e escolha as alterações'], 400);
        $pdo->prepare('INSERT INTO ao_receitas (nome, ops, opts, criado_por) VALUES (?,?,?,?)')->execute([mb_substr($nome, 0, 120), json_encode($in['ops'], JSON_UNESCAPED_UNICODE), json_encode($in['opts'] ?? [], JSON_UNESCAPED_UNICODE), $u['id']]);
        ao_json(['ok' => true]);
    }
    if ($a === 'receita_excluir') {
        $pdo->prepare('DELETE FROM ao_receitas WHERE id = ?')->execute([(int)($in['id'] ?? 0)]);
        ao_json(['ok' => true]);
    }
    ao_json(['erro' => 'ação inválida'], 400);
} catch (OmieContinuar $e) {
    ao_json(['ok' => true, 'continuar' => true, 'msg' => $e->getMessage()]);
} catch (Throwable $e) {
    ao_json(['erro' => preg_replace('/^Omie:\s*(ERROR:\s*)?/u', 'Omie: ', $e->getMessage())], 500);
}

/* ---------------- helpers ---------------- */

function ao_opts(array $o): array
{
    return ['ordem' => in_array($o['ordem'] ?? '', ['venc', 'prev', 'emis', 'id', 'forn', 'tabela'], true) ? $o['ordem'] : 'venc',
        'seq' => ['inicio' => (int)($o['seq']['inicio'] ?? 1), 'passo' => (int)($o['seq']['passo'] ?? 1) ?: 1, 'digitos' => max(1, min(10, (int)($o['seq']['digitos'] ?? 3))), 'reinicia' => ($o['seq']['reinicia'] ?? '') === 'forn' ? 'forn' : 'nada'],
        'seqdata' => ['inicio' => (string)($o['seqdata']['inicio'] ?? ''), 'passo' => (int)($o['seqdata']['passo'] ?? 1) ?: 1, 'unidade' => ($o['seqdata']['unidade'] ?? 'm') === 'd' ? 'd' : 'm', 'fmt' => (string)($o['seqdata']['fmt'] ?? 'dd/mm/aaaa')]];
}

/** títulos do cache na ordem em que vieram da tela. @return array{0: list<array>, 1: string} (títulos, tipos P|R|PR) */
function ao_titulos_escolhidos(string $emp, array $itens): array
{
    if (!$itens) ao_json(['erros' => ['selecione pelo menos um título']]);
    if (count($itens) > 2000) ao_json(['erros' => ['no máximo 2.000 títulos por rodada']]);
    $cache = ['P' => AoCache::titulos($emp, 'P')['itens'] ?? [], 'R' => AoCache::titulos($emp, 'R')['itens'] ?? []];
    $out = []; $tp = [];
    foreach ($itens as $x) {
        $tipo = ($x[0] ?? 'P') === 'R' ? 'R' : 'P'; $t = $cache[$tipo][(string)(int)($x[1] ?? 0)] ?? null;
        if (!$t) continue;
        $t['tipo'] = $tipo; $out[] = $t; $tp[$tipo] = true;
    }
    if (!$out) ao_json(['erros' => ['os títulos selecionados não estão mais na lista — atualize os títulos']]);
    ksort($tp);
    return [$out, implode('', array_keys($tp))];
}

function ao_linha_previa(array $x, array $ctx): array
{
    $t = $x['t']; $r = $x['r']; $nomes = AoCache::nomes($ctx);
    $campos = [];
    foreach ($r['novo'] as $c => $v) $campos[] = ['c' => $c, 'rot' => AoTitulo::rotulo($c, $t['tipo']), 'antes' => AoTitulo::mostrar($c, AoTitulo::valor($t, $c), $nomes), 'depois' => AoTitulo::mostrar($c, $v, $nomes)];
    return ['tipo' => $t['tipo'], 'id' => $t['id'], 'nome' => $nomes['forn'][(string)$t['forn']] ?? (string)$t['forn'], 'venc' => $t['venc'], 'valor' => $t['valor'], 'status' => $t['status'],
        'seq' => $x['pos'] + 1, 'total' => $x['total'], 'campos' => $campos, 'avisos' => $r['avisos'],
        'bloqueios' => array_map(fn($c, $m) => ($c === '*' ? '' : AoTitulo::rotulo($c, $t['tipo']) . ': ') . $m, array_keys($r['bloqueios']), $r['bloqueios'])];
}

function ao_criar_rodada(PDO $pdo, array $u, string $emp, string $tipos, array $ops, array $opts, array $res, array $ctx, ?int $desfaz, array $fixos = []): int
{
    $pdo->beginTransaction();
    $resumo = $desfaz ? 'Desfazer a rodada #' . $desfaz . ' (' . implode(', ', array_map(fn($o) => AoTitulo::rotulo($o['campo']), $ops)) . ')' : AoRegras::resumo($ops);
    $pdo->prepare('INSERT INTO ao_rodadas (empresa, tipos, resumo, ops, opts, desfaz_id, criado_por, n_itens) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$emp, $tipos, $resumo, json_encode($ops, JSON_UNESCAPED_UNICODE), json_encode($opts, JSON_UNESCAPED_UNICODE), $desfaz, $u['id'], count($res)]);
    $rid = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO ao_itens (rodada_id, tipo, omie_id, ci, nome, pos, total, antes, alvo, fixos, status, msg) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($res as $x) {
        $t = $x['t']; $r = $x['r'];
        $bl = array_map(fn($c, $m) => ($c === '*' ? '' : AoTitulo::rotulo($c, $t['tipo']) . ': ') . $m, array_keys($r['bloqueios']), $r['bloqueios']);
        if ($r['novo']) { $st = 'pendente'; $msg = $bl ? 'parte não será alterada — ' . implode('; ', $bl) : ''; }
        elseif ($bl) { $st = 'bloqueado'; $msg = implode('; ', $bl); }
        else { $st = 'ja_ok'; $msg = 'nada a mudar neste título'; }
        $f = $fixos[$t['tipo'] . $t['id']] ?? null;
        $ins->execute([$rid, $t['tipo'], $t['id'], $t['ci'], mb_substr((string)($ctx['forn'][(string)$t['forn']] ?? ''), 0, 200), $x['pos'], $x['total'],
            json_encode($t, JSON_UNESCAPED_UNICODE), json_encode($r['novo'], JSON_UNESCAPED_UNICODE), $f ? json_encode($f, JSON_UNESCAPED_UNICODE) : null, $st, $msg]);
    }
    $pdo->commit();
    ao_fechar_se_terminou($pdo, $rid);
    return $rid;
}

function ao_item(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT * FROM ao_itens WHERE id = ?'); $st->execute([$id]);
    $it = $st->fetch(); if (!$it) ao_json(['erro' => 'item não encontrado'], 404);
    return $it;
}

function ao_item_view(array $it, ?array $nomes): array
{
    $nomes = $nomes ?? [];
    $antes = json_decode((string)$it['antes'], true) ?: []; $alvo = json_decode((string)$it['alvo'], true) ?: [];
    $campos = [];
    foreach ($alvo as $c => $v) $campos[] = ['c' => $c, 'rot' => AoTitulo::rotulo($c, (string)$it['tipo']), 'antes' => AoTitulo::mostrar($c, AoTitulo::valor($antes, $c), $nomes), 'alvo' => AoTitulo::mostrar($c, $v, $nomes)];
    return ['id' => (int)$it['id'], 'tipo' => $it['tipo'], 'omie_id' => (string)$it['omie_id'], 'ci' => $it['ci'], 'nome' => $it['nome'], 'seq' => (int)$it['pos'] + 1,
        'status' => $it['status'], 'status_rot' => AO_STATUS[$it['status']] ?? $it['status'], 'msg' => (string)$it['msg'], 'passo' => (int)$it['passo'],
        'venc' => (string)($antes['venc'] ?? ''), 'valor' => (float)($antes['valor'] ?? 0), 'campos' => $campos];
}

function ao_rodada_view(PDO $pdo, int $id): array
{
    $r = $pdo->query('SELECT r.*, us.nome AS por FROM ao_rodadas r LEFT JOIN users us ON us.id = r.criado_por WHERE r.id = ' . $id)->fetch();
    if (!$r) ao_json(['erro' => 'rodada não encontrada'], 404);
    $nomes = null;
    try { $nomes = AoCache::nomes(AoCache::contexto((string)$r['empresa'])); } catch (Throwable $e) {}
    $st = $pdo->prepare('SELECT * FROM ao_itens WHERE rodada_id = ? ORDER BY pos, id'); $st->execute([$id]);
    return ['rodada' => ['id' => (int)$r['id'], 'empresa' => $r['empresa'], 'empresa_nome' => ao_empresas()[$r['empresa']] ?? $r['empresa'], 'tipos' => $r['tipos'], 'resumo' => $r['resumo'],
            'status' => $r['status'], 'por' => $r['por'], 'em' => date('d/m/Y H:i', strtotime($r['criado_em'])), 'desfaz' => $r['desfaz_id'] ? (int)$r['desfaz_id'] : null],
        'itens' => array_map(fn($i) => ao_item_view($i, $nomes), $st->fetchAll())];
}

function ao_fechar_se_terminou(PDO $pdo, int $rid): void
{
    $n = (int)$pdo->query("SELECT COUNT(*) FROM ao_itens WHERE rodada_id = $rid AND status IN ('pendente','enviado')")->fetchColumn();
    if ($n === 0) $pdo->exec("UPDATE ao_rodadas SET status = 'concluida' WHERE id = $rid");
}
