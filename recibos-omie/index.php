<?php
/* =====================================================================
   recibos-omie/index.php — "Recibos do Omie": gera a Declaração e Recibo
   (mesmo modelo da Capa Financeira) a partir das contas a pagar do Omie,
   vindas do relatório .xlsx "Finanças - Contas a Pagar" ou direto da API
   (período selecionável). Valida se cada título existe no Omie.
   GET  filtros de histórico · ?baixar=ID · ?zip=1,2,3
   POST form: buscar (api) | relatorio (xlsx)
   POST JSON: acao gerar | cancelar
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/../capa-financeira/_comum.php';   // helpers, cf_pessoas, cf_config, Pix, Parser
require_once __DIR__ . '/../capa-financeira/lib/Recibo.php';
require_once __DIR__ . '/lib/OmieApi.php';
require_once __DIR__ . '/lib/Titulo.php';

$u = require_login();
$pdo = db();
$gestor = user_has_tool('recibos-omie', $u) || user_has_tool('capa-financeira', $u);
$minhaPessoa = null;
if (!$gestor) {
    if ((string)cf_config('corretor_baixa_recibo', '1') !== '1' || empty($u['broker_id'])) { http_response_code(403); exit('Acesso negado.'); }
    $q = $pdo->prepare('SELECT * FROM cf_pessoas WHERE broker_id = ? LIMIT 1'); $q->execute([(string)$u['broker_id']]);
    $minhaPessoa = $q->fetch() ?: null;
    if (!$minhaPessoa) { http_response_code(403); exit('Seu usuário ainda não está vinculado a uma pessoa do financeiro.'); }
}
$empresas = array_column(cf_config('empresas', []), null, 'id');
$contasApi = OmieApi::contas();
$cidade = (string)cf_config('cidade_recibo', 'Joinville');
$pessoas = cf_pessoas(false);

function ro_pode(array $r, bool $gestor, ?array $minha): bool
{
    if ($gestor) return true;
    if (!$minha) return false;
    if ($r['pessoa_id'] && (int)$r['pessoa_id'] === (int)$minha['id']) return true;
    $d = preg_replace('/\D+/', '', (string)$r['fornecedor_doc']);
    return $d !== '' && in_array($d, [preg_replace('/\D+/', '', (string)$minha['cnpj']), preg_replace('/\D+/', '', (string)$minha['cpf'])], true);
}
function ro_empresa(array $empresas, string $conta): array
{
    return $empresas[$conta] ?? ['id' => $conta, 'nome' => strtoupper($conta), 'razao' => strtoupper($conta), 'cnpj' => ''];
}

/** regera o PDF de um recibo cujo arquivo não está mais no servidor, a partir dos itens guardados (mesmo número e data). @return ?array linha atualizada */
function ro_regerar(PDO $pdo, array $r, array $pessoas, array $empresas, string $cidade): ?array
{
    $itens = json_decode((string)($r['itens'] ?? ''), true);
    if (!is_array($itens) || !$itens) return null;
    $t0 = $itens[0];
    $p = null; if (!empty($r['pessoa_id'])) foreach ($pessoas as $px) if ((int)$px['id'] === (int)$r['pessoa_id']) { $p = $px; break; }
    $p = $p ?: (Titulo::pessoaPorDoc($pessoas, (string)($t0['doc'] ?? $r['fornecedor_doc'])) ?: Titulo::pessoaDoFornecedor($t0));
    if (trim((string)($p['razao_social'] ?? '')) === '' && trim((string)($t0['razao'] ?? '')) !== '') $p['razao_social'] = trim((string)$t0['razao']);
    $emp = ro_empresa($empresas, (string)$r['conta']);
    $linhas = [];
    foreach ($itens as $t) { $cod = preg_replace('/\D+/', '', (string)($t['obs']['cod'] ?? '')); $ln = Titulo::linhaRecibo($t, (string)($t['numero'] ?? ''), $p); if ($cod !== '') { $qb = $pdo->prepare("SELECT bairro FROM cf_capas WHERE cod = ? AND status = 'confirmada' ORDER BY id DESC LIMIT 1"); $qb->execute([$cod]); $ln['capa_bairro'] = (string)($qb->fetchColumn() ?: ''); } $linhas[] = $ln; }
    $logo = !empty($emp['logo_propria']) && is_file(__DIR__ . '/../capa-financeira/modelos/logo_' . $emp['id'] . '.jpg') ? __DIR__ . '/../capa-financeira/modelos/logo_' . $emp['id'] . '.jpg' : null;
    try { $g = Recibo::gerar($linhas, $p, $emp, (string)$r['data_recibo'], $cidade, $logo); } catch (Throwable $e) { return null; }
    $dir = cf_data_dir() . '/recibos-omie/' . date('Y'); if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $nome = Recibo::nomeArquivo((string)$r['numero'], (string)($p['nome'] ?? $r['fornecedor_nome']), (string)$r['data_recibo']);
    $path = $dir . '/' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4) . '-regerado-' . $nome;
    if (@file_put_contents($path, $g['pdf']) === false || !is_file($path)) return null;
    $pdo->prepare('UPDATE ro_recibos SET arquivo_nome = ?, arquivo_path = ? WHERE id = ?')->execute([$nome, $path, (int)$r['id']]);
    $r['arquivo_nome'] = $nome; $r['arquivo_path'] = $path;
    return $r;
}

/* ---------- downloads ---------- */
if (isset($_GET['baixar'])) {
    $st = $pdo->prepare('SELECT * FROM ro_recibos WHERE id = ?'); $st->execute([(int)$_GET['baixar']]); $r = $st->fetch();
    if (!$r || !ro_pode($r, $gestor, $minhaPessoa)) { http_response_code(404); exit('recibo não encontrado'); }
    if (!is_file($r['arquivo_path'])) {
        // PDF sumiu (ex.: nome de arquivo longo demais na geração — bug corrigido em 06/11/2026): regera a partir dos itens guardados, mesmo número e data
        $r = ro_regerar($pdo, $r, $pessoas, $empresas, $cidade);
        if (!$r) { http_response_code(404); exit('recibo não encontrado: o PDF não está no servidor e não foi possível regerá-lo (sem os itens guardados) — cancele o recibo e gere de novo'); }
    }
    header('Content-Type: application/pdf'); header('Content-Disposition: ' . (isset($_GET['ver']) ? 'inline' : 'attachment') . '; filename="' . $r['arquivo_nome'] . '"');
    header('Content-Length: ' . filesize($r['arquivo_path'])); readfile($r['arquivo_path']); exit;
}
if (isset($_GET['zip'])) {
    $ids = array_filter(array_map('intval', explode(',', (string)$_GET['zip']))); if (!$ids) exit('nada');
    $st = $pdo->query('SELECT * FROM ro_recibos WHERE id IN (' . implode(',', $ids) . ')');
    $tmp = tempnam(sys_get_temp_dir(), 'ro'); $z = new ZipArchive(); $z->open($tmp, ZipArchive::OVERWRITE); $n = 0;
    foreach ($st->fetchAll() as $r) { if (!ro_pode($r, $gestor, $minhaPessoa)) continue; if (!is_file($r['arquivo_path'])) $r = ro_regerar($pdo, $r, $pessoas, $empresas, $cidade); if ($r && is_file($r['arquivo_path'])) { $z->addFile($r['arquivo_path'], $r['arquivo_nome']); $n++; } }
    $z->close(); if (!$n) { @unlink($tmp); exit('nada para baixar'); }
    header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="recibos_omie_' . date('Ymd-Hi') . '.zip"'); header('Content-Length: ' . filesize($tmp));
    readfile($tmp); @unlink($tmp); exit;
}

