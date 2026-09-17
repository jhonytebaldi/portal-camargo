<?php
/* =====================================================================
   recibos-omie/index.php — "Recibos do Omie": gera a Declaração e Recibo
   (mesmo modelo da Capa Financeira) a partir das contas a pagar do Omie,
   vindas do relatório .xlsx "Finanças - Contas a Pagar" ou direto da API
   (período selecionável). Valida se cada título existe no Omie.
   GET  filtros de histórico · ?baixar=ID · ?zip=1,2,3
   POST form: buscar (api) | relatorio (xlsx)
   POST JSON: acao gerar
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

/* ---------- downloads ---------- */
if (isset($_GET['baixar'])) {
    $st = $pdo->prepare('SELECT * FROM ro_recibos WHERE id = ?'); $st->execute([(int)$_GET['baixar']]); $r = $st->fetch();
    if (!$r || !ro_pode($r, $gestor, $minhaPessoa) || !is_file($r['arquivo_path'])) { http_response_code(404); exit('recibo não encontrado'); }
    header('Content-Type: application/pdf'); header('Content-Disposition: ' . (isset($_GET['ver']) ? 'inline' : 'attachment') . '; filename="' . $r['arquivo_nome'] . '"');
    header('Content-Length: ' . filesize($r['arquivo_path'])); readfile($r['arquivo_path']); exit;
}
if (isset($_GET['zip'])) {
    $ids = array_filter(array_map('intval', explode(',', (string)$_GET['zip']))); if (!$ids) exit('nada');
    $st = $pdo->query('SELECT * FROM ro_recibos WHERE id IN (' . implode(',', $ids) . ')');
    $tmp = tempnam(sys_get_temp_dir(), 'ro'); $z = new ZipArchive(); $z->open($tmp, ZipArchive::OVERWRITE); $n = 0;
    foreach ($st->fetchAll() as $r) if (ro_pode($r, $gestor, $minhaPessoa) && is_file($r['arquivo_path'])) { $z->addFile($r['arquivo_path'], $r['arquivo_nome']); $n++; }
    $z->close(); if (!$n) { @unlink($tmp); exit('nada para baixar'); }
    header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="recibos_omie_' . date('Ymd-Hi') . '.zip"'); header('Content-Length: ' . filesize($tmp));
    readfile($tmp); @unlink($tmp); exit;
}

/** nº do recibo: RECIBO da observação → já emitido para o mesmo título → sequência do COD (cf_sequencias) → REC-<ano>-n */
function ro_numero(PDO $pdo, array $t): string
{
    if (!empty($t['obs']['recibo'])) return (string)$t['obs']['recibo'];
    if (!empty($t['cod_int'])) return (string)$t['cod_int'];
    $st = $pdo->prepare("SELECT r.numero, r.itens FROM ro_recibo_itens i JOIN ro_recibos r ON r.id = i.recibo_id WHERE i.fingerprint = ? ORDER BY r.id DESC LIMIT 1");
    $st->execute([$t['fingerprint']]);
    if ($r = $st->fetch()) { foreach (json_decode((string)$r['itens'], true) ?: [] as $it) if (($it['fingerprint'] ?? '') === $t['fingerprint'] && !empty($it['numero'])) return (string)$it['numero']; }
    $cod = preg_replace('/\D+/', '', (string)($t['obs']['cod'] ?? '')) ?: ('REC' . date('Y'));
    $pdo->prepare('INSERT INTO cf_sequencias (cod, tipo, ultimo) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE ultimo = ultimo + 1')->execute([$cod, 'P']);
    $q = $pdo->prepare('SELECT ultimo FROM cf_sequencias WHERE cod = ? AND tipo = ?'); $q->execute([$cod, 'P']);
    return $cod . '-P' . str_pad((string)(int)$q->fetchColumn(), 3, '0', STR_PAD_LEFT);
}

