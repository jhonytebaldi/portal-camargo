<?php
/* =====================================================================
   capa-financeira/recibos.php — recibos ("Declaração e Recibo") dos
   lançamentos a pagar confirmados/exportados. Filtra, gera (1 por
   lançamento ou agrupado por pessoa+data), baixa (PDF ou ZIP), regenera.
   Corretor sem acesso à ferramenta: vê e baixa só os próprios recibos
   (users.broker_id = cf_pessoas.broker_id) se corretor_baixa_recibo = 1.
   GET  ?baixar=ID · ?zip=1,2,3 · filtros
   POST JSON acao: gerar | substituir
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
require_once __DIR__ . '/lib/Recibo.php';

$u = require_login();
$pdo = db();
$gestor = user_has_tool('capa-financeira', $u);
$minhaPessoa = null;
if (!$gestor) {
    if ((string)cf_config('corretor_baixa_recibo', '1') !== '1' || empty($u['broker_id'])) { http_response_code(403); exit('Acesso negado.'); }
    $q = $pdo->prepare('SELECT * FROM cf_pessoas WHERE broker_id = ? LIMIT 1'); $q->execute([(string)$u['broker_id']]);
    $minhaPessoa = $q->fetch() ?: null;
    if (!$minhaPessoa) { http_response_code(403); exit('Seu usuário ainda não está vinculado a uma pessoa do financeiro. Peça ao financeiro para vincular em Pessoas.'); }
}
$empresas = array_column(cf_config('empresas', []), null, 'id');
$cidade = (string)cf_config('cidade_recibo', 'Joinville');

/* ---------- downloads ---------- */
function cf_rec_pode(array $r, bool $gestor, ?array $minha): bool { return $gestor || ($minha && (int)$r['pessoa_id'] === (int)$minha['id']); }
if (isset($_GET['baixar'])) {
    $st = $pdo->prepare('SELECT * FROM cf_recibos WHERE id = ?'); $st->execute([(int)$_GET['baixar']]); $r = $st->fetch();
    if (!$r || !cf_rec_pode($r, $gestor, $minhaPessoa) || !is_file($r['arquivo_path'])) { http_response_code(404); exit('recibo não encontrado'); }
    header('Content-Type: application/pdf'); header('Content-Disposition: ' . (isset($_GET['ver']) ? 'inline' : 'attachment') . '; filename="' . $r['arquivo_nome'] . '"');
    header('Content-Length: ' . filesize($r['arquivo_path'])); readfile($r['arquivo_path']); exit;
}
if (isset($_GET['zip'])) {
    $ids = array_filter(array_map('intval', explode(',', (string)$_GET['zip'])));
    if (!$ids) exit('nada para baixar');
    $st = $pdo->query('SELECT * FROM cf_recibos WHERE id IN (' . implode(',', $ids) . ')');
    $tmp = tempnam(sys_get_temp_dir(), 'rec'); $z = new ZipArchive(); $z->open($tmp, ZipArchive::OVERWRITE); $n = 0;
    foreach ($st->fetchAll() as $r) if (cf_rec_pode($r, $gestor, $minhaPessoa) && is_file($r['arquivo_path'])) { $z->addFile($r['arquivo_path'], $r['arquivo_nome']); $n++; }
    $z->close();
    if (!$n) { @unlink($tmp); exit('nada para baixar'); }
    header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="recibos_' . date('Ymd-Hi') . '.zip"'); header('Content-Length: ' . filesize($tmp));
    readfile($tmp); @unlink($tmp); exit;
}