/** identidades pelas quais um título pode estar ligado a um recibo (a atual e a assinatura legada) */
function ro_fps(array $t): array { return array_values(array_unique(array_filter([(string)($t['fingerprint'] ?? ''), (string)($t['fingerprint_legado'] ?? '')]))); }
/** recibo ATUAL que já contém este título (ou null) */
function ro_recibo_do_titulo(PDO $pdo, array $t): ?array
{
    static $porForn = [];   // recibos atuais por fornecedor (cache da requisição) — para o casamento por valor+vencimento
    $fps = ro_fps($t); if (!$fps) return null;
    $q = $pdo->prepare("SELECT r.id, r.numero, r.data_recibo, r.itens FROM ro_recibo_itens i JOIN ro_recibos r ON r.id = i.recibo_id WHERE i.fingerprint IN (" . implode(',', array_fill(0, count($fps), '?')) . ") AND r.status = 'atual' ORDER BY r.id DESC LIMIT 1");
    $q->execute($fps); if ($r = $q->fetch()) return $r;
    // recibos antigos foram gravados com assinaturas de uma leitura anterior (COD/observação podem ter mudado): casa pelo que não muda —
    // mesmo fornecedor, valor e vencimento (e, quando os dois lados têm, mesmo nº de recibo / COD)
    $k = ($t['conta'] ?? '') . '|' . ($t['doc_digitos'] ?? '');
    if (!isset($porForn[$k])) { $q2 = $pdo->prepare("SELECT id, numero, data_recibo, itens FROM ro_recibos WHERE status = 'atual' AND conta = ? AND REPLACE(REPLACE(REPLACE(fornecedor_doc,'.',''),'/',''),'-','') = ? ORDER BY id DESC LIMIT 60"); $q2->execute([(string)($t['conta'] ?? ''), (string)($t['doc_digitos'] ?? '')]); $porForn[$k] = $q2->fetchAll(); }
    $rec = (string)($t['obs']['recibo'] ?? ''); $cod = (string)($t['obs']['cod'] ?? '');
    foreach ($porForn[$k] as $r) foreach (json_decode((string)$r['itens'], true) ?: [] as $it) {
        if (abs((float)($it['valor'] ?? -1) - (float)$t['valor']) > 0.005 || (string)($it['vencimento'] ?? '') !== (string)($t['vencimento'] ?? '')) continue;
        $itRec = (string)($it['obs']['recibo'] ?? $it['numero'] ?? ''); $itCod = (string)($it['obs']['cod'] ?? '');
        if ($rec !== '' && $itRec !== '' && strcasecmp($rec, $itRec) !== 0 && preg_match('/^(\d{1,6}-[PR]\d{3}|REC\d{4}-P\d{3})$/i', $itRec)) continue;
        if ($cod !== '' && $itCod !== '' && $cod !== $itCod) continue;
        return $r;
    }
    return null;
}
/** nº do recibo: RECIBO da observação → já emitido para o mesmo título → sequência do COD (cf_sequencias) → REC-<ano>-n */
function ro_numero(PDO $pdo, array $t): string
{
    // nº vindo da observação só vale se tiver cara de código nosso (1234-P001 / REC2026-P001); "9" ou lixo é ignorado
    if (!empty($t['obs']['recibo']) && preg_match('/^(\d{1,6}-[PR]\d{3}|REC\d{4}-P\d{3})$/i', trim((string)$t['obs']['recibo']))) return strtoupper(trim((string)$t['obs']['recibo']));
    if (!empty($t['cod_int'])) return (string)$t['cod_int'];
    $fps = ro_fps($t);
    $st = $pdo->prepare("SELECT r.numero, r.itens FROM ro_recibo_itens i JOIN ro_recibos r ON r.id = i.recibo_id WHERE i.fingerprint IN (" . implode(',', array_fill(0, count($fps), '?')) . ") ORDER BY r.id DESC LIMIT 1");
    $st->execute($fps);
    if ($r = $st->fetch()) { foreach (json_decode((string)$r['itens'], true) ?: [] as $it) if (in_array((string)($it['fingerprint'] ?? ''), $fps, true) && !empty($it['numero']) && preg_match('/^(\d{1,6}-[PR]\d{3}|REC\d{4}-P\d{3})$/i', (string)$it['numero'])) return (string)$it['numero']; }
    $cod = preg_replace('/\D+/', '', (string)($t['obs']['cod'] ?? '')) ?: ('REC' . date('Y'));
    $pdo->prepare('INSERT INTO cf_sequencias (cod, tipo, ultimo) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE ultimo = ultimo + 1')->execute([$cod, 'P']);
    $q = $pdo->prepare('SELECT ultimo FROM cf_sequencias WHERE cod = ? AND tipo = ?'); $q->execute([$cod, 'P']);
    return $cod . '-P' . str_pad((string)(int)$q->fetchColumn(), 3, '0', STR_PAD_LEFT);
}

/** linha do título para o modal (dados já interpretados: pessoa, recibo existente, marcações) */
function ro_linha(PDO $pdo, array $pessoas, array $t): array
{
    $p = Titulo::pessoaPorDoc($pessoas, $t['doc']); $o = $t['obs']; $ja = ro_recibo_do_titulo($pdo, $t);
    $naoAchou = $t['origem'] === 'xlsx' && str_starts_with((string)($t['validado'] ?? ''), 'NÃO');
    $pago = in_array($t['situacao'], ['Pago', 'Cancelado'], true);   // "Pago parcialmente" ainda tem saldo: continua selecionável
    // repasse a construtora/terceiro não tem recibo — mas "Repasse de bonificação" é o bônus pago ao corretor (natureza BONUS) e tem recibo normal
    $repasse = (stripos((string)$t['categoria'], 'repasse') !== false && stripos((string)$t['categoria'], 'bonific') === false) || stripos((string)$t['nota_fiscal'], 'REPASSE') !== false || ($o['natureza'] ?? '') === 'REPASSE';
    // ordem de prioridade: "já com recibo" vence "pago" (um título pago normalmente já tem recibo — é isso que interessa ver)
    $classe = $naoAchou ? 'nao' : ($ja ? 'ja' : ($repasse ? 'repasse' : ($pago ? 'pago' : 'novo')));
    $jc = $ja ? array_values(array_filter(array_map('trim', explode(' / ', (string)$ja['numero'])))) : [];
    return ['t' => $t, 'classe' => $classe, 'pessoa' => $p ? (string)$p['nome'] : '', 'ja' => $ja ? ['id' => (int)$ja['id'], 'numero' => (string)$ja['numero'], 'curto' => implode(' / ', array_slice($jc, 0, 2)) . (count($jc) > 2 ? ' +' . (count($jc) - 2) : ''), 'data' => (string)$ja['data_recibo']] : null,
            'funcao' => (string)($o['funcao'] ?: Titulo::funcaoDaCategoria((string)$t['categoria'])), 'bonus' => ($o['natureza'] ?? '') === 'BONUS', 'repasse' => $repasse, 'sem_obs' => ($o['padrao'] ?? '') === 'vazio',
            'cliente' => (string)($o['cliente'] ?? ''), 'imovel' => (string)($o['imovel'] ?? ''), 'cod' => (string)($o['cod'] ?? ''), 'venda' => (string)($o['venda'] ?? ''),
            'final' => round((float)$t['valor'] - (float)($t['desconto'] ?? 0) - (float)($t['impostos'] ?? 0) + (float)($t['juros'] ?? 0), 2), 'grupo' => $t['conta'] . '|' . $t['doc_digitos'] . '|' . ($t['previsao'] ?: $t['vencimento']),
            'numero_obs' => (string)($o['recibo'] ?? ''), 'validado' => (string)($t['validado'] ?? '')];
}
/** confere um título do .xlsx no Omie (CNPJ/CPF + vencimento + valor): preenche omie_id, cod_int, validado, obs_baixa */
function ro_validar(string $conta, array &$t, array &$cache): void
{
    if ($t['doc'] === '' || !$t['vencimento']) { $t['validado'] = 'sem CNPJ/vencimento p/ conferir'; return; }
    $k = $t['doc'] . '|' . $t['vencimento'];
    try { $cache[$k] ??= OmieApi::procurarTitulo($conta, $t['doc'], $t['vencimento']); }
    catch (Throwable $e) { $t['validado'] = 'erro ao consultar: ' . $e->getMessage(); return; }
    $ach = null;
    foreach ($cache[$k] as $c) if (abs((float)($c['cabecTitulo']['nValorTitulo'] ?? 0) - (float)$t['valor']) < 0.005) { $ach = $c; break; }
    if ($ach) { $t['omie_id'] = (int)$ach['cabecTitulo']['nCodTitulo']; $t['cod_int'] = (string)($ach['cabecTitulo']['cCodIntTitulo'] ?? ''); $t['validado'] = 'no Omie: ' . Titulo::situacaoCompleta($ach); $t['obs_baixa'] = Titulo::obsBaixa($ach); }
    else $t['validado'] = 'NÃO ENCONTRADO no Omie';
}