/* ---------- gerar (JSON) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    header('Content-Type: application/json; charset=utf-8');
    if (!$gestor) { http_response_code(403); exit(json_encode(['ok' => false, 'erro' => 'sem permissão'])); }
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $_POST['csrf'] = $in['csrf'] ?? ''; csrf_check();
    $falha = function (string $m, int $c = 400): never { http_response_code($c); exit(json_encode(['ok' => false, 'erro' => $m], JSON_UNESCAPED_UNICODE)); };
    try {
        if (($in['acao'] ?? '') !== 'gerar') $falha('ação inválida');
        $itens = (array)($in['itens'] ?? []); if (!$itens) $falha('nenhum título selecionado');
        $agrupar = !empty($in['agrupar']); $substituir = !empty($in['substituir']);
        $dataFixa = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['data'] ?? '')) ? (string)$in['data'] : null;
        $grupos = [];
        foreach ($itens as $t) {
            if (empty($t['fingerprint']) || !isset($t['valor']) || empty($t['conta'])) $falha('título inválido');
            $t = Titulo::normalizar($t);   // reconfere obs/fingerprint do lado do servidor
            $q = $pdo->prepare("SELECT r.id, r.numero FROM ro_recibo_itens i JOIN ro_recibos r ON r.id = i.recibo_id WHERE i.fingerprint = ? AND r.status = 'atual'"); $q->execute([$t['fingerprint']]);
            if (($ja = $q->fetch()) && !$substituir) $falha('já existe recibo ' . $ja['numero'] . ' para ' . $t['razao'] . ' ' . cf_brl($t['valor']) . ' venc. ' . cf_data_br($t['vencimento']) . '. Marque "gerar de novo" para substituir.');
            $t['_antigo'] = $ja['id'] ?? null;
            $k = $agrupar ? $t['conta'] . '|' . $t['doc_digitos'] . '|' . ($dataFixa ?: ($t['previsao'] ?: $t['vencimento'])) : $t['fingerprint'];
            $grupos[$k][] = $t;
        }
        $dir = cf_data_dir() . '/recibos-omie/' . date('Y'); if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $gerados = [];
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
            $r = Recibo::gerar($linhas, $p, $emp, $data, $cidade, $logo);
            $nome = Recibo::nomeArquivo($r['numero'], $p['nome']);
            $path = $dir . '/' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4) . '-' . $nome;
            file_put_contents($path, $r['pdf']);
            foreach ($g as $t) if (!empty($t['_antigo'])) $pdo->prepare("UPDATE ro_recibos SET status = 'substituido' WHERE id = ?")->execute([$t['_antigo']]);
            $pdo->prepare('INSERT INTO ro_recibos (conta, numero, fingerprints, omie_ids, fornecedor_doc, fornecedor_nome, pessoa_id, valor, data_recibo, itens, origem, validado, arquivo_nome, arquivo_path, gerado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$t0['conta'], $r['numero'], json_encode($fps), json_encode($ids), (string)$t0['doc'], $r['recebedor'], $p['id'] ?? null, $r['valor'], $data, json_encode($itensGravar, JSON_UNESCAPED_UNICODE), $t0['origem'], (string)($t0['validado'] ?? ($t0['origem'] === 'api' ? $t0['situacao'] : '')), $nome, $path, $u['id']]);
            $rid = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT IGNORE INTO ro_recibo_itens (recibo_id, fingerprint) VALUES (?, ?)');
            foreach ($fps as $fp) $ins->execute([$rid, $fp]);
            $gerados[] = $rid;
        }
        $pdo->commit();
        exit(json_encode(['ok' => true, 'ids' => $gerados, 'n' => count($gerados)]));
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
            // validação no Omie (se a API estiver configurada): mesmo CNPJ/CPF + vencimento + valor
            if (isset($contasApi[$contaSel])) {
                $cache = [];
                foreach ($titulos as &$t) {
                    if ($t['doc'] === '' || !$t['vencimento']) { $t['validado'] = 'sem CNPJ/vencimento p/ conferir'; continue; }
                    $k = $t['doc'] . '|' . $t['vencimento'];
                    try { $cache[$k] ??= OmieApi::procurarTitulo($contaSel, $t['doc'], $t['vencimento']); }
                    catch (Throwable $e) { $t['validado'] = 'erro ao consultar: ' . $e->getMessage(); continue; }
                    $ach = null;
                    foreach ($cache[$k] as $c) if (abs((float)($c['cabecTitulo']['nValorTitulo'] ?? 0) - (float)$t['valor']) < 0.005) { $ach = $c; break; }
                    if ($ach) { $t['omie_id'] = (int)$ach['cabecTitulo']['nCodTitulo']; $t['cod_int'] = (string)($ach['cabecTitulo']['cCodIntTitulo'] ?? ''); $t['validado'] = 'no Omie: ' . ($ach['cabecTitulo']['cStatus'] ?? '?'); $t = Titulo::normalizar($t); }
                    else $t['validado'] = 'NÃO ENCONTRADO no Omie';
                }
                unset($t);
            } else foreach ($titulos as &$t) { $t['validado'] = 'API não configurada — sem conferência'; } unset($t);
        }
    } catch (Throwable $e) { $erroBusca = $e->getMessage(); }
}

/* ---------- histórico ---------- */
$f = ['busca' => trim((string)($_GET['busca'] ?? '')), 'de' => (string)($_GET['hde'] ?? ''), 'ate' => (string)($_GET['hate'] ?? ''), 'conta' => (string)($_GET['hconta'] ?? '')];
$sql = "SELECT r.*, u.nome AS por FROM ro_recibos r LEFT JOIN users u ON u.id = r.gerado_por WHERE r.status = 'atual'"; $a = [];
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
<style>main.wrap{max-width:1600px}.ro-val-ok{color:#2e6b3a;font-weight:600}.ro-val-nao{color:#b4512f;font-weight:700}.ro-per button{font:inherit;font-size:13px;padding:4px 10px;border-radius:6px;border:1px solid var(--line);background:#fff;cursor:pointer}.ro-per button.on{background:var(--moss);color:#fff;border-color:var(--moss)}</style>
<div class="cf-rev">
<div class="cf-top">
  <div><h1 class="home-titulo"><?= $gestor ? 'Recibos do Omie' : 'Meus recibos' ?></h1>
    <p class="home-sub"><?= $gestor ? 'Declaração e Recibo a partir das contas a pagar já registradas no Omie — busque pela API por período ou envie o relatório "Finanças › Contas a Pagar" (.xlsx). Mesmo modelo dos recibos da Capa Financeira.' : 'Recibos das suas comissões e bônus.' ?></p></div>
  <?php if ($gestor): ?><nav class="cf-nav"><a href="/capa-financeira/">Capa Financeira</a><a href="/capa-financeira/recibos.php">Recibos das capas</a><a href="/capa-financeira/pessoas.php">Pessoas</a></nav><?php endif; ?>
</div>

<?php if ($gestor): ?>
<div class="cf-duas" style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
  <form method="post" class="card cf-upload" style="margin:0">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="modo" value="api">
    <h2>Buscar no Omie (API)</h2>
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
  <form method="post" enctype="multipart/form-data" class="card cf-upload" style="margin:0">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="modo" value="xlsx">
    <h2>Enviar relatório do Omie (.xlsx)</h2>
    <p class="cf-dica" style="margin-top:0">Omie › Finanças › Contas a Pagar › exportar. A empresa é lida do cabeçalho do relatório<?= $contasApi ? ' e cada título é conferido na API (CNPJ + vencimento + valor)' : '' ?>.</p>
    <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap"><label>Arquivo<input type="file" name="relatorio" accept=".xlsx" required></label><button class="btn" type="submit">Ler relatório</button></div>
  </form>
</div>
<?php if ($erroBusca): ?><div class="erro"><?= h($erroBusca) ?></div><?php endif; ?>

<?php if ($origem): $emp = ro_empresa($empresas, $contaSel); ?>
<h2 class="cf-h2">Títulos <small><?= $origem === 'api' ? 'API · ' . h($contasApi[$contaSel]['nome'] ?? $contaSel) . ' · vencimento ' . cf_data_br($de) . ' a ' . cf_data_br($ate) : 'relatório · ' . h($emp['nome']) ?> · <?= count($titulos) ?> título(s)</small></h2>
<?php if (!$titulos): ?><p class="home-sub">Nenhum título.</p><?php else: ?>
<div class="cf-barra">
  <div class="cf-pend ok" style="display:flex;gap:16px;flex-wrap:wrap;align-items:center">
    <label class="cf-mini" style="margin:0"><input type="checkbox" id="ro-agrupar"> agrupar num só recibo os títulos do mesmo fornecedor e mesma data</label>
    <label>Data do recibo <input type="date" id="ro-data" class="cf-in" title="vazio = previsão de pagamento de cada título"></label>
    <label class="cf-mini" style="margin:0"><input type="checkbox" id="ro-subst"> gerar de novo os que já têm recibo</label>
    <label class="cf-mini" style="margin:0"><input type="checkbox" id="ro-forcar"> incluir títulos não encontrados no Omie</label>
  </div>
  <div class="cf-acoes"><button class="btn" id="btn-gerar">Gerar recibos (<span id="ro-n">0</span> · <span id="ro-total">R$ 0,00</span>)</button></div>
</div>
<div class="cf-tbl-wrap"><table class="grid cf-tbl" id="tbl-ro">
<thead><tr><th><input type="checkbox" id="ro-todos" checked></th><th>Situação</th><th>Vencimento</th><th>Fornecedor (Omie)</th><th>Pessoa (dicionário)</th><th>Função</th><th>Cliente / imóvel</th><th>COD</th><th>Valor</th><th>Nº recibo</th><th>Conferência no Omie</th></tr></thead>
<tbody>
<?php foreach ($titulos as $t): $p = Titulo::pessoaPorDoc($pessoas, $t['doc']); $o = $t['obs'];
  $q = $pdo->prepare("SELECT r.id, r.numero FROM ro_recibo_itens i JOIN ro_recibos r ON r.id = i.recibo_id WHERE i.fingerprint = ? AND r.status = 'atual' LIMIT 1"); $q->execute([$t['fingerprint']]); $ja = $q->fetch();
  $naoAchou = $t['origem'] === 'xlsx' && str_starts_with((string)($t['validado'] ?? ''), 'NÃO'); $pago = in_array($t['situacao'], ['Pago', 'Cancelado'], true); ?>
<tr class="cf-row <?= $naoAchou ? 'cf-tem-grave' : '' ?>" data-t="<?= h(json_encode($t, JSON_UNESCAPED_UNICODE)) ?>" data-valor="<?= (float)$t['valor'] ?>" data-nao="<?= $naoAchou ? 1 : 0 ?>" data-ja="<?= $ja ? 1 : 0 ?>">
  <td><input type="checkbox" class="ro-sel" <?= $naoAchou || $pago || $ja ? '' : 'checked' ?>></td>
  <td><?= h($t['situacao']) ?><?= $t['nota_fiscal'] ? '<br><small class="cf-raw">NF: ' . h($t['nota_fiscal']) . '</small>' : '' ?></td>
  <td><?= cf_data_br($t['vencimento']) ?><?= $t['previsao'] && $t['previsao'] !== $t['vencimento'] ? '<br><small class="cf-raw">prev. ' . cf_data_br($t['previsao']) . '</small>' : '' ?></td>
  <td><?= h($t['razao']) ?><br><small class="cf-raw"><?= h($t['doc']) ?></small></td>
  <td><?= $p ? h($p['nome']) : '<span class="cf-tag" title="não está em Pessoas: o recibo sai com a razão social do Omie">não cadastrada</span>' ?></td>
  <td><?= h((string)($o['funcao'] ?: Titulo::funcaoDaCategoria((string)$t['categoria']))) ?><?= $o['natureza'] === 'BONUS' ? ' <span class="cf-tag">BONUS</span>' : '' ?><br><small class="cf-raw"><?= h((string)$t['categoria']) ?></small></td>
  <td><small><?= h(mb_substr((string)($o['cliente'] ?? ''), 0, 45)) ?><br><span class="cf-raw"><?= h(mb_substr((string)($o['imovel'] ?? ''), 0, 45)) ?></span><?= $o['padrao'] === 'vazio' ? '<br><span class="cf-tag cf-grave">sem observação</span>' : '' ?></small></td>
  <td><?= h((string)($o['cod'] ?? '—')) ?><?= $o['venda'] ? '<br><small class="cf-raw">' . h($o['venda']) . '</small>' : '' ?></td>
  <td class="cf-num"><?= cf_brl($t['valor']) ?></td>
  <td><?php if ($ja): ?><a href="?baixar=<?= (int)$ja['id'] ?>&ver=1" target="_blank">📄 <?= h($ja['numero']) ?></a><?php elseif (!empty($o['recibo'])): ?><?= h($o['recibo']) ?><?php else: ?><span class="cf-raw">novo</span><?php endif; ?></td>
  <td class="<?= $naoAchou ? 'ro-val-nao' : 'ro-val-ok' ?>"><small><?= h((string)($t['validado'] ?? '')) ?></small></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<p class="cf-dica">Títulos "NÃO ENCONTRADO no Omie" ficam desmarcados: cadastre no Omie antes (ou marque "incluir" para gerar mesmo assim). Já pagos/cancelados e os que já têm recibo também vêm desmarcados.</p>
<?php endif; endif; endif; ?>

<h2 class="cf-h2">Recibos gerados</h2>
<form method="get" class="cf-form" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:10px">
  <label>Busca<input name="busca" class="cf-in" value="<?= h($f['busca']) ?>" placeholder="nº, fornecedor, cliente, COD"></label>
  <label>De<input type="date" name="hde" class="cf-in" value="<?= h($f['de']) ?>"></label><label>até<input type="date" name="hate" class="cf-in" value="<?= h($f['ate']) ?>"></label>
  <?php if ($gestor): ?><label>Empresa<select name="hconta" class="cf-in"><option value="">todas</option><?php foreach ($empresas as $id => $e): ?><option value="<?= h($id) ?>" <?= $f['conta'] === $id ? 'selected' : '' ?>><?= h($e['nome']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
  <button class="btn" type="submit">Filtrar</button>
</form>
<?php if (!$historico): ?><p class="home-sub">Nenhum recibo ainda.</p><?php else: ?>
<div class="cf-tbl-wrap"><table class="grid cf-tbl" id="tbl-hist">
<thead><tr><th><input type="checkbox" id="h-todos"></th><th>Nº</th><th>Data</th><th>Recebedor</th><th>Empresa</th><th>Valor</th><th>Títulos</th><th>Omie</th><th>Gerado</th><th></th></tr></thead>
<tbody><?php foreach ($historico as $r): $its = json_decode((string)$r['itens'], true) ?: []; ?>
<tr data-id="<?= (int)$r['id'] ?>"><td><input type="checkbox" class="h-sel"></td><td><b><?= h($r['numero']) ?></b></td><td><?= cf_data_br($r['data_recibo']) ?></td><td><?= h($r['fornecedor_nome']) ?><br><small class="cf-raw"><?= h($r['fornecedor_doc']) ?></small></td>
<td><?= h(strtoupper($r['conta'])) ?></td><td class="cf-num"><?= cf_brl($r['valor']) ?></td>
<td><small><?php foreach ($its as $it): ?><?= h(($it['obs']['funcao'] ?? '') . ' · ' . mb_substr((string)($it['obs']['cliente'] ?? ''), 0, 30) . ' · COD ' . ($it['obs']['cod'] ?? '—')) ?><br><?php endforeach; ?></small></td>
<td><small><?= h((string)$r['validado']) ?></small></td><td><small><?= h(substr((string)$r['gerado_em'], 0, 16)) ?><br><?= h((string)$r['por']) ?></small></td>
<td><a class="cf-x" href="?baixar=<?= (int)$r['id'] ?>&ver=1" target="_blank" title="ver">📄</a> <a class="cf-x" href="?baixar=<?= (int)$r['id'] ?>" title="baixar">⬇</a></td></tr>
<?php endforeach; ?></tbody></table></div>
<p><a class="btn cf-btn-sec" id="h-zip" href="#" style="display:none">⬇ ZIP dos selecionados</a></p>
<?php endif; ?>

<script>
(function(){
  const csrf = document.querySelector('meta[name=csrf]').content;
  const brl = v => 'R$ ' + v.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  document.querySelectorAll('.ro-per button').forEach(b => b.addEventListener('click', () => { document.querySelectorAll('.ro-per button').forEach(x => x.classList.remove('on')); b.classList.add('on'); document.getElementById('ro-periodo').value = b.dataset.p; document.getElementById('ro-custom').style.display = b.dataset.p === 'custom' ? '' : 'none'; }));
  function soma(){ const sel = [...document.querySelectorAll('.ro-sel:checked')]; const n = document.getElementById('ro-n'); if (!n) return; let t = 0; sel.forEach(c => t += parseFloat(c.closest('tr').dataset.valor) || 0); n.textContent = sel.length; document.getElementById('ro-total').textContent = brl(t); document.getElementById('btn-gerar').disabled = !sel.length; }
  document.querySelectorAll('.ro-sel').forEach(c => c.addEventListener('change', soma)); soma();
  const todos = document.getElementById('ro-todos'); if (todos) todos.addEventListener('change', () => { document.querySelectorAll('.ro-sel').forEach(c => c.checked = todos.checked); soma(); });
  const bg = document.getElementById('btn-gerar');
  if (bg) bg.addEventListener('click', async () => {
    const forcar = document.getElementById('ro-forcar').checked;
    const trs = [...document.querySelectorAll('.ro-sel:checked')].map(c => c.closest('tr'));
    const bloq = trs.filter(tr => tr.dataset.nao === '1' && !forcar);
    if (bloq.length) { alert(bloq.length + ' título(s) selecionado(s) não foram encontrados no Omie. Cadastre lá primeiro ou marque "incluir títulos não encontrados".'); return; }
    const itens = trs.map(tr => JSON.parse(tr.dataset.t)); if (!itens.length) return;
    bg.disabled = true;
    const r = await fetch('/recibos-omie/', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({csrf, acao:'gerar', itens, agrupar: document.getElementById('ro-agrupar').checked ? 1 : 0, data: document.getElementById('ro-data').value, substituir: document.getElementById('ro-subst').checked ? 1 : 0})});
    let j = null; try { j = await r.json(); } catch(e) {}
    if (!j || !j.ok) { alert(j && j.erro ? j.erro : ('erro ' + r.status)); bg.disabled = false; return; }
    if (j.ids.length === 1) window.open('?baixar=' + j.ids[0] + '&ver=1', '_blank'); else location.href = '?zip=' + j.ids.join(',');
    setTimeout(() => location.href = '/recibos-omie/', 1500);
  });
  function hz(){ const ids = [...document.querySelectorAll('.h-sel:checked')].map(c => +c.closest('tr').dataset.id); const z = document.getElementById('h-zip'); if (!z) return; z.style.display = ids.length ? '' : 'none'; z.href = '?zip=' + ids.join(','); z.textContent = '⬇ ZIP (' + ids.length + ')'; }
  document.querySelectorAll('.h-sel').forEach(c => c.addEventListener('change', hz));
  const ht = document.getElementById('h-todos'); if (ht) ht.addEventListener('change', () => { document.querySelectorAll('.h-sel').forEach(c => c.checked = ht.checked); hz(); });
})();
</script>
</div>
<?php portal_footer();