/** lançamentos a pagar elegíveis (confirmado/exportado), com dados da capa e recibo atual */
function cf_rec_linhas(PDO $pdo, array $f, ?array $ids = null): array
{
    $sql = "SELECT l.*, c.cod AS capa_cod, c.cliente AS capa_cliente, c.construtora AS capa_construtora, c.unidade AS capa_unidade, c.bairro AS capa_bairro,
                   c.data_venda AS capa_data_venda, c.empresa AS capa_empresa, r.id AS rec_id, r.numero AS rec_numero, r.arquivo_nome AS rec_arquivo, r.data_recibo AS rec_data, r.gerado_em AS rec_em
            FROM cf_lancamentos l JOIN cf_capas c ON c.id = l.capa_id LEFT JOIN cf_recibos r ON r.id = l.recibo_id AND r.status = 'atual'
            WHERE l.tipo = 'P' AND l.status IN ('confirmado','exportado') AND c.status = 'confirmada'";
    $a = [];
    if ($ids !== null) { if (!$ids) return []; $sql .= ' AND l.id IN (' . implode(',', array_map('intval', $ids)) . ')'; }
    if (!empty($f['pessoa'])) { $sql .= ' AND l.pessoa_id = ?'; $a[] = (int)$f['pessoa']; }
    if (!empty($f['empresa'])) { $sql .= ' AND c.empresa = ?'; $a[] = $f['empresa']; }
    if (!empty($f['de'])) { $sql .= ' AND l.data_prevista >= ?'; $a[] = $f['de']; }
    if (!empty($f['ate'])) { $sql .= ' AND l.data_prevista <= ?'; $a[] = $f['ate']; }
    if (!empty($f['cod'])) { $sql .= ' AND c.cod = ?'; $a[] = $f['cod']; }
    if (!empty($f['busca'])) { $sql .= ' AND (c.cliente LIKE ? OR c.construtora LIKE ? OR l.codigo_integracao LIKE ? OR l.cf_raw LIKE ?)'; $b = '%' . $f['busca'] . '%'; array_push($a, $b, $b, $b, $b); }
    if (($f['recibo'] ?? '') === 'sem') $sql .= ' AND r.id IS NULL';
    if (($f['recibo'] ?? '') === 'com') $sql .= ' AND r.id IS NOT NULL';
    $sql .= ' ORDER BY l.data_prevista DESC, c.cod, l.linha_xlsx';
    $st = $pdo->prepare($sql); $st->execute($a);
    return $st->fetchAll();
}

