<?php
/* =====================================================================
   capa-financeira/exportar.php — gera as planilhas de importação do Omie
   (Contas a Pagar / Contas a Receber) a partir dos lançamentos confirmados
   e ainda não exportados. Também: download das exportações anteriores,
   desfazer uma exportação (se a importação no Omie falhou) e a lista de
   alterações feitas depois de exportar (ajuste manual no Omie).
   GET  ?empresa=&tipo=P|R            tela
   GET  ?baixar=ID                    download do .xlsx gerado
   POST JSON acao: editar | gerar | desfazer | ajustado
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
require_once __DIR__ . '/lib/OmieXlsx.php';
require_once __DIR__ . '/lib/Exportacao.php';

$u = require_tool('capa-financeira');
$pdo = db();
$empresas = array_column(cf_config('empresas', []), null, 'id');
$modelos = ['P' => __DIR__ . '/modelos/Modelo_Omie_Contas_Pagar_v1_1_5.xlsx', 'R' => __DIR__ . '/modelos/Modelo_Omie_Contas_Receber_v1_0_6.xlsx'];

/* ---------- download ---------- */
if (isset($_GET['baixar'])) {
    $st = $pdo->prepare('SELECT * FROM cf_exportacoes WHERE id = ?'); $st->execute([(int)$_GET['baixar']]);
    $e = $st->fetch();
    if (!$e || !is_file($e['arquivo_path'])) { http_response_code(404); exit('arquivo não encontrado'); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $e['arquivo_nome'] . '"');
    header('Content-Length: ' . filesize($e['arquivo_path']));
    readfile($e['arquivo_path']); exit;
}

/** lançamentos confirmados (não exportados) da empresa/tipo, com capa e pessoa */
function cf_exp_candidatas(PDO $pdo, string $empresa, string $tipo, ?array $ids = null): array
{
    $sql = "SELECT l.*, c.cod AS capa_cod, c.cliente AS capa_cliente, c.construtora AS capa_construtora, c.bairro AS capa_bairro, c.unidade AS capa_unidade,
                   c.data_venda AS capa_data_venda, c.empresa AS capa_empresa, c.versao AS capa_versao
            FROM cf_lancamentos l JOIN cf_capas c ON c.id = l.capa_id
            WHERE l.status = 'confirmado' AND c.status = 'confirmada' AND c.empresa = ? AND l.tipo = ?";
    $args = [$empresa, $tipo];
    if ($ids !== null) { if (!$ids) return []; $sql .= ' AND l.id IN (' . implode(',', array_map('intval', $ids)) . ')'; }
    $sql .= ' ORDER BY c.cod, l.linha_xlsx';
    $st = $pdo->prepare($sql); $st->execute($args);
    return $st->fetchAll();
}
function cf_exp_capa(array $l): array
{
    return ['cod' => $l['capa_cod'], 'cliente' => $l['capa_cliente'], 'construtora' => $l['capa_construtora'], 'bairro' => $l['capa_bairro'],
            'unidade' => $l['capa_unidade'], 'data_venda' => $l['capa_data_venda']];
}

