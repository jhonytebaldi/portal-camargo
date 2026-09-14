<?php
/* =====================================================================
   capa-financeira/revisar.php — revisão de uma capa: alertas, pessoas,
   datas, natureza, nota fiscal, duplicatas/alterações; confirmação.
   As edições são salvas na hora via acao.php (fetch).
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';

$u = require_tool('capa-financeira');
$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT c.*, u.nome AS enviado_nome FROM cf_capas c LEFT JOIN users u ON u.id = c.enviado_por WHERE c.id = ?');
$st->execute([$id]);
$capa = $st->fetch();
if (!$capa) { http_response_code(404); exit('Capa não encontrada.'); }

$st = $pdo->prepare('SELECT * FROM cf_lancamentos WHERE capa_id = ? ORDER BY linha_xlsx');
$st->execute([$id]);
$linhas = $st->fetchAll();
$pessoas = cf_pessoas();
$categorias = cf_config('categorias', []);
$empresas = array_column(cf_config('empresas', []), null, 'id');
$capaFlags = json_decode((string)$capa['capa_flags'], true) ?: [];
$anterior = null;
if ($capa['capa_anterior_id']) { $q = $pdo->prepare('SELECT id, versao, status, criado_em FROM cf_capas WHERE id = ?'); $q->execute([$capa['capa_anterior_id']]); $anterior = $q->fetch() ?: null; }
$antById = [];
if ($anterior) { $q = $pdo->prepare('SELECT * FROM cf_lancamentos WHERE capa_id = ?'); $q->execute([$anterior['id']]); foreach ($q->fetchAll() as $a) $antById[(int)$a['id']] = $a; }
$editavel = $capa['status'] === 'revisao';
$funcoes = ['CORRETOR', 'CAPTADOR', 'COORDENADOR', 'INTEGRACAO', 'DIRETOR', 'PRE VENDA', 'FINANCEIRO', 'SAC'];

// pendências
$pend = [];
foreach ($linhas as $l) {
    if ($l['status'] !== 'revisao') continue;
    $fl = json_decode((string)$l['flags'], true) ?: [];
    $graves = array_filter($fl, fn($f) => str_starts_with($f, 'GRAVE:'));
    if ($l['tipo'] === 'P' && !$l['pessoa_id']) $pend[] = "Linha {$l['linha_xlsx']}: favorecido não identificado";
    if (!$l['data_prevista']) $pend[] = "Linha {$l['linha_xlsx']}: sem data prevista";
    if ($graves && !(int)$l['revisado']) $pend[] = "Linha {$l['linha_xlsx']}: alerta grave sem revisão";
}

portal_header('Revisar capa', $u);
?>
<meta name="csrf" content="<?= h(csrf_token()) ?>">
<style>main.wrap{max-width:1560px}</style>
<div class="cf-top">
  <div>
    <h1 class="home-titulo">Capa #<?= (int)$capa['id'] ?> — <?= h($capa['cliente']) ?> <?= (int)$capa['versao'] > 1 ? '<span class="cf-tag">versão ' . (int)$capa['versao'] . '</span>' : '' ?></h1>
    <p class="home-sub"><?= h($capa['construtora']) ?> · <?= h($capa['unidade']) ?> · <?= h($capa['bairro']) ?> · COD <b><?= h((string)$capa['cod']) ?: '—' ?></b> · venda <b><?= cf_data_br($capa['data_venda']) ?></b> · empresa <b><?= h($empresas[$capa['empresa']]['nome'] ?? strtoupper($capa['empresa'])) ?></b></p>
    <p class="cf-dica">Arquivo: <?= h($capa['arquivo_nome']) ?> · enviado por <?= h((string)$capa['enviado_nome']) ?> em <?= h(substr((string)$capa['criado_em'], 0, 16)) ?> · status <span class="cf-status cf-st-<?= h($capa['status']) ?>"><?= h($capa['status']) ?></span>
      <?php if ($anterior): ?> · substitui a <a href="/capa-financeira/revisar.php?id=<?= (int)$anterior['id'] ?>">capa #<?= (int)$anterior['id'] ?> (v<?= (int)$anterior['versao'] ?>)</a><?php endif; ?></p>
  </div>
  <nav class="cf-nav"><a href="/capa-financeira/">← Capas</a></nav>
</div>

<div class="cf-resumo">
  <div class="cf-kpi"><span>A pagar</span><b><?= cf_brl($capa['total_pagar']) ?></b></div>
  <div class="cf-kpi"><span>A receber</span><b><?= cf_brl($capa['total_receber']) ?></b></div>
  <div class="cf-kpi"><span>Bônus (capa)</span><b><?= cf_brl($capa['valor_bonus']) ?></b></div>
  <div class="cf-kpi"><span>VGV</span><b><?= cf_brl($capa['vgv']) ?></b></div>
</div>

<?php if ($capaFlags): ?>
<div class="aviso"><b>Avisos da capa:</b><ul class="cf-flags"><?php foreach ($capaFlags as $f): ?><li><?= h(cf_flag_texto($f)) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($editavel && ($capa['cod'] === null || $capa['cod'] === '')): ?>
<div class="aviso"><b>Capa sem COD do imóvel.</b> Informe o código do imóvel no Robust para continuar:
  <input id="cf-cod" class="cf-in" placeholder="ex.: 1375" style="width:120px"> <button class="btn" id="btn-cod" type="button">Salvar COD</button></div>
<?php endif; ?>
<?php if ($capa['obs_capa']): ?><div class="aviso">Observação na capa (C8): <b><?= h($capa['obs_capa']) ?></b></div><?php endif; ?>

<?php if ($editavel): ?>
<div class="cf-barra">
  <div id="cf-pend" class="<?= $pend ? 'cf-pend' : 'cf-pend ok' ?>">
    <?php if ($pend): ?><b><?= count($pend) ?> pendência(s) antes de confirmar:</b><ul><?php foreach (array_slice($pend, 0, 12) as $p): ?><li><?= h($p) ?></li><?php endforeach; ?><?= count($pend) > 12 ? '<li>…</li>' : '' ?></ul>
    <?php else: ?>Tudo revisado. Pode confirmar.<?php endif; ?>
  </div>
  <div class="cf-acoes">
    <button class="btn" id="btn-confirmar" <?= $pend ? 'disabled' : '' ?>>Confirmar capa</button>
    <button class="btn cf-btn-sec" id="btn-descartar">Descartar</button>
  </div>
</div>
<?php endif; ?>

<?php
$legenda = ['IGUAL' => 'igual à versão anterior', 'ALTERADA' => 'alterada em relação à versão anterior', 'NOVA' => 'nova nesta versão', 'POSSIVEL_DUPLICATA' => 'possível duplicata de outra capa'];
function cf_linha_diff(array $l, array $antById): string {
    if (!$l['dup_tipo']) return '';
    $cls = ['IGUAL' => 'cf-d-igual', 'ALTERADA' => 'cf-d-alt', 'NOVA' => 'cf-d-nova', 'POSSIVEL_DUPLICATA' => 'cf-d-dup'][$l['dup_tipo']] ?? '';
    $tit = '';
    if ($l['dup_tipo'] === 'ALTERADA' && isset($antById[(int)$l['dup_de']])) {
        $a = $antById[(int)$l['dup_de']];
        $tit = 'antes: ' . cf_data_br($a['data_prevista']) . ' ' . cf_brl($a['valor']) . ' NF=' . ($a['nota_fiscal'] ?: '—') . ' ' . ($a['natureza'] ?: '');
    }
    return '<span class="cf-tag ' . $cls . '" title="' . h($tit) . '">' . h($l['dup_tipo']) . '</span>';
}
function cf_render_flags(array $fl): string {
    $out = '';
    foreach ($fl as $f) {
        if ($f === 'PESSOA_NAO_IDENTIFICADA') continue;
        $out .= '<div class="cf-flag cf-' . cf_nivel_flag($f) . '">' . h(cf_flag_texto($f)) . '</div>';
    }
    return $out;
}
$pagar = array_filter($linhas, fn($l) => $l['tipo'] === 'P');
$receber = array_filter($linhas, fn($l) => $l['tipo'] === 'R');
?>

<h2 class="cf-h2">Contas a pagar <small><?= count($pagar) ?> linha(s)</small></h2>
<div class="cf-tbl-wrap">
<table class="grid cf-tbl" id="tbl-pagar">
<thead><tr><th>L</th><th>Data prevista</th><th>Favorecido (coluna C)</th><th>Pessoa (dicionário)</th><th>Função</th><th>Natureza</th><th>Categoria Omie</th><th>Valor</th><th>Nota Fiscal</th><th>Condição</th><th>Alertas</th><th>Ok</th><th></th></tr></thead>
<tbody>
<?php foreach ($pagar as $l): $fl = json_decode((string)$l['flags'], true) ?: []; $rem = $l['status'] === 'removido';
  $graves = (bool)array_filter($fl, fn($f) => str_starts_with($f, 'GRAVE:')); ?>
<tr class="cf-row <?= $rem ? 'cf-removida' : '' ?> <?= $graves && !(int)$l['revisado'] ? 'cf-tem-grave' : '' ?>" data-id="<?= (int)$l['id'] ?>">
  <td><?= (int)$l['linha_xlsx'] ?><br><?= cf_linha_diff($l, $antById) ?></td>
  <td>
    <?php if ($editavel && !$rem): ?><input type="date" class="cf-in" data-campo="data_prevista" value="<?= h((string)$l['data_prevista']) ?>">
    <?php else: ?><?= cf_data_br($l['data_prevista']) ?><?php endif; ?>
    <?php if ($l['data_raw'] && $l['data_raw'] !== $l['data_prevista']): ?><br><small class="cf-raw">na capa: <?= h($l['data_raw']) ?></small><?php endif; ?>
  </td>
  <td><b><?= h($l['cf_raw']) ?></b><?php if ($l['favorecido_hist'] && CapaParser::key($l['favorecido_hist']) !== CapaParser::key($l['cf_raw'])): ?><br><small class="cf-raw">histórico: <?= h($l['favorecido_hist']) ?></small><?php endif; ?></td>
  <td>
    <?php if ($editavel && !$rem): ?>
      <select class="cf-in cf-pessoa <?= $l['pessoa_id'] ? '' : 'cf-vazio' ?>" data-campo="pessoa_id">
        <option value="">— não identificado —</option>
        <?php $sug = $l['pessoa_id'] ? [] : cf_sugerir_pessoas($l['cf_raw'], $pessoas);
        if ($sug): ?><optgroup label="Parecidos (confira!)"><?php foreach ($sug as $pid): ?><option value="<?= $pid ?>">≈ <?= h($pessoas[$pid]['nome']) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
        <optgroup label="Todas"><?php foreach ($pessoas as $pid => $p): ?><option value="<?= $pid ?>" <?= (int)$l['pessoa_id'] === $pid ? 'selected' : '' ?>><?= h($p['nome']) ?></option><?php endforeach; ?></optgroup>
      </select>
      <?php if (!$l['pessoa_id']): ?><label class="cf-mini"><input type="checkbox" class="cf-salvar-alias" checked> salvar "<?= h($l['cf_raw']) ?>" como apelido</label><?php endif; ?>
    <?php else: ?><?= h($pessoas[(int)$l['pessoa_id']]['nome'] ?? '—') ?><?php endif; ?>
  </td>
  <td><?php if ($editavel && !$rem): ?><select class="cf-in" data-campo="funcao"><?php foreach (array_unique(array_merge($funcoes, [$l['funcao'] ?: 'CORRETOR'])) as $f): ?><option <?= $f === $l['funcao'] ? 'selected' : '' ?>><?= h($f) ?></option><?php endforeach; ?></select><?php else: ?><?= h((string)$l['funcao']) ?><?php endif; ?></td>
  <td><?php if ($editavel && !$rem): ?><select class="cf-in" data-campo="natureza"><option <?= $l['natureza'] === 'COMISSAO' ? 'selected' : '' ?>>COMISSAO</option><option <?= $l['natureza'] === 'BONUS' ? 'selected' : '' ?>>BONUS</option></select><?php else: ?><?= h((string)$l['natureza']) ?><?php endif; ?></td>
  <td class="cf-cat"><?= h((string)$l['categoria']) ?: '<span class="cf-tag cf-grave">sem categoria</span>' ?></td>
  <td class="cf-num"><?= cf_brl($l['valor']) ?></td>
  <td><?php if ($editavel && !$rem): ?><input type="text" class="cf-in cf-nf" maxlength="20" data-campo="nota_fiscal" value="<?= h((string)$l['nota_fiscal']) ?>"><?php else: ?><?= h((string)$l['nota_fiscal']) ?><?php endif; ?></td>
  <td><small><?= h((string)$l['condicao']) ?><?= $l['prefixo'] && $l['col_g'] && CapaParser::key($l['prefixo']) !== CapaParser::key($l['col_g']) ? '<br>G: ' . h($l['col_g']) . '<br>prefixo: ' . h($l['prefixo']) : '' ?></small></td>
  <td class="cf-alertas"><?= cf_render_flags($fl) ?></td>
  <td><?php if ($graves): ?><input type="checkbox" class="cf-in" data-campo="revisado" <?= (int)$l['revisado'] ? 'checked' : '' ?> <?= $editavel && !$rem ? '' : 'disabled' ?> title="alerta grave conferido"><?php endif; ?></td>
  <td><?php if ($editavel): ?><button class="cf-x" data-acao="<?= $rem ? 'restaurar' : 'remover' ?>" title="<?= $rem ? 'restaurar' : 'remover esta linha' ?>"><?= $rem ? '↺' : '✕' ?></button><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>

<h2 class="cf-h2">Contas a receber <small><?= count($receber) ?> linha(s)</small></h2>
<div class="cf-tbl-wrap">
<table class="grid cf-tbl" id="tbl-receber">
<thead><tr><th>L</th><th>Data prevista</th><th>Cliente (coluna C)</th><th>Parcela</th><th>Categoria Omie</th><th>Valor</th><th>Fluxo</th><th>Alertas</th><th>Ok</th><th></th></tr></thead>
<tbody>
<?php foreach ($receber as $l): $fl = json_decode((string)$l['flags'], true) ?: []; $rem = $l['status'] === 'removido';
  $graves = (bool)array_filter($fl, fn($f) => str_starts_with($f, 'GRAVE:')); ?>
<tr class="cf-row <?= $rem ? 'cf-removida' : '' ?> <?= $graves && !(int)$l['revisado'] ? 'cf-tem-grave' : '' ?>" data-id="<?= (int)$l['id'] ?>">
  <td><?= (int)$l['linha_xlsx'] ?><br><?= cf_linha_diff($l, $antById) ?></td>
  <td><?php if ($editavel && !$rem): ?><input type="date" class="cf-in" data-campo="data_prevista" value="<?= h((string)$l['data_prevista']) ?>"><?php else: ?><?= cf_data_br($l['data_prevista']) ?><?php endif; ?>
      <?php if ($l['data_raw'] && $l['data_raw'] !== $l['data_prevista']): ?><br><small class="cf-raw">na capa: <?= h($l['data_raw']) ?></small><?php endif; ?></td>
  <td><b><?= h($l['cf_raw']) ?></b></td>
  <td><?= $l['parcela'] ? (int)$l['parcela'] . '/' . (int)$l['total_parcelas'] : '—' ?></td>
  <td><?php if ($editavel && !$rem): ?><select class="cf-in" data-campo="categoria">
        <?php foreach (array_unique([$categorias['RECEBER_NOVO'] ?? '', $categorias['RECEBER_USADO'] ?? '', $categorias['RECEBER_BONUS'] ?? '']) as $c): if ($c === '') continue; ?>
          <option <?= $c === $l['categoria'] ? 'selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?></select>
        <?php if ($l['status_imovel'] === 'PRONTO'): ?><br><small class="cf-raw">imóvel PRONTO: confirme se é usado</small><?php endif; ?>
      <?php else: ?><?= h((string)$l['categoria']) ?><?php endif; ?></td>
  <td class="cf-num"><?= cf_brl($l['valor']) ?></td>
  <td><small><?= h((string)$l['rotulo_fluxo']) ?></small></td>
  <td class="cf-alertas"><?= cf_render_flags($fl) ?></td>
  <td><?php if ($graves): ?><input type="checkbox" class="cf-in" data-campo="revisado" <?= (int)$l['revisado'] ? 'checked' : '' ?> <?= $editavel && !$rem ? '' : 'disabled' ?>><?php endif; ?></td>
  <td><?php if ($editavel): ?><button class="cf-x" data-acao="<?= $rem ? 'restaurar' : 'remover' ?>"><?= $rem ? '↺' : '✕' ?></button><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>

<p class="cf-dica">Legenda: <?php foreach ($legenda as $k => $v): ?><span class="cf-tag"><?= $k ?></span> <?= $v ?> &nbsp; <?php endforeach; ?></p>

<script>
(function(){
  const csrf = document.querySelector('meta[name=csrf]').content;
  const capaId = <?= (int)$capa['id'] ?>;
  async function acao(body){
    body.csrf = csrf; body.capa_id = capaId;
    const r = await fetch('/capa-financeira/acao.php', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF':csrf}, body: JSON.stringify(body)});
    let j = null; try { j = await r.json(); } catch(e) {}
    if (!r.ok || !j || !j.ok) { alert('Não salvou: ' + (j && j.erro ? j.erro : r.status)); return null; }
    return j;
  }
  function atualizaPend(j){
    const box = document.getElementById('cf-pend'); const btn = document.getElementById('btn-confirmar');
    if (!box || !j || !j.pendencias) return;
    if (j.pendencias.length) { box.className = 'cf-pend'; box.innerHTML = '<b>' + j.pendencias.length + ' pendência(s) antes de confirmar:</b><ul>' + j.pendencias.slice(0,12).map(p => '<li>' + p + '</li>').join('') + '</ul>'; btn.disabled = true; }
    else { box.className = 'cf-pend ok'; box.textContent = 'Tudo revisado. Pode confirmar.'; btn.disabled = false; }
  }
  document.querySelectorAll('.cf-in').forEach(el => {
    el.addEventListener('change', async () => {
      const tr = el.closest('tr'); const id = +tr.dataset.id; const campo = el.dataset.campo;
      let valor = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
      const body = {acao:'editar', id, campo, valor};
      if (campo === 'pessoa_id') { const cb = tr.querySelector('.cf-salvar-alias'); body.salvar_alias = cb && cb.checked ? 1 : 0; }
      const j = await acao(body);
      if (!j) return;
      if (j.categoria !== undefined) tr.querySelector('.cf-cat').textContent = j.categoria || '';
      if (j.nota_fiscal !== undefined) { const nf = tr.querySelector('.cf-nf'); if (nf) nf.value = j.nota_fiscal || ''; }
      if (campo === 'pessoa_id') { el.classList.toggle('cf-vazio', !el.value); const cb = tr.querySelector('.cf-salvar-alias'); if (cb && el.value) cb.parentElement.remove(); }
      if (campo === 'revisado' || campo === 'pessoa_id' || campo === 'data_prevista') tr.classList.toggle('cf-tem-grave', !!j.tem_grave_pendente);
      atualizaPend(j);
    });
  });
  document.querySelectorAll('.cf-x').forEach(b => b.addEventListener('click', async () => {
    const tr = b.closest('tr'); const j = await acao({acao: b.dataset.acao, id: +tr.dataset.id});
    if (j) location.reload();
  }));
  const bc = document.getElementById('btn-confirmar');
  if (bc) bc.addEventListener('click', async () => {
    if (!confirm('Confirmar esta capa? Os lançamentos passam a valer para exportação e recibos.')) return;
    const j = await acao({acao:'confirmar'}); if (j) location.reload();
  });
  const bcod = document.getElementById('btn-cod');
  if (bcod) bcod.addEventListener('click', async () => { const j = await acao({acao:'editar_capa', campo:'cod', valor: document.getElementById('cf-cod').value}); if (j) location.reload(); });
  const bd = document.getElementById('btn-descartar');
  if (bd) bd.addEventListener('click', async () => {
    if (!confirm('Descartar esta capa? Nada dela será exportado.')) return;
    const j = await acao({acao:'descartar'}); if (j) location.href = '/capa-financeira/';
  });
})();
</script>
<?php portal_footer();