/* ---------- ações ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$gestor) { http_response_code(403); exit(json_encode(['ok' => false, 'erro' => 'sem permissão'])); }
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $_POST['csrf'] = $in['csrf'] ?? ''; csrf_check();
    $falha = function (string $m, int $c = 400): never { http_response_code($c); exit(json_encode(['ok' => false, 'erro' => $m], JSON_UNESCAPED_UNICODE)); };
    $acao = (string)($in['acao'] ?? '');
    try {
        if ($acao === 'gerar') {
            $ids = array_values(array_unique(array_map('intval', (array)($in['ids'] ?? []))));
            if (!$ids) $falha('nenhuma linha selecionada');
            $agrupar = !empty($in['agrupar']);
            $dataFixa = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['data'] ?? '')) ? (string)$in['data'] : null;
            $substituir = !empty($in['substituir']);
            $linhas = cf_rec_linhas($pdo, [], $ids);
            if (count($linhas) !== count($ids)) $falha('alguma linha não está confirmada — recarregue a página');
            $pessoas = cf_pessoas(false);
            // grupos: 1 recibo por linha, ou por pessoa + data prevista (+ empresa)
            $grupos = [];
            foreach ($linhas as $l) {
                if (!$l['pessoa_id'] || !isset($pessoas[(int)$l['pessoa_id']])) $falha($l['codigo_integracao'] . ': linha sem pessoa do dicionário');
                if ($l['rec_id'] && !$substituir) $falha($l['codigo_integracao'] . ' já tem recibo (' . $l['rec_numero'] . '). Marque "gerar de novo" para substituir.');
                $k = $agrupar ? $l['pessoa_id'] . '|' . ($dataFixa ?: $l['data_prevista']) . '|' . $l['capa_empresa'] : (string)$l['id'];
                $grupos[$k][] = $l;
            }
            $dir = cf_data_dir() . '/recibos/' . date('Y'); if (!is_dir($dir)) @mkdir($dir, 0750, true);
            $gerados = [];
            $pdo->beginTransaction();
            foreach ($grupos as $g) {
                $p = $pessoas[(int)$g[0]['pessoa_id']]; $emp = $empresas[$g[0]['capa_empresa']] ?? ['nome' => strtoupper($g[0]['capa_empresa']), 'razao' => '', 'cnpj' => ''];
                $data = $dataFixa ?: (string)($g[0]['data_prevista'] ?: date('Y-m-d'));
                $logo = !empty($emp['logo_propria']) && is_file(__DIR__ . '/modelos/logo_' . $emp['id'] . '.jpg') ? __DIR__ . '/modelos/logo_' . $emp['id'] . '.jpg' : null;
                $r = Recibo::gerar($g, $p, $emp, $data, $cidade, $logo);
                $nome = Recibo::nomeArquivo($r['numero'], $p['nome']);
                $path = $dir . '/' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4) . '-' . $nome;
                file_put_contents($path, $r['pdf']);
                $lids = array_map(fn($l) => (int)$l['id'], $g);
                foreach ($g as $l) if ($l['rec_id']) $pdo->prepare("UPDATE cf_recibos SET status = 'substituido' WHERE id = ?")->execute([$l['rec_id']]);
                $pdo->prepare('INSERT INTO cf_recibos (numero, pessoa_id, empresa, lancamento_ids, valor, data_recibo, arquivo_nome, arquivo_path, gerado_por) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$r['numero'], $p['id'], $g[0]['capa_empresa'], json_encode($lids), $r['valor'], $data, $nome, $path, $u['id']]);
                $rid = (int)$pdo->lastInsertId();
                $pdo->prepare('UPDATE cf_lancamentos SET recibo_id = ? WHERE id IN (' . implode(',', $lids) . ')')->execute([$rid]);
                $gerados[] = $rid;
            }
            $pdo->commit();
            exit(json_encode(['ok' => true, 'ids' => $gerados, 'n' => count($gerados)]));
        }
        $falha('ação inválida');
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $falha('erro: ' . $e->getMessage(), 500); }
}

/* ---------- tela ---------- */
$f = ['pessoa' => $gestor ? (int)($_GET['pessoa'] ?? 0) : (int)$minhaPessoa['id'], 'empresa' => (string)($_GET['empresa'] ?? ''), 'de' => (string)($_GET['de'] ?? ''), 'ate' => (string)($_GET['ate'] ?? ''),
      'cod' => trim((string)($_GET['cod'] ?? '')), 'busca' => trim((string)($_GET['busca'] ?? '')), 'recibo' => (string)($_GET['recibo'] ?? '')];
foreach (['de', 'ate'] as $k) if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k])) $f[$k] = '';
if (!$gestor) $f['recibo'] = 'com';
$linhas = cf_rec_linhas($pdo, $f);
$pessoas = cf_pessoas(false);

portal_header('Recibos', $u);
?>
<meta name="csrf" content="<?= h(csrf_token()) ?>">
<style>main.wrap{max-width:1500px}</style>
<div class="cf-rev">
<div class="cf-top">
  <div><h1 class="home-titulo"><?= $gestor ? 'Recibos de comissão' : 'Meus recibos' ?></h1>
    <p class="home-sub"><?= $gestor ? '"Declaração e Recibo" por lançamento a pagar confirmado. Gere antes do pagamento, baixe em PDF ou ZIP; o número do recibo é o código de integração.' : 'Recibos das suas comissões e bônus, gerados pelo financeiro.' ?></p></div>
  <?php if ($gestor): ?><nav class="cf-nav"><a href="/capa-financeira/">← Capas</a><a href="/capa-financeira/exportar.php">Exportar</a><a href="/capa-financeira/pessoas.php">Pessoas</a></nav><?php endif; ?>
</div>