/* ---------- ações JSON ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $_POST['csrf'] = $in['csrf'] ?? ''; csrf_check();
    $falha = function (string $m, int $c = 400): never { http_response_code($c); exit(json_encode(['ok' => false, 'erro' => $m], JSON_UNESCAPED_UNICODE)); };
    $acao = (string)($in['acao'] ?? '');
    try {
        if ($acao === 'editar') {
            $id = (int)($in['id'] ?? 0); $campo = (string)($in['campo'] ?? ''); $valor = trim((string)($in['valor'] ?? ''));
            $st = $pdo->prepare("SELECT * FROM cf_lancamentos WHERE id = ? AND status = 'confirmado'"); $st->execute([$id]);
            if (!($l = $st->fetch())) $falha('linha não está confirmada/pendente de exportação');
            if ($campo === 'chave_pix') {
                $a = Pix::analisar($valor);
                if (!$a['valido']) $falha('chave Pix inválida: ' . $a['erro']);
                $valor = (string)($a['valor'] ?? '');
            } elseif (!in_array($campo, ['conta_corrente', 'cliente_omie', 'nota_fiscal'], true)) $falha('campo inválido');
            $pdo->prepare("UPDATE cf_lancamentos SET $campo = ? WHERE id = ?")->execute([$valor !== '' ? mb_substr($valor, 0, 60) : null, $id]);
            if ($campo === 'conta_corrente' && !empty($in['aplicar_todas'])) {
                // mesma conta em todas as linhas confirmadas do mesmo tipo/empresa ainda sem conta
                $pdo->prepare("UPDATE cf_lancamentos l JOIN cf_capas c ON c.id = l.capa_id SET l.conta_corrente = ?
                               WHERE l.status = 'confirmado' AND l.tipo = ? AND c.empresa = ? AND (l.conta_corrente IS NULL OR l.conta_corrente = '')")
                    ->execute([$valor ?: null, $l['tipo'], (string)$in['empresa']]);
            }
            exit(json_encode(['ok' => true, 'valor' => $valor]));
        }
        if ($acao === 'gerar') {
            $empresa = (string)($in['empresa'] ?? ''); $tipo = (string)($in['tipo'] ?? 'P');
            if (!isset($empresas[$empresa]) || !in_array($tipo, ['P', 'R'], true)) $falha('empresa/tipo inválidos');
            $ids = array_values(array_unique(array_map('intval', (array)($in['ids'] ?? []))));
            if (!$ids) $falha('nenhuma linha selecionada');
            $reg = (string)($in['data_registro'] ?? date('Y-m-d'));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reg)) $reg = date('Y-m-d');
            $opts = ['data_registro' => $reg, 'emissao' => in_array($in['emissao'] ?? '', ['venda', 'registro', 'prevista'], true) ? $in['emissao'] : 'venda'];
            $pessoas = cf_pessoas(false);
            $linhas = cf_exp_candidatas($pdo, $empresa, $tipo, $ids);
            if (count($linhas) !== count($ids)) $falha('alguma linha selecionada já foi exportada ou não está confirmada — recarregue a página');
            $rows = []; $erros = []; $avisos = []; $avisosPor = []; $total = 0.0; $clientes = [];
            foreach ($linhas as $l) {
                $m = Exportacao::montar($l, cf_exp_capa($l), $l['pessoa_id'] ? ($pessoas[(int)$l['pessoa_id']] ?? null) : null, $empresas[$empresa], $opts);
                if ($m['erros']) { $erros[] = $l['codigo_integracao'] . ': ' . implode('; ', $m['erros']); continue; }
                foreach ($m['avisos'] as $a) $avisosPor[$a][] = $l['codigo_integracao'];
                if ($tipo === 'R') $clientes[$m['cols']['C']['s']] = true;
                $rows[] = $m['cols']; $total += (float)$l['valor'];
            }
            if ($erros) $falha('Corrija antes de gerar: ' . implode(' | ', array_slice($erros, 0, 6)));
            foreach ($avisosPor as $a => $cods) $avisos[] = ucfirst($a) . ' — ' . count($cods) . ' linha(s): ' . implode(', ', array_slice($cods, 0, 12)) . (count($cods) > 12 ? '…' : '');
            if ($tipo === 'R' && $clientes) $avisos[] = 'Clientes das contas a receber — confira se existem no Omie (senão cadastre com CPF e data de nascimento): ' . implode(', ', array_keys($clientes));

            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO cf_exportacoes (tipo, empresa, arquivo_nome, arquivo_path, n_linhas, total, data_registro, avisos, gerado_por) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$tipo, $empresa, 'tmp', 'tmp', count($rows), $total, $reg, json_encode($avisos, JSON_UNESCAPED_UNICODE), $u['id']]);
            $expId = (int)$pdo->lastInsertId();
            $agora = new DateTimeImmutable();
            $nome = Exportacao::nomeArquivo($tipo, $empresa, $expId, $agora);
            $dir = cf_data_dir() . '/exportacoes/' . $agora->format('Y');
            if (!is_dir($dir)) @mkdir($dir, 0750, true);
            $path = $dir . '/' . $nome;
            OmieXlsx::gerar($modelos[$tipo], $rows, $path);
            $pdo->prepare('UPDATE cf_exportacoes SET arquivo_nome = ?, arquivo_path = ? WHERE id = ?')->execute([$nome, $path, $expId]);
            $pdo->prepare("UPDATE cf_lancamentos SET status = 'exportado', exportacao_id = ?, alteracao_pos_exportacao = 0 WHERE id IN (" . implode(',', $ids) . ") AND status = 'confirmado'")->execute([$expId]);
            $pdo->commit();
            exit(json_encode(['ok' => true, 'exportacao_id' => $expId, 'arquivo' => $nome, 'n' => count($rows), 'total' => $total, 'avisos' => $avisos], JSON_UNESCAPED_UNICODE));
        }
        if ($acao === 'desfazer') {
            $id = (int)($in['id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM cf_exportacoes WHERE id = ? AND status = 'gerada'"); $st->execute([$id]);
            if (!$st->fetch()) $falha('exportação não encontrada ou já desfeita');
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE cf_lancamentos SET status = 'confirmado', exportacao_id = NULL WHERE exportacao_id = ? AND status = 'exportado'")->execute([$id]);
            $pdo->prepare("UPDATE cf_exportacoes SET status = 'desfeita' WHERE id = ?")->execute([$id]);
            $pdo->commit();
            exit(json_encode(['ok' => true]));
        }
        if ($acao === 'ajustado') {
            $pdo->prepare('UPDATE cf_lancamentos SET alteracao_pos_exportacao = 0 WHERE id = ?')->execute([(int)($in['id'] ?? 0)]);
            exit(json_encode(['ok' => true]));
        }
        $falha('ação inválida');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $falha('erro: ' . $e->getMessage(), 500);
    }
}

/* ---------- tela ---------- */
$empresa = (string)($_GET['empresa'] ?? array_key_first($empresas));
if (!isset($empresas[$empresa])) $empresa = (string)array_key_first($empresas);
$tipo = ($_GET['tipo'] ?? 'P') === 'R' ? 'R' : 'P';
$emp = $empresas[$empresa];
$pessoas = cf_pessoas(false);
$opts = ['data_registro' => date('Y-m-d'), 'emissao' => 'venda'];
$linhas = cf_exp_candidatas($pdo, $empresa, $tipo, null);
$contagem = [];
foreach ($pdo->query("SELECT c.empresa, l.tipo, COUNT(*) n FROM cf_lancamentos l JOIN cf_capas c ON c.id = l.capa_id WHERE l.status = 'confirmado' AND c.status = 'confirmada' GROUP BY c.empresa, l.tipo")->fetchAll() as $r) $contagem[$r['empresa']][$r['tipo']] = (int)$r['n'];
$montadas = [];
foreach ($linhas as $l) $montadas[(int)$l['id']] = Exportacao::montar($l, cf_exp_capa($l), $l['pessoa_id'] ? ($pessoas[(int)$l['pessoa_id']] ?? null) : null, $emp, $opts);
$st = $pdo->prepare("SELECT l.*, c.cod AS capa_cod, c.cliente AS capa_cliente, e.arquivo_nome FROM cf_lancamentos l JOIN cf_capas c ON c.id = l.capa_id LEFT JOIN cf_exportacoes e ON e.id = l.exportacao_id
                     WHERE l.alteracao_pos_exportacao = 1 AND l.status IN ('exportado','confirmado') AND c.empresa = ? ORDER BY l.id DESC LIMIT 100");
$st->execute([$empresa]); $alteradas = $st->fetchAll();
$st = $pdo->prepare('SELECT e.*, u.nome AS por FROM cf_exportacoes e LEFT JOIN users u ON u.id = e.gerado_por WHERE e.empresa = ? ORDER BY e.id DESC LIMIT 20');
$st->execute([$empresa]); $historico = $st->fetchAll();

portal_header('Exportar para o Omie', $u);
?>
<meta name="csrf" content="<?= h(csrf_token()) ?>">
<style>main.wrap{max-width:1680px}</style>
<script src="/capa-financeira/pix.js?v=2"></script>
<div class="cf-rev">
<div class="cf-top">
  <div><h1 class="home-titulo">Exportar para o Omie</h1>
    <p class="home-sub">Planilha de importação (modelo oficial) com os lançamentos confirmados e ainda não exportados. Depois de importar no Omie, a linha fica marcada como exportada.</p></div>
  <nav class="cf-nav"><a href="/capa-financeira/">← Capas</a><a href="/capa-financeira/pessoas.php">Pessoas</a><a href="/capa-financeira/configuracoes.php">Configurações</a></nav>
</div>

<div class="admin-tabs">
  <?php foreach ($empresas as $id => $e): ?><a class="<?= $id === $empresa ? 'on' : '' ?>" href="?empresa=<?= h($id) ?>&tipo=<?= $tipo ?>"><?= h($e['nome']) ?> <small>(<?= ($contagem[$id]['P'] ?? 0) ?> P / <?= ($contagem[$id]['R'] ?? 0) ?> R)</small></a><?php endforeach; ?>
</div>
<div class="admin-tabs">
  <a class="<?= $tipo === 'P' ? 'on' : '' ?>" href="?empresa=<?= h($empresa) ?>&tipo=P">Contas a pagar (<?= $contagem[$empresa]['P'] ?? 0 ?>)</a>
  <a class="<?= $tipo === 'R' ? 'on' : '' ?>" href="?empresa=<?= h($empresa) ?>&tipo=R">Contas a receber (<?= $contagem[$empresa]['R'] ?? 0 ?>)</a>
</div>

<?php if (!$linhas): ?>
  <p class="home-sub">Nada pendente de exportação para <?= h($emp['nome']) ?> (<?= $tipo === 'P' ? 'contas a pagar' : 'contas a receber' ?>). Confirme capas na revisão para elas aparecerem aqui.</p>
<?php else: ?>
<div class="cf-barra">
  <div class="cf-pend ok" style="display:flex;gap:18px;flex-wrap:wrap;align-items:center">
    <label>Data de registro <input type="date" id="exp-reg" class="cf-in" value="<?= date('Y-m-d') ?>"></label>
    <label>Data de emissão =
      <select id="exp-emi" class="cf-in"><option value="venda">data da venda (capa)</option><option value="registro">data de registro</option><option value="prevista">vencimento</option></select></label>
    <span class="cf-dica" style="margin:0">Vencimento e Previsão = data prevista da linha. Nº Documento = código de integração. Observações levam cliente, construtora, imóvel, venda, COD, recibo, função e condição.</span>
  </div>
  <div class="cf-acoes"><button class="btn" id="btn-gerar">Gerar planilha (<span id="exp-n">0</span> linhas · <span id="exp-total">R$ 0,00</span>)</button></div>
</div>

<?php if ($tipo === 'P'): ?>
<div class="cf-tbl-wrap"><table class="grid cf-tbl" id="tbl-exp">
<thead><tr><th><input type="checkbox" id="exp-todos" checked></th><th>Capa</th><th>Código</th><th>Pessoa → Fornecedor (Omie)</th><th>Categoria</th><th>Conta corrente</th><th>Valor</th><th>Vencimento</th><th>Nota Fiscal</th><th>Chave Pix</th><th>Problemas</th></tr></thead>
<tbody>
<?php foreach ($linhas as $l): $m = $montadas[(int)$l['id']]; $p = $l['pessoa_id'] ? ($pessoas[(int)$l['pessoa_id']] ?? null) : null; $ok = !$m['erros']; ?>
<tr class="cf-row <?= $ok ? '' : 'cf-tem-grave' ?>" data-id="<?= (int)$l['id'] ?>" data-valor="<?= (float)$l['valor'] ?>">
  <td><input type="checkbox" class="exp-sel" <?= $ok ? 'checked' : 'disabled' ?>></td>
  <td><a href="/capa-financeira/revisar.php?id=<?= (int)$l['capa_id'] ?>"><?= h((string)$l['capa_cod']) ?></a><br><small class="cf-raw"><?= h(mb_substr((string)$l['capa_cliente'], 0, 40)) ?></small></td>
  <td><b><?= h((string)$l['codigo_integracao']) ?></b><br><small class="cf-raw">L<?= (int)$l['linha_xlsx'] ?> · <?= h((string)$l['funcao']) ?><?= $l['natureza'] === 'BONUS' ? ' (BONUS)' : '' ?></small></td>
  <td><?= h($p['nome'] ?? '—') ?><br><small class="cf-raw">→ <?= h($m['cols']['C']['s'] ?? '—') ?><?= $p && !empty($p['departamento_omie']) ? ' · dep.: ' . h($p['departamento_omie']) : '' ?></small></td>
  <td><?= h((string)$l['categoria']) ?></td>
  <td><input type="text" class="cf-in exp-edit" data-campo="conta_corrente" value="<?= h((string)$l['conta_corrente']) ?>" placeholder="<?= h($m['cols']['E']['s'] ?? 'nome exato no Omie') ?>" maxlength="40" title="vazio = padrão da pessoa/empresa"></td>
  <td class="cf-num"><?= cf_brl($l['valor']) ?></td>
  <td><?= cf_data_br($l['data_prevista']) ?></td>
  <td><input type="text" class="cf-in exp-edit" data-campo="nota_fiscal" value="<?= h((string)$l['nota_fiscal']) ?>" maxlength="20" style="width:130px"></td>
  <td class="cf-pix-cel"><input type="text" class="cf-in cf-pix exp-edit" data-campo="chave_pix" value="<?= h((string)$l['chave_pix']) ?>" autocomplete="off"></td>
  <td class="cf-alertas"><?php foreach ($m['erros'] as $e): ?><div class="cf-flag cf-grave"><?= h($e) ?></div><?php endforeach; foreach ($m['avisos'] as $a): ?><div class="cf-flag cf-leve"><?= h($a) ?></div><?php endforeach; ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php else: ?>
<div class="cf-tbl-wrap"><table class="grid cf-tbl" id="tbl-exp">
<thead><tr><th><input type="checkbox" id="exp-todos" checked></th><th>Capa</th><th>Código</th><th>Cliente (como está no Omie)</th><th>Categoria</th><th>Conta corrente</th><th>Valor</th><th>Parcela</th><th>Vencimento</th><th>Nota Fiscal</th><th>Problemas</th></tr></thead>
<tbody>
<?php foreach ($linhas as $l): $m = $montadas[(int)$l['id']]; $ok = !$m['erros']; ?>
<tr class="cf-row <?= $ok ? '' : 'cf-tem-grave' ?>" data-id="<?= (int)$l['id'] ?>" data-valor="<?= (float)$l['valor'] ?>">
  <td><input type="checkbox" class="exp-sel" <?= $ok ? 'checked' : 'disabled' ?>></td>
  <td><a href="/capa-financeira/revisar.php?id=<?= (int)$l['capa_id'] ?>"><?= h((string)$l['capa_cod']) ?></a><br><small class="cf-raw"><?= h(mb_substr((string)$l['capa_cliente'], 0, 40)) ?></small></td>
  <td><b><?= h((string)$l['codigo_integracao']) ?></b><br><small class="cf-raw">L<?= (int)$l['linha_xlsx'] ?> · <?= h((string)$l['cf_raw']) ?></small></td>
  <td><input type="text" class="cf-in exp-edit" data-campo="cliente_omie" value="<?= h((string)$l['cliente_omie']) ?>" placeholder="<?= h($m['cols']['C']['s'] ?? '') ?>" maxlength="60" title="vazio = primeiro comprador da capa"></td>
  <td><?= h((string)$l['categoria']) ?></td>
  <td><input type="text" class="cf-in exp-edit" data-campo="conta_corrente" value="<?= h((string)$l['conta_corrente']) ?>" placeholder="<?= h($m['cols']['E']['s'] ?? 'nome exato no Omie') ?>" maxlength="40"></td>
  <td class="cf-num"><?= cf_brl($l['valor']) ?></td>
  <td><?= $l['parcela'] ? (int)$l['parcela'] . '/' . (int)$l['total_parcelas'] : '—' ?></td>
  <td><?= cf_data_br($l['data_prevista']) ?></td>
  <td><input type="text" class="cf-in exp-edit" data-campo="nota_fiscal" value="<?= h((string)$l['nota_fiscal']) ?>" maxlength="20" style="width:130px"></td>
  <td class="cf-alertas"><?php foreach ($m['erros'] as $e): ?><div class="cf-flag cf-grave"><?= h($e) ?></div><?php endforeach; foreach ($m['avisos'] as $a): ?><div class="cf-flag cf-leve"><?= h($a) ?></div><?php endforeach; ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<p class="cf-dica">Conta corrente vazia usa a conta da pessoa (Pessoas) ou a padrão da empresa (Configurações). Ao preencher uma conta você pode aplicá-la em todas as linhas sem conta. Linhas com problema (vermelho) não entram até serem corrigidas.</p>
<?php endif; ?>

<?php if ($alteradas): ?>
<h2 class="cf-h2">Alterações depois da exportação <small>ajustar manualmente no Omie</small></h2>
<div class="cf-tbl-wrap"><table class="grid cf-tbl">
<thead><tr><th>Código</th><th>Capa</th><th>Favorecido/Cliente</th><th>Valor</th><th>Vencimento</th><th>NF</th><th>Exportado em</th><th></th></tr></thead>
<tbody><?php foreach ($alteradas as $a): ?>
<tr data-id="<?= (int)$a['id'] ?>"><td><b><?= h((string)$a['codigo_integracao']) ?></b></td><td><a href="/capa-financeira/revisar.php?id=<?= (int)$a['capa_id'] ?>"><?= h((string)$a['capa_cod']) ?></a> <?= h(mb_substr((string)$a['capa_cliente'], 0, 30)) ?></td>
<td><?= h((string)$a['cf_raw']) ?></td><td class="cf-num"><?= cf_brl($a['valor']) ?></td><td><?= cf_data_br($a['data_prevista']) ?></td><td><?= h((string)$a['nota_fiscal']) ?></td><td><small><?= h((string)$a['arquivo_nome']) ?></small></td>
<td><button type="button" class="cf-x exp-ajustado" title="já ajustei este título no Omie">✔ ajustado</button></td></tr>
<?php endforeach; ?></tbody></table></div>
<p class="cf-dica">Na importação por planilha o Omie identifica o título por Categoria + Nota Fiscal + Fornecedor + Valor + Parcela + Vencimento — reimportar uma linha alterada criaria um título novo. Por isso estas ficam para ajuste manual (busque pelo Nº Documento = código de integração). Com a API isso passa a ser automático.</p>
<?php endif; ?>

<h2 class="cf-h2">Exportações anteriores <small><?= h($emp['nome']) ?></small></h2>
<?php if (!$historico): ?><p class="home-sub">Nenhuma ainda.</p><?php else: ?>
<div class="cf-tbl-wrap"><table class="grid cf-tbl">
<thead><tr><th>#</th><th>Quando</th><th>Tipo</th><th>Linhas</th><th>Total</th><th>Arquivo</th><th>Avisos</th><th>Status</th><th></th></tr></thead>
<tbody><?php foreach ($historico as $e): $av = json_decode((string)$e['avisos'], true) ?: []; ?>
<tr data-id="<?= (int)$e['id'] ?>"><td><?= (int)$e['id'] ?></td><td><?= h(substr((string)$e['gerado_em'], 0, 16)) ?><br><small><?= h((string)$e['por']) ?></small></td><td><?= $e['tipo'] === 'P' ? 'Pagar' : 'Receber' ?></td>
<td><?= (int)$e['n_linhas'] ?></td><td class="cf-num"><?= cf_brl($e['total']) ?></td><td><a href="?baixar=<?= (int)$e['id'] ?>">⬇ <?= h($e['arquivo_nome']) ?></a></td>
<td><?php if ($av): ?><details><summary><?= count($av) ?> aviso(s)</summary><ul class="cf-flags"><?php foreach ($av as $x): ?><li><?= h($x) ?></li><?php endforeach; ?></ul></details><?php endif; ?></td>
<td><span class="cf-status cf-st-<?= $e['status'] === 'gerada' ? 'confirmada' : 'descartada' ?>"><?= h($e['status']) ?></span></td>
<td><?php if ($e['status'] === 'gerada'): ?><button type="button" class="cf-x exp-desfazer" title="a importação no Omie falhou: devolve as linhas para 'confirmado' e gera de novo">↩ desfazer</button><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; ?>

<script>
(function(){
  const csrf = document.querySelector('meta[name=csrf]').content, empresa = <?= json_encode($empresa) ?>, tipo = <?= json_encode($tipo) ?>;
  async function post(body){ body.csrf = csrf; body.empresa = empresa; body.tipo = tipo;
    const r = await fetch('/capa-financeira/exportar.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)});
    let j = null; try { j = await r.json(); } catch(e) {}
    if (!r.ok || !j || !j.ok) { alert(j && j.erro ? j.erro : ('erro ' + r.status)); return null; } return j; }
  function toast(msg){ const t = document.createElement('div'); t.className = 'cf-toast'; t.textContent = msg; document.body.appendChild(t); setTimeout(() => t.remove(), 4000); }
  const brl = v => 'R$ ' + v.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  function soma(){ let n = 0, t = 0; document.querySelectorAll('.exp-sel:checked').forEach(c => { n++; t += parseFloat(c.closest('tr').dataset.valor) || 0; });
    const en = document.getElementById('exp-n'); if (en) { en.textContent = n; document.getElementById('exp-total').textContent = brl(t); document.getElementById('btn-gerar').disabled = n === 0; } }
  soma();
  document.querySelectorAll('.exp-sel').forEach(c => c.addEventListener('change', soma));
  const todos = document.getElementById('exp-todos');
  if (todos) todos.addEventListener('change', () => { document.querySelectorAll('.exp-sel:not(:disabled)').forEach(c => c.checked = todos.checked); soma(); });
  window.cfPix && window.cfPix.ligarTodos && window.cfPix.ligarTodos();
  document.querySelectorAll('.exp-edit').forEach(el => el.addEventListener('change', async () => {
    const tr = el.closest('tr'); const campo = el.dataset.campo; let valor = el.value;
    if (campo === 'chave_pix') { if (el.value && el.dataset.pixValido !== '1') { toast('Chave Pix inválida — não salvei'); return; } valor = el.dataset.pixValor || ''; }
    const body = {acao:'editar', id: +tr.dataset.id, campo, valor};
    if (campo === 'conta_corrente' && valor) {
      const vazias = [...document.querySelectorAll('.exp-edit[data-campo=conta_corrente]')].filter(o => o !== el && !o.value).length;
      if (vazias && confirm('Aplicar a conta "' + valor + '" também nas outras ' + vazias + ' linha(s) sem conta?')) body.aplicar_todas = 1;
    }
    const j = await post(body); if (!j) return;
    toast('Salvo — recarregando para reavaliar'); setTimeout(() => location.reload(), 600);
  }));
  const bg = document.getElementById('btn-gerar');
  if (bg) bg.addEventListener('click', async () => {
    const ids = [...document.querySelectorAll('.exp-sel:checked')].map(c => +c.closest('tr').dataset.id);
    if (!ids.length) return;
    if (!confirm('Gerar a planilha do Omie com ' + ids.length + ' linha(s)? Elas passam a "exportado" (dá pra desfazer se a importação falhar).')) return;
    bg.disabled = true;
    const j = await post({acao:'gerar', ids, data_registro: document.getElementById('exp-reg').value, emissao: document.getElementById('exp-emi').value});
    if (!j) { bg.disabled = false; return; }
    if (j.avisos && j.avisos.length) alert('Planilha gerada (' + j.n + ' linhas). Avisos:\n\n- ' + j.avisos.join('\n- '));
    location.href = '/capa-financeira/exportar.php?baixar=' + j.exportacao_id;
    setTimeout(() => location.href = '/capa-financeira/exportar.php?empresa=' + empresa + '&tipo=' + tipo, 1500);
  });
  document.querySelectorAll('.exp-desfazer').forEach(b => b.addEventListener('click', async () => {
    const id = +b.closest('tr').dataset.id;
    if (!confirm('Desfazer a exportação #' + id + '? As linhas voltam para "confirmado" e aparecem de novo para exportar. Só faça isso se a planilha NÃO foi importada no Omie.')) return;
    const j = await post({acao:'desfazer', id}); if (j) location.reload();
  }));
  document.querySelectorAll('.exp-ajustado').forEach(b => b.addEventListener('click', async () => {
    const tr = b.closest('tr'); const j = await post({acao:'ajustado', id: +tr.dataset.id}); if (j) tr.remove();
  }));
})();
</script>
</div>
<?php portal_footer();