/* ---------- gerar (JSON) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    header('Content-Type: application/json; charset=utf-8');
    if (!$gestor) { http_response_code(403); exit(json_encode(['ok' => false, 'erro' => 'sem permissão'])); }
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $_POST['csrf'] = $in['csrf'] ?? ''; csrf_check();
    $falha = function (string $m, int $c = 400): never { http_response_code($c); exit(json_encode(['ok' => false, 'erro' => $m], JSON_UNESCAPED_UNICODE)); };
    try {
        if (($in['acao'] ?? '') === 'cancelar') {
            if (!$gestor) $falha('só o financeiro pode cancelar recibos', 403);
            $id = (int)($in['id'] ?? 0); $motivo = mb_substr(trim((string)($in['motivo'] ?? '')), 0, 200);
            $q = $pdo->prepare('SELECT id, numero, status FROM ro_recibos WHERE id = ?'); $q->execute([$id]); $r = $q->fetch();
            if (!$r) $falha('recibo não encontrado: pode já ter sido removido. Atualize a página e confira a lista.', 404);
            if ($r['status'] !== 'atual') $falha('o recibo ' . $r['numero'] . ' já está ' . $r['status'] . ' — nada a cancelar. Para emitir outro, busque o título de novo e marque "gerar de novo".');
            $pdo->prepare("UPDATE ro_recibos SET status = 'cancelado', motivo = ?, encerrado_por = ?, encerrado_em = NOW() WHERE id = ?")->execute([$motivo ?: null, $u['id'], $id]);
            exit(json_encode(['ok' => true, 'numero' => $r['numero']]));
        }
        if (($in['acao'] ?? '') === 'validar') {
            // confere no Omie os títulos do relatório, em blocos com orçamento de tempo (~20 s): devolve as linhas conferidas e os índices que sobraram
            $conta = (string)($in['conta'] ?? ''); if (!isset($contasApi[$conta])) $falha('API do Omie não configurada para esta empresa');
            if (($resta = OmieApi::bloqueadoPor($conta)) > 0) $falha('A API do Omie está bloqueada temporariamente (anti-abuso). Aguarde ' . ceil($resta / 60) . ' min; enquanto isso dá para gerar marcando "incluir títulos não encontrados".');
            $t0 = microtime(true); $cache = []; $linhas = []; $restantes = [];
            foreach ((array)($in['itens'] ?? []) as $i => $t) {
                if ((microtime(true) - $t0) > 20) { $restantes[] = (int)($t['_i'] ?? $i); continue; }
                $t = Titulo::normalizar($t); ro_validar($conta, $t, $cache);
                $linhas[] = ro_linha($pdo, $pessoas, $t) + ['_i' => (int)($t['_i'] ?? $i)];
            }
            exit(json_encode(['ok' => true, 'linhas' => $linhas, 'restantes' => $restantes], JSON_UNESCAPED_UNICODE));
        }
        if (($in['acao'] ?? '') === 'restaurar') {
            // desfaz um cancelamento/substituição: o recibo volta a "atual" (se nenhum título dele estiver em outro recibo atual)
            $id = (int)($in['id'] ?? 0);
            $q = $pdo->prepare('SELECT id, numero, status, itens FROM ro_recibos WHERE id = ?'); $q->execute([$id]); $r = $q->fetch();
            if (!$r) $falha('recibo não encontrado — atualize a página', 404);
            if ($r['status'] === 'atual') $falha('o recibo ' . $r['numero'] . ' já está atual');
            $conf = [];
            foreach (json_decode((string)$r['itens'], true) ?: [] as $it) { $it = Titulo::normalizar($it); if (($o = ro_recibo_do_titulo($pdo, $it)) && (int)$o['id'] !== $id) $conf[$o['numero']] = $o['numero']; }
            if ($conf) $falha('não dá para restaurar o ' . $r['numero'] . ': títulos dele estão no(s) recibo(s) atual(is) ' . implode(', ', $conf) . '. Cancele esse(s) primeiro.');
            $pdo->prepare("UPDATE ro_recibos SET status = 'atual', substituido_por = NULL, motivo = NULL, encerrado_por = NULL, encerrado_em = NULL WHERE id = ?")->execute([$id]);
            exit(json_encode(['ok' => true, 'numero' => $r['numero']]));
        }
        if (!in_array($in['acao'] ?? '', ['gerar', 'prever'], true)) $falha('ação inválida');
        $prever = ($in['acao'] === 'prever');
        $itens = (array)($in['itens'] ?? []); if (!$itens) $falha('nenhum título selecionado');
        $agrupar = !empty($in['agrupar']); $substituir = !empty($in['substituir']);
        $dataFixa = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['data'] ?? '')) ? (string)$in['data'] : null;
        $grupos = []; $sel = []; $antigosInfo = [];
        foreach ($itens as $t) {
            if (empty($t['fingerprint']) || !isset($t['valor']) || empty($t['conta'])) $falha('título inválido');
            $t = Titulo::normalizar($t);   // reconfere obs/fingerprint do lado do servidor
            $ja = ro_recibo_do_titulo($pdo, $t);
            if ($ja && !$substituir) $falha('já existe recibo ' . $ja['numero'] . ' para ' . $t['razao'] . ' ' . cf_brl($t['valor']) . ' venc. ' . cf_data_br($t['vencimento']) . '. Marque "gerar de novo" para substituir.');
            $t['_antigo'] = $ja['id'] ?? null;
            if ($ja) { $antigosInfo[(int)$ja['id']] ??= ['id' => (int)$ja['id'], 'numero' => (string)$ja['numero'], 'data' => (string)$ja['data_recibo'], 'itens' => json_decode((string)$ja['itens'], true) ?: [], 'selecionados' => 0]; $antigosInfo[(int)$ja['id']]['selecionados']++; }
            foreach (ro_fps($t) as $fp) $sel[$fp] = true;
            $k = $agrupar ? $t['conta'] . '|' . $t['doc_digitos'] . '|' . ($dataFixa ?: ($t['previsao'] ?: $t['vencimento'])) : $t['fingerprint'];
            $grupos[$k][] = $t;
        }
        // "gerar de novo": o recibo antigo é substituído e o NOVO recibo contém SÓ os títulos selecionados (decisão do Jhony, 07/11/2026).
        // Títulos do recibo antigo que não estão na seleção ficam sem recibo (voltam a aparecer como "novos" na próxima leitura) — a prévia avisa quantos.
        foreach ($antigosInfo as $aid => &$ai) {
            $fora = [];
            foreach ($ai['itens'] as $it) { $it = Titulo::normalizar($it); if (!array_intersect(ro_fps($it), array_keys($sel))) $fora[] = ($it['obs']['recibo'] ?? $it['numero'] ?? '?') . ' ' . cf_brl((float)($it['valor'] ?? 0)); }
            $ai['total'] = count($ai['itens']); $ai['fora'] = count($fora); $ai['fora_lista'] = array_slice($fora, 0, 8); unset($ai['itens']);
        }
        unset($ai);
        if ($prever) {
            $nRec = count($grupos); $nTit = array_sum(array_map('count', $grupos));
            exit(json_encode(['ok' => true, 'recibos' => $nRec, 'titulos' => $nTit, 'substituir' => array_values($antigosInfo)], JSON_UNESCAPED_UNICODE));
        }
        $dir = cf_data_dir() . '/recibos-omie/' . date('Y'); if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $gerados = []; $resumo = [];
        $pdo->beginTransaction();
        foreach ($grupos as $g) {
            $t0 = $g[0]; $p = Titulo::pessoaPorDoc($pessoas, $t0['doc']) ?: Titulo::pessoaDoFornecedor($t0);
            // razão social que falta no dicionário vem do cadastro do Omie (quem assina o recibo)
            if (trim((string)($p['razao_social'] ?? '')) === '' && trim((string)$t0['razao']) !== '') $p['razao_social'] = trim((string)$t0['razao']);
            $emp = ro_empresa($empresas, $t0['conta']);
            $data = $dataFixa ?: (string)($t0['previsao'] ?: ($t0['vencimento'] ?: date('Y-m-d')));
            $linhas = []; $fps = []; $ids = []; $itensGravar = [];
            foreach ($g as $t) { $num = ro_numero($pdo, $t); $linhas[] = Titulo::linhaRecibo($t, $num, $p); $fps[] = $t['fingerprint']; if ($t['omie_id']) $ids[] = (int)$t['omie_id']; $t['numero'] = $num; unset($t['_antigo']); $itensGravar[] = $t; }
            $logo = !empty($emp['logo_propria']) && is_file(__DIR__ . '/../capa-financeira/modelos/logo_' . $emp['id'] . '.jpg') ? __DIR__ . '/../capa-financeira/modelos/logo_' . $emp['id'] . '.jpg' : null;
            // bairro da obra vem da capa no portal (pelo COD); sem capa, o Recibo extrai do texto do imóvel
            foreach ($linhas as &$ln) { $cod = preg_replace('/\D+/', '', (string)($ln['capa_cod'] ?? '')); if ($cod !== '') { $qb = $pdo->prepare("SELECT bairro FROM cf_capas WHERE cod = ? AND status = 'confirmada' ORDER BY id DESC LIMIT 1"); $qb->execute([$cod]); $ln['capa_bairro'] = (string)($qb->fetchColumn() ?: ''); } } unset($ln);
            $r = Recibo::gerar($linhas, $p, $emp, $data, $cidade, $logo);
            $nome = Recibo::nomeArquivo($r['numero'], $p['nome'], $data);
            $path = $dir . '/' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4) . '-' . $nome;
            if (@file_put_contents($path, $r['pdf']) === false || !is_file($path)) { $pdo->rollBack(); $falha('não consegui gravar o PDF do recibo ' . $r['numero'] . ' no servidor (' . (error_get_last()['message'] ?? 'sem detalhe') . '). Nada foi gravado — confira o espaço/permissões da pasta de dados e tente de novo.'); }
            $antigos = array_values(array_unique(array_filter(array_map(fn($t) => (int)($t['_antigo'] ?? 0), $g))));
            $pdo->prepare('INSERT INTO ro_recibos (conta, numero, fingerprints, omie_ids, fornecedor_doc, fornecedor_nome, pessoa_id, valor, bruto, descricao_nf, data_recibo, itens, origem, validado, arquivo_nome, arquivo_path, gerado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$t0['conta'], $r['numero'], json_encode($fps), json_encode($ids), (string)$t0['doc'], $r['recebedor'], $p['id'] ?? null, $r['valor'], $r['bruto'], $r['descricao_nf'], $data, json_encode($itensGravar, JSON_UNESCAPED_UNICODE), $t0['origem'], (string)($t0['validado'] ?? ($t0['origem'] === 'api' ? $t0['situacao'] : '')), $nome, $path, $u['id']]);
            $rid = (int)$pdo->lastInsertId();
            $todasFps = []; foreach ($g as $t) foreach (ro_fps($t) as $fp) $todasFps[] = $fp;
            $resumo[] = ['id' => $rid, 'numero' => $r['numero'], 'recebedor' => $r['recebedor'], 'valor' => $r['valor'], 'bruto' => $r['bruto'], 'descricao_nf' => $r['descricao_nf'], 'nf_encurtado' => $r['nf_encurtado'], 'link' => '?baixar=' . $rid . '&ver=1', 'fingerprints' => array_values(array_unique($todasFps))];
            foreach ($antigos as $aid) $pdo->prepare("UPDATE ro_recibos SET status = 'substituido', substituido_por = ?, encerrado_por = ?, encerrado_em = NOW() WHERE id = ? AND status = 'atual'")->execute([$rid, $u['id'], $aid]);
            $ins = $pdo->prepare('INSERT IGNORE INTO ro_recibo_itens (recibo_id, fingerprint) VALUES (?, ?)');
            foreach ($g as $t) foreach (ro_fps($t) as $fp) $ins->execute([$rid, $fp]);
            $gerados[] = $rid;
        }
        $pdo->commit();
        exit(json_encode(['ok' => true, 'ids' => $gerados, 'n' => count($gerados), 'recibos' => $resumo], JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $falha('erro: ' . $e->getMessage(), 500); }
}

/* ---------- buscar / relatório (form POST) ---------- */
$titulos = []; $origem = ''; $erroBusca = ''; $contaSel = (string)($_POST['conta'] ?? $_GET['conta'] ?? array_key_first($contasApi) ?? 'vertical');
$de = (string)($_POST['de'] ?? ''); $ate = (string)($_POST['ate'] ?? ''); $soAberto = isset($_POST['modo']) ? !empty($_POST['so_aberto']) : true;
$periodo = (string)($_POST['periodo'] ?? '7');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $gestor) {
    csrf_check();
    try {
        if (($_POST['modo'] ?? '') === 'api') {
            if (!isset($contasApi[$contaSel])) throw new RuntimeException('API do Omie não configurada para esta empresa (OMIE_CONTAS no config.php)');
            if ($periodo !== 'custom') {
                $hoje = new DateTimeImmutable('today');
                [$de, $ate] = match ($periodo) { 'hoje' => [$hoje, $hoje], 'amanha' => [$hoje->modify('+1 day'), $hoje->modify('+1 day')], 'atrasados' => [$hoje->modify('-120 days'), $hoje->modify('-1 day')],
                    default => [$hoje, $hoje->modify('+' . max(1, (int)$periodo) . ' days')] };
                $de = $de->format('Y-m-d'); $ate = $ate->format('Y-m-d');
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $de) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) throw new RuntimeException('informe o período');
            $cats = OmieApi::categorias($pdo, $contaSel);
            foreach (OmieApi::titulosPagar($contaSel, $de, $ate) as $t) {
                $n = Titulo::daApi($pdo, $contaSel, $t, $cats);
                if ($soAberto && (!empty($n['liquidado']) || $n['situacao'] === 'Cancelado')) continue;
                $n['validado'] = $n['situacao']; $titulos[] = $n;
            }
            $origem = 'api';
        } elseif (($_POST['modo'] ?? '') === 'xlsx') {
            $f = $_FILES['relatorio'] ?? null;
            if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !preg_match('/\.xlsx$/i', (string)$f['name'])) throw new RuntimeException('envie o relatório .xlsx exportado do Omie (Finanças › Contas a Pagar)');
            $rel = Titulo::doRelatorio($f['tmp_name']);
            $contaSel = $rel['conta'] ?: $contaSel; $origem = 'xlsx';
            $titulos = $rel['titulos'];
            if (!$contaSel) throw new RuntimeException('não reconheci a empresa no cabeçalho do relatório (esperava VERTICAL, CAMARGO ou MZ7): ' . $rel['empresa_txt']);
            // a conferência no Omie (CNPJ + vencimento + valor) é feita depois, pelo modal, em blocos (ação "validar") — não trava a leitura
            foreach ($titulos as &$t) { $t['validado'] = isset($contasApi[$contaSel]) ? 'conferindo…' : 'API não configurada — sem conferência'; } unset($t);
        }
    } catch (Throwable $e) { $erroBusca = $e->getMessage(); }
    // resposta para o modal (os formulários enviam por fetch)
    if (!empty($_POST['ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        if ($erroBusca) { http_response_code(400); exit(json_encode(['ok' => false, 'erro' => $erroBusca], JSON_UNESCAPED_UNICODE)); }
        $tA = microtime(true); $linhas = []; foreach ($titulos as $i => $t) $linhas[] = ro_linha($pdo, $pessoas, $t) + ['_i' => $i];
        exit(json_encode(['ok' => true, 'seg_linhas' => round(microtime(true) - $tA, 2), 'seg_total' => round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 2), 'origem' => $origem, 'conta' => $contaSel, 'empresa' => ro_empresa($empresas, $contaSel)['nome'], 'periodo' => $origem === 'api' ? cf_data_br($de) . ' a ' . cf_data_br($ate) : '', 'validar' => $origem === 'xlsx' && isset($contasApi[$contaSel]), 'linhas' => $linhas], JSON_UNESCAPED_UNICODE));
    }
}