<form method="get" class="cf-form" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px">
  <?php if ($gestor): ?>
  <label>Pessoa<select name="pessoa" class="cf-in"><option value="">todas</option><?php foreach ($pessoas as $pid => $p): ?><option value="<?= $pid ?>" <?= $f['pessoa'] === $pid ? 'selected' : '' ?>><?= h($p['nome']) ?><?= $p['ativo'] ? '' : ' (desligado)' ?></option><?php endforeach; ?></select></label>
  <label>Empresa<select name="empresa" class="cf-in"><option value="">todas</option><?php foreach ($empresas as $id => $e): ?><option value="<?= h($id) ?>" <?= $f['empresa'] === $id ? 'selected' : '' ?>><?= h($e['nome']) ?></option><?php endforeach; ?></select></label>
  <?php endif; ?>
  <label>Pagamento de<input type="date" name="de" class="cf-in" value="<?= h($f['de']) ?>"></label>
  <label>até<input type="date" name="ate" class="cf-in" value="<?= h($f['ate']) ?>"></label>
  <label>COD<input name="cod" class="cf-in" value="<?= h($f['cod']) ?>" style="width:90px"></label>
  <label>Busca<input name="busca" class="cf-in" value="<?= h($f['busca']) ?>" placeholder="cliente, construtora, código"></label>
  <?php if ($gestor): ?><label>Recibo<select name="recibo" class="cf-in"><option value="">todos</option><option value="sem" <?= $f['recibo'] === 'sem' ? 'selected' : '' ?>>sem recibo</option><option value="com" <?= $f['recibo'] === 'com' ? 'selected' : '' ?>>com recibo</option></select></label><?php endif; ?>
  <button class="btn" type="submit">Filtrar</button>
</form>

<?php if ($gestor && $linhas): ?>
<div class="cf-barra">
  <div class="cf-pend ok" style="display:flex;gap:16px;flex-wrap:wrap;align-items:center">
    <label class="cf-mini" style="margin:0"><input type="checkbox" id="rec-agrupar"> agrupar num só recibo as linhas da mesma pessoa e mesma data (cada venda numa linha)</label>
    <label>Data do recibo <input type="date" id="rec-data" class="cf-in" title="vazio = data prevista de pagamento de cada linha"></label>
    <label class="cf-mini" style="margin:0"><input type="checkbox" id="rec-subst"> gerar de novo as que já têm recibo (substitui)</label>
  </div>
  <div class="cf-acoes"><button class="btn" id="btn-gerar">Gerar recibos (<span id="rec-n">0</span>)</button> <a class="btn cf-btn-sec" id="btn-zip" href="#" style="display:none">⬇ ZIP dos selecionados</a></div>
</div>
<?php endif; ?>

<?php if (!$linhas): ?><p class="home-sub">Nenhum lançamento com esses filtros.</p><?php else: ?>
<div class="cf-tbl-wrap"><table class="grid cf-tbl" id="tbl-rec">
<thead><tr><?php if ($gestor): ?><th><input type="checkbox" id="rec-todos"></th><?php endif; ?><th>Pagamento</th><th>Código</th><th>Pessoa</th><th>Função</th><th>Capa</th><th>Valor</th><th>Recibo</th><th></th></tr></thead>
<tbody>
<?php $tot = 0; foreach ($linhas as $l): $p = $l['pessoa_id'] ? ($pessoas[(int)$l['pessoa_id']] ?? null) : null; $tot += (float)$l['valor']; ?>
<tr class="cf-row" data-id="<?= (int)$l['id'] ?>" data-rec="<?= (int)$l['rec_id'] ?>" data-valor="<?= (float)$l['valor'] ?>">
  <?php if ($gestor): ?><td><input type="checkbox" class="rec-sel" <?= $p ? '' : 'disabled title="sem pessoa"' ?>></td><?php endif; ?>
  <td><?= cf_data_br($l['data_prevista']) ?></td>
  <td><b><?= h((string)$l['codigo_integracao']) ?></b><br><small class="cf-raw"><?= h(strtoupper((string)$l['capa_empresa'])) ?> · <?= $l['status'] === 'exportado' ? 'no Omie' : 'confirmado' ?></small></td>
  <td><?= h($p['nome'] ?? $l['cf_raw']) ?><?= $p ? '<br><small class="cf-raw">' . h(Recibo::identidade($p)[0]) . '</small>' : '' ?></td>
  <td><?= h((string)$l['funcao']) ?><?= $l['natureza'] === 'BONUS' ? ' <span class="cf-tag">BONUS</span>' : '' ?></td>
  <td><?php if ($gestor): ?><a href="/capa-financeira/revisar.php?id=<?= (int)$l['capa_id'] ?>"><?= h((string)$l['capa_cod']) ?></a><?php else: ?><?= h((string)$l['capa_cod']) ?><?php endif; ?><br><small class="cf-raw"><?= h(mb_substr((string)$l['capa_cliente'], 0, 40)) ?> · <?= h(mb_substr((string)$l['capa_construtora'], 0, 30)) ?></small></td>
  <td class="cf-num"><?= cf_brl($l['valor']) ?></td>
  <td><?php if ($l['rec_id']): ?><a href="?baixar=<?= (int)$l['rec_id'] ?>&ver=1" target="_blank" title="<?= h((string)$l['rec_arquivo']) ?>">📄 <?= h((string)$l['rec_numero']) ?></a><br><small class="cf-raw"><?= cf_data_br($l['rec_data']) ?> · gerado <?= h(substr((string)$l['rec_em'], 0, 10)) ?></small><?php else: ?><span class="cf-raw">—</span><?php endif; ?></td>
  <td><?php if ($l['rec_id']): ?><a class="cf-x" href="?baixar=<?= (int)$l['rec_id'] ?>" title="baixar PDF">⬇</a><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr><td colspan="<?= $gestor ? 6 : 5 ?>">Total (<?= count($linhas) ?> linhas)</td><td class="cf-num"><?= cf_brl($tot) ?></td><td colspan="2"></td></tr></tfoot>
</table></div>
<?php endif; ?>

<?php if ($gestor): ?>
<script>
(function(){
  const csrf = document.querySelector('meta[name=csrf]').content;
  function soma(){ const sel = [...document.querySelectorAll('.rec-sel:checked')]; const n = document.getElementById('rec-n'); if (!n) return;
    n.textContent = sel.length; document.getElementById('btn-gerar').disabled = !sel.length;
    const recs = sel.map(c => +c.closest('tr').dataset.rec).filter(Boolean); const z = document.getElementById('btn-zip');
    z.style.display = recs.length ? '' : 'none'; z.href = '?zip=' + recs.join(','); z.textContent = '⬇ ZIP (' + recs.length + ' recibo' + (recs.length > 1 ? 's' : '') + ')'; }
  document.querySelectorAll('.rec-sel').forEach(c => c.addEventListener('change', soma)); soma();
  const todos = document.getElementById('rec-todos'); if (todos) todos.addEventListener('change', () => { document.querySelectorAll('.rec-sel:not(:disabled)').forEach(c => c.checked = todos.checked); soma(); });
  const bg = document.getElementById('btn-gerar');
  if (bg) bg.addEventListener('click', async () => {
    const ids = [...document.querySelectorAll('.rec-sel:checked')].map(c => +c.closest('tr').dataset.id); if (!ids.length) return;
    bg.disabled = true;
    const r = await fetch('/capa-financeira/recibos.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({csrf, acao:'gerar', ids,
      agrupar: document.getElementById('rec-agrupar').checked ? 1 : 0, data: document.getElementById('rec-data').value, substituir: document.getElementById('rec-subst').checked ? 1 : 0})});
    let j = null; try { j = await r.json(); } catch(e) {}
    if (!j || !j.ok) { alert(j && j.erro ? j.erro : ('erro ' + r.status)); bg.disabled = false; return; }
    if (j.ids.length === 1) window.open('?baixar=' + j.ids[0] + '&ver=1', '_blank'); else location.href = '?zip=' + j.ids.join(',');
    setTimeout(() => location.reload(), 1200);
  });
})();
</script>
<?php endif; ?>
</div>
<?php portal_footer();