/* ---------- histórico ---------- */
$f = ['busca' => trim((string)($_GET['busca'] ?? '')), 'de' => (string)($_GET['hde'] ?? ''), 'ate' => (string)($_GET['hate'] ?? ''), 'conta' => (string)($_GET['hconta'] ?? ''),
      'origem' => in_array($_GET['horigem'] ?? '', ['api', 'xlsx'], true) ? (string)$_GET['horigem'] : '', 'status' => in_array($_GET['hstatus'] ?? '', ['atual', 'substituido', 'cancelado', 'todos'], true) ? (string)$_GET['hstatus'] : 'todos'];
$sql = "SELECT r.*, u.nome AS por, e.nome AS encerrado_nome, s.numero AS subst_numero FROM ro_recibos r LEFT JOIN users u ON u.id = r.gerado_por LEFT JOIN users e ON e.id = r.encerrado_por LEFT JOIN ro_recibos s ON s.id = r.substituido_por WHERE 1"; $a = [];
if ($f['status'] !== 'todos') { $sql .= ' AND r.status = ?'; $a[] = $f['status']; }
if ($f['origem'] !== '') { $sql .= ' AND r.origem = ?'; $a[] = $f['origem']; }
if (!$gestor) { $sql .= ' AND (r.pessoa_id = ? OR r.fornecedor_doc IN (?, ?))'; array_push($a, (int)$minhaPessoa['id'], (string)$minhaPessoa['cnpj'], (string)$minhaPessoa['cpf']); }
if ($f['busca'] !== '') { $sql .= ' AND (r.numero LIKE ? OR r.fornecedor_nome LIKE ? OR r.itens LIKE ?)'; $b = '%' . $f['busca'] . '%'; array_push($a, $b, $b, $b); }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['de'])) { $sql .= ' AND r.data_recibo >= ?'; $a[] = $f['de']; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['ate'])) { $sql .= ' AND r.data_recibo <= ?'; $a[] = $f['ate']; }
if ($f['conta'] !== '') { $sql .= ' AND r.conta = ?'; $a[] = $f['conta']; }
$sql .= ' ORDER BY r.id DESC LIMIT 300';
$st = $pdo->prepare($sql); $st->execute($a); $historico = $st->fetchAll();

portal_header('Recibos do Omie', $u);
?>
<meta name="csrf" content="<?= h(csrf_token()) ?>">
<style>main.wrap{max-width:1600px}.ro-filtros{display:grid;grid-template-columns:minmax(220px,2fr) repeat(5,minmax(130px,1fr)) auto;gap:10px;align-items:end;margin-bottom:10px}.ro-filtros label{display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:600;min-width:0}.ro-filtros .cf-in{width:100%;min-width:0;padding:7px 9px;font:inherit;font-size:14px;border:2px solid var(--line);border-radius:6px;background:#fff;box-sizing:border-box}.ro-filtros .btn{height:38px}@media(max-width:1100px){.ro-filtros{grid-template-columns:repeat(3,1fr)}}@media(max-width:640px){.ro-filtros{grid-template-columns:1fr 1fr}}.ro-encerrado td{opacity:.72}#tbl-hist .cf-tag{white-space:normal;display:inline-block;line-height:1.3;margin-top:3px}.ro-val-ok{color:#2e6b3a;font-weight:600}.ro-val-nao{color:#b4512f;font-weight:700}.ro-per button{font:inherit;font-size:13px;padding:4px 10px;border-radius:6px;border:1px solid var(--line);background:#fff;cursor:pointer}.ro-per button.on{background:var(--moss);color:#fff;border-color:var(--moss)}
.ro-filtros label.on{color:#1f6b3a}.ro-filtros label.on .cf-in{border-color:#2f7a4b;background:#f1f8f3}.ro-filtros .ro-limpar{font-size:13px;align-self:end;padding-bottom:10px;white-space:nowrap}
.ro-abas button{font:inherit;font-size:13px;padding:5px 11px;border-radius:14px;border:1px solid var(--line);background:#fff;cursor:pointer}.ro-abas button.on{background:var(--moss);color:#fff;border-color:var(--moss)}
.ro-mais{display:inline-block;margin-top:3px;font-size:12px;color:#2f6b4f;cursor:pointer;text-decoration:underline dotted;white-space:nowrap}.ro-col{cursor:pointer}.ro-col .ro-resto{display:none}.ro-col.aberto .ro-resto{display:inline}
#tbl-hist td:nth-child(2){max-width:260px}#tbl-hist td:nth-child(7){max-width:320px}
details.ro-api{margin:0}details.ro-api summary{cursor:pointer;font-weight:700;font-size:16px;list-style:none;display:flex;align-items:center;gap:8px}details.ro-api summary::before{content:"▸";font-size:13px}details.ro-api[open] summary::before{content:"▾"}details.ro-api summary small{font-weight:400;color:#667;font-size:13px}
</style>
<div class="cf-rev">
<?php cf_cabecalho($gestor ? 'Recibos do Omie' : 'Meus recibos', $gestor ? 'Declaração e Recibo a partir das contas a pagar já registradas no Omie — busque pela API por período ou envie o relatório "Finanças › Contas a Pagar" (.xlsx). Mesmo modelo dos recibos da Capa Financeira.' : 'Recibos das suas comissões e bônus.', $gestor ? [['Recibos do Omie', null]] : [['Meus recibos', null]], 'recibos-omie'); ?>

<?= cf_recibos_nf_ui() ?>
<?php if ($gestor): ?>
<div class="cf-duas" style="display:grid;grid-template-columns:3fr 2fr;gap:14px;margin-bottom:14px;align-items:start">
  <form method="post" enctype="multipart/form-data" class="card cf-upload ro-form-xlsx" style="margin:0">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="modo" value="xlsx">
    <h2>Enviar relatório do Omie (.xlsx)</h2>
    <p class="cf-dica" style="margin-top:0">Omie › Finanças › Contas a Pagar › exportar. A empresa é lida do cabeçalho do relatório<?= $contasApi ? ' e cada título é conferido na API (CNPJ + vencimento + valor)' : '' ?>.</p>
    <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap"><label>Arquivo<input type="file" name="relatorio" accept=".xlsx" required></label><button class="btn" type="submit">Ler relatório</button></div>
  </form>
  <details class="ro-api card" style="margin:0" <?= $origem === 'api' || ($_POST['modo'] ?? '') === 'api' ? 'open' : '' ?>><summary>Buscar no Omie (API) <small>— alternativa ao relatório; pouco usada</small></summary>
  <form method="post" class="cf-upload ro-form-api" style="margin:10px 0 0;padding:0;border:0;box-shadow:none;background:none">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="modo" value="api">
    <?php if (!$contasApi): ?><p class="aviso">API não configurada: adicione <code>OMIE_CONTAS</code> no config.php da hospedagem (fora do public_html). Enquanto isso use o relatório .xlsx ao lado.</p><?php endif; ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <label>Empresa<select name="conta" class="cf-in"><?php foreach ($contasApi ?: $empresas as $id => $c): ?><option value="<?= h($id) ?>" <?= $id === $contaSel ? 'selected' : '' ?>><?= h($c['nome']) ?></option><?php endforeach; ?></select></label>
      <label>Vencimento<div class="ro-per" style="display:flex;gap:5px;flex-wrap:wrap;margin-top:4px">
        <?php foreach (['atrasados' => 'Atrasados (120 dias)', 'hoje' => 'Hoje', 'amanha' => 'Amanhã', '2' => '2 dias', '3' => '3 dias', '7' => '7 dias', '15' => '15 dias', '30' => '30 dias', 'custom' => 'Período…'] as $k => $n): ?>
          <button type="button" data-p="<?= $k ?>" class="<?= $periodo === $k ? 'on' : '' ?>"><?= $n ?></button><?php endforeach; ?></div>
        <input type="hidden" name="periodo" id="ro-periodo" value="<?= h($periodo) ?>"></label>
      <span id="ro-custom" style="<?= $periodo === 'custom' ? '' : 'display:none' ?>"><label>de <input type="date" name="de" class="cf-in" value="<?= h($de) ?>"></label> <label>até <input type="date" name="ate" class="cf-in" value="<?= h($ate) ?>"></label></span>
      <label class="cf-mini"><input type="checkbox" name="so_aberto" <?= $soAberto ? 'checked' : '' ?>> só títulos em aberto</label>
      <button class="btn" type="submit" <?= $contasApi ? '' : 'disabled' ?>>Buscar</button>
    </div>
  </form>
  </details>
</div>
<!-- títulos lidos do relatório / API: ficam SÓ neste modal até virarem recibo (nada fica tabelado na página) -->
<div id="ro-modal" class="cfm-fundo" style="display:none;z-index:900;align-items:flex-start;padding:24px 12px;overflow:auto">
  <div class="cfm-caixa" style="width:min(1500px,97vw);max-height:none;padding:16px 20px 14px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
      <h2 class="cfm-tit" style="text-align:left;margin:0">Títulos lidos <small id="rom-sub" style="font-weight:400;color:#667;font-size:13px"></small></h2>
      <button type="button" class="cf-x" id="rom-fechar">✖ encerrar</button>
    </div>
    <div id="rom-valid" class="cf-raw" style="margin:6px 0 0;display:none"><span id="rom-valid-txt"></span><div style="height:6px;border-radius:4px;background:#e8ebe6;margin-top:4px;overflow:hidden"><i id="rom-valid-bar" style="display:block;height:100%;width:0;background:#3f8a5f;transition:width .3s"></i></div></div>
    <div class="ro-abas" id="rom-abas" style="display:flex;gap:6px;flex-wrap:wrap;margin:10px 0 8px"></div>
    <div class="cf-barra" style="margin:0 0 8px">
      <div class="cf-pend ok" style="display:flex;gap:16px;flex-wrap:wrap;align-items:center">
        <label class="cf-mini" style="margin:0"><input type="checkbox" id="ro-agrupar"> agrupar num só recibo os títulos do mesmo fornecedor e mesma <b>previsão de pagamento</b></label>
        <label>Data do recibo <input type="date" id="ro-data" class="cf-in" title="vazio = previsão de pagamento de cada título (se não houver previsão, o vencimento)"></label>
        <label class="cf-mini" style="margin:0" title="um título que já está num recibo atual: o recibo antigo é regerado por inteiro"><input type="checkbox" id="ro-subst"> gerar de novo os que já têm recibo</label>
        <label class="cf-mini" style="margin:0"><input type="checkbox" id="ro-forcar"> incluir títulos não encontrados no Omie</label>
      </div>
      <div class="cf-acoes"><div style="text-align:right"><button class="btn" id="btn-gerar">Gerar <span id="ro-rot">recibos</span> (<span id="ro-n">0</span> · <span id="ro-total">R$ 0,00</span>)</button><div class="cf-raw" id="ro-resumo" style="margin-top:4px"></div></div></div>
    </div>
    <div class="cf-tbl-wrap" style="max-height:58vh;overflow:auto"><table class="grid cf-tbl" id="tbl-ro">
    <thead><tr><th><input type="checkbox" id="ro-todos"></th><th>Situação</th><th>Previsão pgto<br><small class="cf-raw">(venc. original)</small></th><th>Fornecedor (Omie)</th><th>Pessoa (dicionário)</th><th>Função</th><th>Cliente / imóvel</th><th>COD</th><th>Valor</th><th>Nº recibo</th><th>Conferência no Omie</th></tr></thead>
    <tbody></tbody></table></div>
    <p class="cf-dica" style="margin:8px 0 0">Vêm marcados os títulos <b>novos</b> (sem recibo, não pagos, encontrados no Omie). "Já com recibo": marcar + "gerar de novo" regera o recibo antigo por inteiro. Pagos/cancelados, repasses e não encontrados ficam desmarcados. O que não for gerado permanece aqui até você encerrar.</p>
  </div>
</div>
<?php endif; ?>

<h2 class="cf-h2">Recibos gerados</h2>
<form method="get" class="ro-filtros">
  <label>Busca<input name="busca" class="cf-in" value="<?= h($f['busca']) ?>" placeholder="nº, fornecedor, cliente, COD"></label>
  <label>De<input type="date" name="hde" class="cf-in" value="<?= h($f['de']) ?>"></label>
  <label>Até<input type="date" name="hate" class="cf-in" value="<?= h($f['ate']) ?>"></label>
  <?php if ($gestor): ?><label>Empresa<select name="hconta" class="cf-in"><option value="">todas</option><?php foreach ($empresas as $id => $e): ?><option value="<?= h($id) ?>" <?= $f['conta'] === $id ? 'selected' : '' ?>><?= h($e['nome']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
  <label>Origem<select name="horigem" class="cf-in"><option value="">todas</option><option value="api" <?= $f['origem'] === 'api' ? 'selected' : '' ?>>buscados pela API</option><option value="xlsx" <?= $f['origem'] === 'xlsx' ? 'selected' : '' ?>>importados do .xlsx</option></select></label>
  <label>Situação<select name="hstatus" class="cf-in"><?php foreach (['todos' => 'todos', 'atual' => 'atuais', 'substituido' => 'substituídos', 'cancelado' => 'cancelados'] as $k => $n): ?><option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></label>
  <button class="btn" type="submit">Filtrar</button>
  <a href="/recibos-omie/" class="ro-limpar" id="ro-limpar" style="display:none">✖ limpar filtros</a>
</form>
<?php if (!$historico): ?><p class="home-sub">Nenhum recibo ainda.</p><?php else: ?>
<div class="cf-tbl-wrap"><table class="grid cf-tbl" id="tbl-hist">
<thead><tr><th><input type="checkbox" id="h-todos"></th><th>Nº</th><th>Data</th><th>Recebedor</th><th>Empresa</th><th>Valor</th><th>Títulos</th><th>Omie</th><th>Gerado</th><th></th></tr></thead>
<tbody><?php foreach ($historico as $r): $its = json_decode((string)$r['itens'], true) ?: [];
  $quando = $r['encerrado_em'] ? ' em ' . cf_data_br(substr((string)$r['encerrado_em'], 0, 10)) : ''; $quem = $r['encerrado_nome'] ? ' por ' . h((string)$r['encerrado_nome']) : '';
  $sn = array_values(array_filter(array_map('trim', explode(' / ', (string)($r['subst_numero'] ?? ''))))); $snTxt = implode(' / ', array_slice($sn, 0, 2)) . (count($sn) > 2 ? ' +' . (count($sn) - 2) : '');
  $sit = $r['status'] === 'substituido' ? '<br><span class="cf-tag" title="' . h((string)$r['subst_numero']) . '">substituído' . ($snTxt !== '' ? ' pelo ' . h($snTxt) : '') . $quando . $quem . '</span>'
       : ($r['status'] === 'cancelado' ? '<br><span class="cf-tag cf-grave">cancelado' . $quando . $quem . '</span>' . ($r['motivo'] ? '<br><small class="cf-raw">' . h((string)$r['motivo']) . '</small>' : '') : ''); ?>
<?php $cods = array_values(array_filter(array_map('trim', explode(' / ', (string)$r['numero'])))); $nCods = count($cods); ?>
<tr data-id="<?= (int)$r['id'] ?>" class="<?= $r['status'] !== 'atual' ? 'ro-encerrado' : '' ?>"><td><input type="checkbox" class="h-sel"></td><td><?php if ($nCods > 3): ?><b class="ro-col" title="clique para ver todos os códigos"><?= h(implode(' / ', array_slice($cods, 0, 3))) ?><span class="ro-resto"> / <?= h(implode(' / ', array_slice($cods, 3))) ?></span></b> <span class="ro-mais">+<?= $nCods - 3 ?> ▾</span><?php else: ?><b><?= h($r['numero']) ?></b><?php endif; ?><?= $sit ?></td><td><?= cf_data_br($r['data_recibo']) ?></td><td><?= h($r['fornecedor_nome']) ?><br><small class="cf-raw"><?= h($r['fornecedor_doc']) ?></small></td>
<td><?= h(strtoupper($r['conta'])) ?></td><td class="cf-num"><?= cf_brl($r['valor']) ?></td>
<td><small><?php $nIts = count($its); if ($nIts > 2): ?><span class="ro-col" title="clique para ver todos os títulos"><?php foreach (array_slice($its, 0, 2) as $it): ?><?= h(($it['obs']['funcao'] ?? '') . ' · ' . mb_substr((string)($it['obs']['cliente'] ?? ''), 0, 30) . ' · COD ' . ($it['obs']['cod'] ?? '—')) ?><br><?php endforeach; ?><span class="ro-resto"><?php foreach (array_slice($its, 2) as $it): ?><?= h(($it['obs']['funcao'] ?? '') . ' · ' . mb_substr((string)($it['obs']['cliente'] ?? ''), 0, 30) . ' · COD ' . ($it['obs']['cod'] ?? '—')) ?><br><?php endforeach; ?></span></span><span class="ro-mais">+<?= $nIts - 2 ?> título(s) ▾</span><?php else: foreach ($its as $it): ?><?= h(($it['obs']['funcao'] ?? '') . ' · ' . mb_substr((string)($it['obs']['cliente'] ?? ''), 0, 30) . ' · COD ' . ($it['obs']['cod'] ?? '—')) ?><br><?php endforeach; endif; ?></small></td>
<td><small><?= h((string)$r['validado']) ?><br><span class="cf-raw"><?= $r['origem'] === 'api' ? 'API' : '.xlsx' ?></span></small></td><td><small><?= h(substr((string)$r['gerado_em'], 0, 16)) ?><br><?= h((string)$r['por']) ?></small></td>
<td style="white-space:nowrap"><a class="cf-x" href="?baixar=<?= (int)$r['id'] ?>&ver=1" target="_blank" title="ver">📄</a> <a class="cf-x" href="?baixar=<?= (int)$r['id'] ?>" title="baixar">⬇</a><?php if (!empty($r['descricao_nf'])): ?> <a class="cf-x" href="#" onclick="cfNf.mostrar(this);return false" title="bruto, líquido e descrição para a Nota Fiscal" data-nf="<?= h(json_encode(['numero' => $r['numero'], 'recebedor' => $r['fornecedor_nome'], 'valor' => (float)$r['valor'], 'bruto' => (float)$r['bruto'], 'descricao_nf' => $r['descricao_nf'], 'link' => '?baixar=' . (int)$r['id'] . '&ver=1'], JSON_UNESCAPED_UNICODE)) ?>">🧾 NF</a><?php endif; ?><?php if ($gestor && $r['status'] === 'atual'): ?> <a class="cf-x ro-cancelar" href="#" data-num="<?= h($r['numero']) ?>" title="cancelar este recibo (fica guardado como cancelado)">✖</a><?php elseif ($gestor): ?> <a class="cf-x ro-restaurar" href="#" data-num="<?= h(mb_substr($r['numero'], 0, 40)) ?>" title="voltar este recibo para atual (desfaz o cancelamento/substituição)">↶ restaurar</a><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<p><a class="btn cf-btn-sec" id="h-zip" href="#" style="display:none">⬇ ZIP dos selecionados</a></p>
<?php endif; ?>

<script>
(function(){
  const csrf = document.querySelector('meta[name=csrf]').content;
  const brl = v => 'R$ ' + v.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  document.querySelectorAll('.ro-per button').forEach(b => b.addEventListener('click', () => { document.querySelectorAll('.ro-per button').forEach(x => x.classList.remove('on')); b.classList.add('on'); document.getElementById('ro-periodo').value = b.dataset.p; document.getElementById('ro-custom').style.display = b.dataset.p === 'custom' ? '' : 'none'; }));
  const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');
  const dBr = d => d ? String(d).split('-').reverse().join('/') : '';
  const curto = n => { const c = String(n || '').split(' / '); return c.slice(0, 3).join(' / ') + (c.length > 3 ? ' …' : ''); };
  // POST JSON com erro em modal (e fecha o progresso, se houver)
  async function post(body, pg){
    let r = null, j = null;
    try { r = await fetch('/recibos-omie/', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)}); try { j = await r.json(); } catch(e) {} }
    catch(e) { if (pg) pg.fechar(); await CFModal.aviso({tipo:'erro', titulo:'Sem conexão com o portal', texto:'A requisição não chegou ao servidor. Confira sua internet e tente de novo.'}); return null; }
    if (!r.ok || !j || !j.ok) { if (pg) pg.fechar(); await CFModal.aviso({tipo:'erro', titulo:'Não foi possível concluir', texto: j && j.erro ? j.erro : ('Erro ' + r.status + (r.status === 403 || r.status === 401 ? ': a página expirou — atualize e tente de novo' : r.status >= 500 ? ': o servidor não respondeu direito — tente de novo em instantes' : ''))}); return null; }
    return j;
  }

  /* ===== modal dos títulos lidos ===== */
  const M = {el: document.getElementById('ro-modal'), linhas: [], conta: '', origem: '', aba: 'todos', gerados: 0};
  const ROT = {novo: 'Novos', ja: 'Já com recibo', pago: 'Pagos / cancelados', repasse: 'Repasses', nao: 'Não encontrados no Omie', gerado: 'Gerados agora'};
  function abrirModal(){ M.el.style.display = 'flex'; requestAnimationFrame(() => M.el.classList.add('on')); }
  function fecharModal(){ M.el.classList.remove('on'); setTimeout(() => { M.el.style.display = 'none'; }, 180); }
  function linhaHtml(l){
    const t = l.t; const sit = l.classe;
    const marc = sit === 'novo';
    const num = l.classe === 'gerado' ? '<a href="?baixar=' + l.gerado.id + '&ver=1" target="_blank">📄 ' + esc(curto(l.gerado.numero)) + '</a><br><small class="cf-raw">gerado agora</small>'
      : (l.ja ? '<a href="?baixar=' + l.ja.id + '&ver=1" target="_blank" title="' + esc(l.ja.numero) + '">📄 ' + esc(l.ja.curto) + '</a><br><small class="cf-raw">já tem recibo (' + dBr(l.ja.data) + ')</small>' : (l.numero_obs ? esc(l.numero_obs) : '<span class="cf-raw">novo</span>'));
    const val = l.validado || ''; const valCls = /^NÃO/.test(val) ? 'ro-val-nao' : (/conferindo/.test(val) ? 'cf-raw' : 'ro-val-ok');
    return '<tr class="cf-row ro-' + sit + (sit === 'nao' ? ' cf-tem-grave' : '') + '" data-i="' + l._i + '">'
      + '<td>' + (sit === 'gerado' ? '✔' : '<input type="checkbox" class="ro-sel"' + (marc ? ' checked' : '') + '>') + '</td>'
      + '<td>' + esc(t.situacao) + (t.nota_fiscal ? '<br><small class="cf-raw">NF: ' + esc(t.nota_fiscal) + '</small>' : '') + '<br><span class="cf-tag' + (sit === 'nao' ? ' cf-grave' : '') + '">' + ROT[sit] + '</span></td>'
      + '<td><b>' + dBr(t.previsao || t.vencimento) + '</b>' + (t.previsao && t.previsao !== t.vencimento ? '<br><small class="cf-raw" title="vencimento original do título">venc. ' + dBr(t.vencimento) + '</small>' : '') + '</td>'
      + '<td>' + esc(t.razao) + '<br><small class="cf-raw">' + esc(t.doc) + '</small></td>'
      + '<td>' + (l.pessoa ? esc(l.pessoa) : '<span class="cf-tag" title="não está em Pessoas: o recibo sai com a razão social do Omie">não cadastrada</span>') + '</td>'
      + '<td>' + esc(l.funcao) + (l.bonus ? ' <span class="cf-tag">BONUS</span>' : '') + (l.repasse ? ' <span class="cf-tag cf-grave" title="repasse a construtora/terceiro: normalmente não tem recibo de comissão">REPASSE</span>' : '') + '</td>'
      + '<td><small>' + esc(l.cliente.slice(0, 45)) + '<br><span class="cf-raw">' + esc(l.imovel.slice(0, 45)) + '</span>' + (l.sem_obs ? '<br><span class="cf-tag cf-grave">sem observação</span>' : '') + '</small></td>'
      + '<td>' + esc(l.cod || '—') + (l.venda ? '<br><small class="cf-raw">' + esc(l.venda) + '</small>' : '') + '</td>'
      + '<td class="cf-num">' + brl(+t.valor) + '</td><td>' + num + '</td>'
      + '<td class="' + valCls + '"><small>' + esc(val) + '</small></td></tr>';
  }
  function render(){
    const tb = document.querySelector('#tbl-ro tbody'); const cont = {};
    M.linhas.forEach(l => cont[l.classe] = (cont[l.classe] || 0) + 1);
    const vis = M.linhas.filter(l => M.aba === 'todos' || l.classe === M.aba);
    tb.innerHTML = vis.map(linhaHtml).join('');
    const ab = document.getElementById('rom-abas'); let h = '<button type="button" class="' + (M.aba === 'todos' ? 'on' : '') + '" data-aba="todos">Todos (' + M.linhas.length + ')</button>';
    ['novo', 'ja', 'pago', 'repasse', 'nao', 'gerado'].forEach(k => { if (cont[k]) h += '<button type="button" class="' + (M.aba === k ? 'on' : '') + '" data-aba="' + k + '">' + ROT[k] + ' (' + cont[k] + ')</button>'; });
    ab.innerHTML = h; ab.querySelectorAll('button').forEach(b => b.addEventListener('click', () => { M.aba = b.dataset.aba; render(); }));
    tb.querySelectorAll('.ro-sel').forEach(c => c.addEventListener('change', soma)); soma();
  }
  function selecionadas(){ return [...document.querySelectorAll('#tbl-ro .ro-sel:checked')].map(c => M.linhas.find(l => l._i === +c.closest('tr').dataset.i)); }
  // contador do botão: nº de recibos que vão sair de fato (agrupado = um por fornecedor+previsão) e o valor final (já com descontos/impostos/juros)
  function soma(){
    const sel = selecionadas(); const n = document.getElementById('ro-n');
    const agrupar = document.getElementById('ro-agrupar').checked, dataFixa = document.getElementById('ro-data').value;
    let bruto = 0, fin = 0; const grupos = new Set();
    sel.forEach(l => { bruto += +l.t.valor || 0; fin += +l.final || 0; const g = l.grupo.split('|'); grupos.add(agrupar ? g[0] + '|' + g[1] + '|' + (dataFixa || g[2]) : l.t.fingerprint); });
    const nRec = agrupar ? grupos.size : sel.length;
    n.textContent = nRec; document.getElementById('ro-rot').textContent = nRec === 1 ? 'recibo' : 'recibos';
    document.getElementById('ro-total').textContent = brl(fin);
    document.getElementById('ro-resumo').textContent = sel.length ? sel.length + ' título(s)' + (Math.abs(bruto - fin) > 0.005 ? ' · bruto ' + brl(bruto) + ' · descontos ' + brl(bruto - fin) : '') : '';
    document.getElementById('btn-gerar').disabled = !sel.length;
  }
  ['ro-agrupar', 'ro-data'].forEach(id => document.getElementById(id).addEventListener('change', soma));
  document.getElementById('ro-todos').addEventListener('change', e => { document.querySelectorAll('#tbl-ro .ro-sel').forEach(c => c.checked = e.target.checked); soma(); });
  try { document.getElementById('ro-agrupar').checked = localStorage.getItem('ro-agrupar') === '1'; } catch(e) {}
  document.getElementById('ro-agrupar').addEventListener('change', e => { try { localStorage.setItem('ro-agrupar', e.target.checked ? '1' : '0'); } catch(err) {} });

  // conferência no Omie em blocos (o relatório já abriu; cada bloco atualiza as linhas)
  async function validar(){
    const box = document.getElementById('rom-valid'), txt = document.getElementById('rom-valid-txt'), bar = document.getElementById('rom-valid-bar');
    let fila = M.linhas.filter(l => /conferindo/.test(l.validado || '')).map(l => l._i); const total = fila.length; if (!total) return;
    box.style.display = ''; let feitos = 0; const BL = 15; M.validando = true;
    while (fila.length) {
      const lote = fila.slice(0, BL); txt.textContent = 'Conferindo no Omie (CNPJ + vencimento + valor)… ' + feitos + '/' + total; bar.style.width = (feitos / total * 100) + '%';
      const j = await post({csrf, acao:'validar', conta: M.conta, itens: lote.map(i => ({...M.linhas.find(l => l._i === i).t, _i: i}))});
      if (!j) { txt.textContent = 'Conferência interrompida — os títulos sem conferência ficam marcados como "conferindo…"; dá para gerar mesmo assim marcando "incluir títulos não encontrados".'; bar.style.width = '0'; M.validando = false; return; }
      j.linhas.forEach(nl => { const k = M.linhas.findIndex(l => l._i === nl._i); const sel = document.querySelector('#tbl-ro tr[data-i="' + nl._i + '"] .ro-sel'); const marcado = sel ? sel.checked : null; M.linhas[k] = nl; M.linhas[k]._marc = marcado; });
      const rest = j.restantes || []; feitos += lote.length - rest.length; fila = rest.concat(fila.slice(BL));
      render(); M.linhas.forEach(l => { if (l._marc !== undefined && l._marc !== null) { const c = document.querySelector('#tbl-ro tr[data-i="' + l._i + '"] .ro-sel'); if (c && l.classe !== 'nao' && l.classe !== 'pago') c.checked = l._marc; } }); soma();
    }
    M.validando = false; txt.textContent = 'Conferência no Omie concluída: ' + total + ' título(s).'; bar.style.width = '100%'; setTimeout(() => box.style.display = 'none', 2500);
  }

  // ler relatório / buscar na API: por fetch, abre o modal com o que foi lido (nada fica na página)
  document.querySelectorAll('form.ro-form-xlsx, form.ro-form-api').forEach(f => f.addEventListener('submit', async e => {
    e.preventDefault();
    const xlsx = f.classList.contains('ro-form-xlsx');
    if (xlsx && !(f.querySelector('input[type=file]').files || []).length) { await CFModal.aviso({tipo:'info', titulo:'Escolha o arquivo', texto:'Selecione o relatório .xlsx exportado do Omie (Finanças › Contas a Pagar).'}); return; }
    const pg = CFModal.progresso({titulo: xlsx ? 'Lendo o relatório' : 'Buscando no Omie', etapas: xlsx ? ['enviando o arquivo', 'lendo os títulos', 'conferindo recibos já emitidos'] : ['consultando a API', 'montando a lista'], tau: xlsx ? 5 : 4}).faixa(0, 90);
    const fd = new FormData(f); fd.append('ajax', '1');
    let r = null, j = null;
    try { r = await fetch('/recibos-omie/', {method:'POST', body: fd}); try { j = await r.json(); } catch(err) {} } catch(err) { r = null; }
    if (!r) { pg.fechar(); await CFModal.aviso({tipo:'erro', titulo:'Sem conexão com o portal', texto:'O arquivo não chegou ao servidor. Confira sua internet e tente de novo.'}); return; }
    if (!r.ok || !j || !j.ok) { pg.fechar(); await CFModal.aviso({tipo:'erro', titulo: xlsx ? 'Não consegui ler o relatório' : 'Busca não concluída', texto: j && j.erro ? j.erro : 'Erro ' + r.status + (r.status === 413 ? ': arquivo grande demais para o servidor' : r.status === 403 ? ': a página expirou — atualize e tente de novo' : r.status >= 500 ? ': o servidor não respondeu direito — tente de novo em instantes' : '')}); return; }
    M.linhas = j.linhas; M.conta = j.conta; M.origem = j.origem; M.aba = 'todos'; M.gerados = 0;
    document.getElementById('rom-sub').textContent = (xlsx ? 'relatório' : 'API') + ' · ' + j.empresa + (j.periodo ? ' · vencimento ' + j.periodo : '') + ' · ' + j.linhas.length + ' título(s)';
    const cont = {}; j.linhas.forEach(l => cont[l.classe] = (cont[l.classe] || 0) + 1);
    await pg.resultado({tipo: j.linhas.length ? 'ok' : 'info', titulo: xlsx ? 'Relatório lido' : 'Busca concluída', html: '<p style="margin:0">' + j.linhas.length + ' título(s)' + (j.linhas.length ? ': <b>' + (cont.novo || 0) + ' novo(s)</b>, ' + (cont.ja || 0) + ' já com recibo, ' + ((cont.pago || 0) + (cont.repasse || 0)) + ' pago(s)/repasse(s)' : '') + '.</p>' + (j.validar ? '<p class="cf-dica" style="margin:6px 0 0">A conferência no Omie começa agora, em segundo plano, dentro da janela.</p>' : '')});
    render(); abrirModal(); f.reset();
    if (j.validar) validar();
  }));
  document.getElementById('rom-fechar').addEventListener('click', async () => {
    const sobra = M.linhas.filter(l => l.classe === 'novo').length;
    if (sobra && !await CFModal.pergunta({tipo:'parcial', titulo:'Encerrar sem gerar?', texto: sobra + ' título(s) novo(s) ainda sem recibo vão ser descartados desta leitura (nada é gravado; é só enviar o relatório de novo para vê-los outra vez).', ok:'Sim, encerrar'})) return;
    fecharModal(); if (M.gerados) location.reload();
  });
  M.el.addEventListener('click', e => { if (e.target === M.el) document.getElementById('rom-fechar').click(); });

  const bg = document.getElementById('btn-gerar');
  bg.addEventListener('click', async () => {
    if (M.validando && !await CFModal.pergunta({titulo:'Conferência em andamento', texto:'A conferência no Omie ainda não terminou. Gerar assim mesmo? (os títulos ainda não conferidos entram sem a conferência)', ok:'Sim, gerar'})) return;
    const forcar = document.getElementById('ro-forcar').checked;
    const sel = selecionadas();
    const bloq = sel.filter(l => l.classe === 'nao' && !forcar);
    if (bloq.length) { await CFModal.aviso({tipo:'parcial', titulo:'Títulos não encontrados no Omie', texto: bloq.length + ' título(s) selecionado(s) não foram encontrados no Omie. Cadastre lá primeiro ou marque "incluir títulos não encontrados".'}); return; }
    const jaSel = sel.filter(l => l.classe === 'ja');
    if (jaSel.length && !document.getElementById('ro-subst').checked) { await CFModal.aviso({tipo:'parcial', titulo:'Títulos que já têm recibo', texto: jaSel.length + ' título(s) marcado(s) já estão em recibos. Desmarque-os, ou marque "gerar de novo os que já têm recibo" para regerar esses recibos por inteiro.'}); return; }
    const itens = sel.map(l => l.t); if (!itens.length) return;
    const corpo = {csrf, itens, agrupar: document.getElementById('ro-agrupar').checked ? 1 : 0, data: document.getElementById('ro-data').value, substituir: document.getElementById('ro-subst').checked ? 1 : 0};
    bg.disabled = true;
    // 1) prévia: quantos recibos saem e quais recibos antigos seriam substituídos (por inteiro)
    const pv = await post({...corpo, acao:'prever'}); if (!pv) { bg.disabled = false; return; }
    let html = '<p style="margin:0 0 6px">Vão sair <b>' + pv.recibos + ' recibo(s)</b> com <b>' + pv.titulos + ' título(s)</b>' + (corpo.agrupar ? ' (agrupados por fornecedor e previsão de pagamento)' : '') + '.</p>';
    if (pv.substituir.length) {
      html += '<p style="margin:8px 0 4px"><b>Recibos que serão substituídos</b> (ficam no histórico como "substituído"; o novo recibo tem só o que você marcou):</p><ul style="margin:0 0 0 18px">';
      pv.substituir.forEach(a => { html += '<li><b>' + esc(curto(a.numero)) + '</b> de ' + dBr(a.data) + ' — ' + a.total + ' título(s), ' + a.selecionados + ' marcado(s)' + (a.fora ? '. <span style="color:#b4512f"><b>' + a.fora + ' título(s) desse recibo não estão marcados e vão ficar sem recibo</b>' + (a.fora_lista && a.fora_lista.length ? ' (' + esc(a.fora_lista.join(', ')) + (a.fora > a.fora_lista.length ? ', …' : '') + ')' : '') + ' — aparecem como novos na próxima leitura.</span>' : '') + '</li>'; });
      html += '</ul><p class="cf-dica" style="margin:8px 0 0">Para manter os recibos antigos como estão, cancele e desmarque os títulos "já com recibo".</p>';
    }
    if (!await CFModal.pergunta({titulo: pv.substituir.length ? 'Gerar e substituir?' : 'Gerar recibos?', html, ok: 'Sim, gerar', tipo: pv.substituir.length ? 'parcial' : 'info'})) { bg.disabled = false; return; }
    // 2) gera, com progresso (modal por cima da janela de títulos)
    const pg = CFModal.progresso({titulo: 'Gerando ' + pv.recibos + ' recibo(s)', etapas: ['numerando os títulos', 'montando os PDFs', 'gravando'], tau: 4}).faixa(0, 92);
    const j = await post({...corpo, acao:'gerar'}, pg);
    if (!j) { bg.disabled = false; return; }
    // marca as linhas geradas (e as dos recibos substituídos) na janela — elas deixam de ser candidatas
    const fps = new Set(); (j.recibos || []).forEach(r => (r.fingerprints || []).forEach(fp => fps.add(fp)));
    M.linhas.forEach(l => { const rec = (j.recibos || []).find(r => (r.fingerprints || []).includes(l.t.fingerprint) || (r.fingerprints || []).includes(l.t.fingerprint_legado)); if (rec) { l.classe = 'gerado'; l.gerado = {id: rec.id, numero: rec.numero}; } });
    M.gerados += j.n; render();
    const subst = pv.substituir.length ? '<p style="margin:6px 0 0">' + pv.substituir.length + ' recibo(s) antigo(s) substituído(s) — continuam no histórico com a situação "substituído".</p>' : '';
    const links = (j.recibos || []).map(r => '<a href="?baixar=' + r.id + '&ver=1" target="_blank">📄 ' + esc(curto(r.numero)) + '</a> — ' + esc(r.recebedor) + ' — ' + brl(+r.valor)).join('<br>');
    const sobra = M.linhas.filter(l => l.classe === 'novo').length;
    await pg.resultado({tipo: 'ok', titulo: j.n + ' recibo(s) gerado(s)', html: '<p style="margin:0">' + links + '</p>' + subst + (j.ids.length > 1 ? '<p style="margin:6px 0 0"><a href="?zip=' + j.ids.join(',') + '">⬇ baixar todos (ZIP)</a></p>' : '') + '<p class="cf-dica" style="margin:8px 0 0">' + (sobra ? sobra + ' título(s) novo(s) continuam na janela — gere-os também ou encerre para ignorá-los.' : 'Não sobrou título novo. Ao encerrar a janela, a lista de recibos é atualizada.') + '</p>'});
    cfNf.painel(j.recibos || [], j.n + ' recibo(s) gerado(s)'); bg.disabled = false;
  });
  // colunas longas do histórico: abre/fecha ao clicar
  document.querySelectorAll('#tbl-hist .ro-col, #tbl-hist .ro-mais').forEach(el => el.addEventListener('click', e => { e.preventDefault(); const td = el.closest('td'); const col = td.querySelector('.ro-col'); col.classList.toggle('aberto'); const m = td.querySelector('.ro-mais'); if (m) m.textContent = col.classList.contains('aberto') ? 'menos ▴' : m.dataset.rot || (m.dataset.rot = m.textContent); }));
  // filtros ativos em destaque
  (function(){ const f = document.querySelector('form.ro-filtros'); if (!f) return; let n = 0;
    f.querySelectorAll('label').forEach(l => { const i = l.querySelector('input,select'); if (!i) return; const padrao = i.name === 'hstatus' ? 'todos' : ''; if ((i.value || '') !== padrao) { l.classList.add('on'); n++; } });
    const lim = document.getElementById('ro-limpar'); if (lim && n) { lim.style.display = ''; lim.textContent = '✖ limpar ' + n + ' filtro(s)'; } })();
  function hz(){ const ids = [...document.querySelectorAll('.h-sel:checked')].map(c => +c.closest('tr').dataset.id); const z = document.getElementById('h-zip'); if (!z) return; z.style.display = ids.length ? '' : 'none'; z.href = '?zip=' + ids.join(','); z.textContent = '⬇ ZIP (' + ids.length + ')'; }
  document.querySelectorAll('.h-sel').forEach(c => c.addEventListener('change', hz));
  document.querySelectorAll('.ro-cancelar').forEach(a => a.addEventListener('click', async e => {
    e.preventDefault();
    const ok = await CFModal.pergunta({tipo:'parcial', titulo:'Cancelar o recibo?', html:'<p style="margin:0"><b>' + a.dataset.num.replace(/</g,'&lt;') + '</b> sai da lista de atuais e fica guardado como cancelado (o PDF continua no filtro "cancelados"; dá para restaurar depois).</p><label style="display:block;margin-top:10px;font-size:13px">Motivo (opcional)<input id="ro-motivo" class="cf-in" style="width:100%;margin-top:4px" maxlength="200"></label>', ok:'Sim, cancelar'});
    if (!ok) return;
    const motivo = (document.getElementById('ro-motivo') || {}).value || '';
    const j = await post({csrf, acao:'cancelar', id: +a.closest('tr').dataset.id, motivo}); if (!j) return;
    await CFModal.aviso({tipo:'ok', titulo:'Recibo cancelado', texto: j.numero.split(' / ').slice(0, 3).join(' / ') + (j.numero.split(' / ').length > 3 ? ' …' : '')}); location.reload();
  }));
  document.querySelectorAll('.ro-restaurar').forEach(a => a.addEventListener('click', async e => {
    e.preventDefault();
    if (!await CFModal.pergunta({titulo:'Restaurar o recibo?', texto:'O recibo ' + a.dataset.num + ' volta a ser o recibo atual desses títulos (só é possível se nenhum título dele estiver em outro recibo atual).', ok:'Sim, restaurar'})) return;
    const j = await post({csrf, acao:'restaurar', id: +a.closest('tr').dataset.id}); if (!j) return;
    await CFModal.aviso({tipo:'ok', titulo:'Recibo restaurado', texto: j.numero.split(' / ').slice(0, 3).join(' / ') + (j.numero.split(' / ').length > 3 ? ' …' : '')}); location.reload();
  }));
  const ht = document.getElementById('h-todos'); if (ht) ht.addEventListener('change', () => { document.querySelectorAll('.h-sel').forEach(c => c.checked = ht.checked); hz(); });
})();
</script>
</div>
<?php portal_footer();
