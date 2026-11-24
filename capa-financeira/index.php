<?php
/* =====================================================================
   capa-financeira/index.php — lista das capas enviadas + envio de nova capa.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';

$u = require_tool('capa-financeira');
$pdo = db();
$empresas = cf_config('empresas', []);
$filtro = (string)($_GET['status'] ?? '');

$sql = "SELECT c.*, u.nome AS enviado_nome,
          (SELECT COUNT(*) FROM cf_lancamentos l WHERE l.capa_id = c.id AND l.status <> 'removido') AS n_linhas,
          (SELECT COUNT(*) FROM cf_lancamentos l WHERE l.capa_id = c.id AND l.status = 'revisao'
              AND ((l.revisado = 0 AND l.flags <> '[]' AND l.flags NOT IN ('[\"PESSOA_NAO_IDENTIFICADA\"]', '[\"REPASSE (favorecido = terceiro; categoria de repasse)\"]')) OR (l.tipo = 'P' AND l.pessoa_id IS NULL))) AS n_pendencias
        FROM cf_capas c LEFT JOIN users u ON u.id = c.enviado_por";
$args = [];
if (in_array($filtro, ['revisao', 'confirmada', 'substituida', 'descartada'], true)) { $sql .= ' WHERE c.status = ?'; $args[] = $filtro; }
$sql .= ' ORDER BY c.id DESC LIMIT 200';
$st = $pdo->prepare($sql); $st->execute($args);
$capas = $st->fetchAll();
$nPessoas = (int)$pdo->query('SELECT COUNT(*) FROM cf_pessoas WHERE ativo = 1')->fetchColumn();
$nExportar = (int)$pdo->query("SELECT COUNT(*) FROM cf_lancamentos l JOIN cf_capas c ON c.id = l.capa_id WHERE l.status = 'confirmado' AND c.status = 'confirmada'")->fetchColumn();

portal_header('Capa Financeira', $u);
?>
<style>main.wrap{max-width:1500px}</style>
<?php cf_cabecalho('Capa Financeira → Omie', 'Envie a capa da venda, revise os lançamentos, confirme. Depois gere as planilhas do Omie e os recibos.', [['Capa Financeira', null]], 'capas'); ?>
<?php if (($_GET['ok'] ?? '') === 'confirmada'): ?><div class="cf-sucesso" data-cf-modal="ok" data-cf-titulo="Capa confirmada">✔ Capa #<?= (int)($_GET['id'] ?? 0) ?> confirmada. Os lançamentos já aparecem em <a href="/capa-financeira/exportar.php">Exportar para o Omie</a><?= $nExportar ? ' (' . $nExportar . ' pendentes)' : '' ?> e em <a href="/capa-financeira/recibos.php">Recibos</a>.</div><?php endif; ?>

<?php if ($nPessoas === 0): ?>
<div class="aviso">O dicionário de pessoas está vazio. Cadastre a equipe em <a href="/capa-financeira/pessoas.php">Pessoas</a> (dá pra importar a planilha DADOS CORRETORES EQUIPE) — sem isso todo favorecido vai aparecer como "não identificado".</div>
<?php endif; ?>

<section class="card cf-upload">
  <h2>Enviar nova capa</h2>
  <form method="post" action="/capa-financeira/upload.php" enctype="multipart/form-data" class="cf-form">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <label>Empresa da importação
      <select name="empresa" required>
        <option value="">— escolha —</option>
        <?php foreach ($empresas as $e): ?>
          <option value="<?= h($e['id']) ?>"><?= h($e['nome']) ?> · <?= h($e['cnpj']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Arquivo da capa (.xlsx)
      <input type="file" name="capas[]" accept=".xlsx" multiple required>
    </label>
    <button class="btn" type="submit">Enviar e revisar</button>
  </form>
  <p class="cf-dica">Reenviar a capa de uma venda já enviada (mesmo COD) cria uma nova versão e mostra o que mudou linha a linha.</p>
  <p class="cf-dica">Dá para selecionar <b>várias capas de uma vez</b> (até 20, da mesma empresa): todas entram em revisão e você resolve os alertas repetidos em <a href="/capa-financeira/lote.php">Resolver em lote</a>. Capa com COD de uma venda do formulário, ou com COD que já é de outro cliente, fica de fora e precisa ser enviada sozinha.</p>
</section>
<section class="card cf-upload" id="drv">
  <h2>Escolher do Google Drive <small id="drv-pasta" class="cf-raw" style="font-weight:400"></small></h2>
  <div id="drv-off" class="cf-dica" style="display:none">O acesso ao Drive ainda não foi configurado. Em <a href="/capa-financeira/configuracoes.php">Configurações › Google Drive</a> um administrador envia a chave da conta de serviço e testa o acesso.</div>
  <div id="drv-on" style="display:none">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:8px">
      <select id="drv-emp" class="cf-in"><option value="">— empresa da importação —</option><?php foreach ($empresas as $e): ?><option value="<?= h($e['id']) ?>"><?= h($e['nome']) ?></option><?php endforeach; ?></select>
      <input type="text" id="drv-busca" class="cf-in" placeholder="filtrar: nome, construtora, COD, bairro, unidade…" style="width:330px">
      <label style="font-size:13px"><input type="checkbox" id="drv-todos"> mostrar também as já importadas, "ok" e modelos</label>
      <button type="button" class="btn" id="drv-importar" disabled>Importar marcadas (<span id="drv-n">0</span>)</button>
      <button type="button" class="cf-x" id="drv-atualizar" title="lê a pasta do Drive de novo agora">↻ atualizar</button>
      <span class="cf-raw" id="drv-quando"></span>
    </div>
    <div id="drv-aviso" class="cf-flag cf-leve" style="display:none;margin-bottom:8px"></div>
    <div id="drv-lista" style="max-height:460px;overflow:auto;border:1px solid var(--line);border-radius:8px;padding:6px 10px"></div>
    <p class="cf-dica">Quando há mais de um arquivo da mesma venda (mesmos compradores no nome, em qualquer pasta), só um fica disponível: o <b>aditivo</b> mais recente, se houver; senão o que já está no portal ou o modificado por último. Os outros aparecem travados com o motivo, apontando para o que vale (dá para liberar). A lista é lida do Drive na hora (e de novo a cada 45 s com esta tela aberta): renomear, mover ou subir arquivo na pasta aparece aqui sem fazer mais nada. As marcadas entram em revisão — nada é confirmado nem enviado ao Omie. Marcando uma só, ela pode virar nova versão de uma capa do mesmo cliente; em lote, COD que já existe fica de fora com o motivo. Quando a capa vai toda para o Omie, o arquivo ganha "ok - " no nome sozinho.</p>
  </div>
  <div id="drv-carregando" class="cf-raw">lendo a pasta do Drive…</div>
</section>
<style>.drv-g{margin:6px 0}.drv-g>summary{cursor:pointer;font-weight:600;padding:4px 0}.drv-a{display:flex;gap:8px;align-items:center;padding:3px 0 3px 18px;font-size:14px}.drv-a:hover{background:rgba(0,0,0,.03)}.drv-a .cf-flag{font-size:11px;padding:1px 7px;margin:0}.drv-a small{color:#778}</style>
<script>
(() => {
  const csrf = <?= json_encode(csrf_token()) ?>, $ = id => document.getElementById(id), esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const SIT = {pendente: ['cf-leve', 'pendente'], revisao: ['cf-ok', 'em revisão'], confirmada: ['cf-ok', 'confirmada'], enviada: ['cf-ok', 'no Omie'], alterada: ['cf-grave', 'mudou no Drive'], provavel: ['cf-ok', 'já enviada à mão?'], ok: ['cf-ok', 'ok no nome'], modelo: ['', 'modelo']};
  let arquivos = [], marcados = new Set(), fechados = new Set(), ocupado = false, primeira = true;
  const liberados = new Set();
  const travado = a => (a.trava || a.nao_subir) && !liberados.has(a.id);
  const TRV = {aditivo: ['substituída pelo aditivo', 'o aditivo substitui esta capa: suba só o aditivo'], copia: ['cópia idêntica', 'é uma cópia exata de outro arquivo desta venda'], mais_recente: ['há versão mais recente', 'existe outro arquivo desta venda modificado depois deste'], copia_importada: ['cópia de arquivo já importado', 'cópia exata de um arquivo desta venda que já está no portal'], ja_importada: ['venda já no portal', 'esta venda já está no portal por outro arquivo; liberar importa este como nova versão']};
  const semAc = t => String(t ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  // texto pesquisável: nome do arquivo + cabeçalho da capa (COD, clientes, construtora, bairro, unidade, data da venda)
  const alvo = a => a._alvo ??= semAc([a.nome, a.subpasta, a.ficha ? ['cod ' + a.ficha.cod, a.ficha.cliente, a.ficha.construtora, a.ficha.bairro, a.ficha.unidade, (a.ficha.venda || '').split('-').reverse().join('/')].join(' ') : ''].join(' '));
  const bate = (a, busca) => !busca || busca.split(/\s+/).every(p => alvo(a).includes(p));
  const escolhivel = a => (a.sit === 'pendente' || a.sit === 'alterada') && !travado(a);
  const pendente = a => a.sit === 'pendente' || a.sit === 'alterada';
  function desenhar() {
    const busca = semAc($('drv-busca').value.trim()), todos = $('drv-todos').checked;
    const vis = arquivos.filter(a => (todos || pendente(a)) && bate(a, busca));
    const grupos = {}; vis.forEach(a => (grupos[a.subpasta || '(raiz da pasta)'] ??= []).push(a));
    const nomes = Object.keys(grupos).sort();
    const rolagem = $('drv-lista').scrollTop;
    $('drv-lista').innerHTML = nomes.length ? nomes.map(g => {
      const its = grupos[g], esc_g = esc(g), nEsc = its.filter(escolhivel).length;
      return '<details class="drv-g" data-g="' + esc_g + '"' + (fechados.has(g) ? '' : ' open') + '><summary>' + esc_g + ' <small class="cf-raw">' + its.length + ' arquivo(s)</small>' + (nEsc ? ' <button type="button" class="cf-x drv-mes" data-g="' + esc_g + '">marcar os ' + nEsc + ' pendentes</button>' : '') + '</summary>' +
        its.map(a => { const s = SIT[a.sit] || ['', a.sit]; const d = a.modificado ? new Date(a.modificado).toLocaleDateString('pt-BR') : '';
          return '<label class="drv-a" title="' + esc(a.txt || '') + '"><input type="checkbox" class="drv-c" value="' + esc(a.id) + '"' + (marcados.has(a.id) ? ' checked' : '') + (escolhivel(a) ? '' : ' disabled') + '> <span>' + esc(a.nome) + '</span> <span class="cf-flag ' + s[0] + '">' + s[1] + '</span>' +
            (a.capa ? ' <a href="/capa-financeira/revisar.php?id=' + a.capa + '">capa #' + a.capa + '</a>' : '') +
            (a.aditivo && a.substitui ? ' <span class="cf-flag cf-ok" title="este aditivo é o arquivo que vale para a venda">aditivo — substitui ' + a.substitui.n + ' arquivo(s)' + (a.substitui.capa ? ' e a capa #' + a.substitui.capa : '') + '</span>' : (a.aditivo ? ' <span class="cf-flag cf-ok">aditivo</span>' : '')) +
            (a.trava ? ' <span class="cf-flag cf-grave" title="' + esc(TRV[a.trava.motivo][1]) + '">' + TRV[a.trava.motivo][0] + '</span> <small>vale: ' + esc(a.trava.nome) + (a.trava.subpasta ? ' (' + esc(a.trava.subpasta) + ')' : '') + (a.trava.capa ? ' · <a href="/capa-financeira/revisar.php?id=' + a.trava.capa + '">capa #' + a.trava.capa + '</a>' : '') + '</small>' : '') +
            (a.nao_subir ? ' <span class="cf-flag cf-grave">o nome diz "não subir"</span>' : '') + (a.distrato && !a.nao_subir ? ' <span class="cf-flag cf-leve">distrato</span>' : '') +
            (a.outros && a.outros.length ? ' <span class="cf-flag cf-leve" title="' + esc(a.outros.join(' · ')) + '">há outro arquivo desta venda</span>' : '') +
            (pendente(a) && (a.trava || a.nao_subir) && !liberados.has(a.id) ? ' <button type="button" class="cf-x drv-lib" data-id="' + esc(a.id) + '" title="libera a caixa de seleção deste arquivo mesmo assim">liberar</button>' : '') +
            ' <small>' + d + (a.ficha ? ' · ' + esc([a.ficha.cod ? 'COD ' + a.ficha.cod : 'sem COD', a.ficha.construtora, a.ficha.bairro].filter(Boolean).join(' · ')) : '') + '</small></label>'; }).join('') + '</details>';
    }).join('') : '<p class="cf-raw">' + (arquivos.length ? 'Nenhum arquivo pendente com esse filtro. Marque "mostrar também as já importadas" para ver todos.' : 'A pasta não tem arquivos .xlsx.') + '</p>';
    $('drv-lista').scrollTop = rolagem;
    contar();
  }
  function contar() { $('drv-n').textContent = marcados.size; $('drv-importar').disabled = !marcados.size || ocupado; }
  async function ler(manual) {
    if (ocupado) return;
    let j = null;
    try { const r = await fetch('/capa-financeira/drive.php?acao=listar', {headers: {'Accept': 'application/json'}}); j = await r.json(); } catch (e) { j = null; }
    $('drv-carregando').style.display = 'none';
    if (!j || !j.ok) {
      const m = j && j.erro ? j.erro : 'Não consegui ler a pasta do Drive agora (sem resposta do portal). Confira sua internet e clique em atualizar.';
      if (primeira || manual) { $('drv-on').style.display = ''; $('drv-aviso').style.display = ''; $('drv-aviso').textContent = m; }
      primeira = false; return;
    }
    primeira = false;
    if (!j.configurado) { $('drv-off').style.display = ''; return; }
    $('drv-on').style.display = ''; $('drv-pasta').textContent = '· ' + j.pasta; $('drv-quando').textContent = 'lido às ' + j.lido_em;
    $('drv-aviso').style.display = j.aviso ? '' : 'none'; $('drv-aviso').textContent = j.aviso || '';
    if (j.indexar_restam > 0) { $('drv-quando').textContent += ' · lendo o conteúdo das capas para o filtro (faltam ' + j.indexar_restam + ')…'; setTimeout(() => ler(false), 1200); }
    arquivos = j.arquivos; const validos = new Set(arquivos.filter(escolhivel).map(a => a.id));
    marcados = new Set([...marcados].filter(i => validos.has(i)));
    desenhar();
  }
  $('drv-lista').addEventListener('change', e => { if (!e.target.classList.contains('drv-c')) return; e.target.checked ? marcados.add(e.target.value) : marcados.delete(e.target.value); contar(); });
  $('drv-lista').addEventListener('click', async e => { const lb = e.target.closest('.drv-lib');
    if (lb) { e.preventDefault(); const a = arquivos.find(x => x.id === lb.dataset.id); if (!a) return;
      if (!await CFModal.pergunta({tipo: 'parcial', titulo: 'Liberar este arquivo?', html: '<p style="margin:0">' + (a.trava ? esc(TRV[a.trava.motivo][1].charAt(0).toUpperCase() + TRV[a.trava.motivo][1].slice(1)) + ' (<b>' + esc(a.trava.nome) + '</b>). O normal é importar só o arquivo que vale.' : 'O nome do arquivo diz para não subir.') + ' Liberar mesmo assim?</p>', ok: 'Liberar'})) return;
      liberados.add(a.id); desenhar(); return; }
    const b = e.target.closest('.drv-mes'); if (!b) return; e.preventDefault();
    const busca = semAc($('drv-busca').value.trim());
    arquivos.filter(a => (a.subpasta || '(raiz da pasta)') === b.dataset.g && escolhivel(a) && bate(a, busca)).forEach(a => marcados.add(a.id)); desenhar(); });
  $('drv-lista').addEventListener('toggle', e => { const d = e.target; if (d.classList && d.classList.contains('drv-g')) d.open ? fechados.delete(d.dataset.g) : fechados.add(d.dataset.g); }, true);
  $('drv-busca').addEventListener('input', desenhar); $('drv-todos').addEventListener('change', desenhar);
  $('drv-atualizar').addEventListener('click', () => { $('drv-quando').textContent = 'lendo…'; ler(true); });
  $('drv-importar').addEventListener('click', async () => {
    const emp = $('drv-emp').value, ids = [...marcados];
    if (!emp) { await CFModal.aviso({tipo: 'info', titulo: 'Falta a empresa', texto: 'Escolha a empresa da importação (Vertical ou Camargo) ao lado do botão.'}); return; }
    if (ids.length > 1 && !await CFModal.pergunta({titulo: 'Importar ' + ids.length + ' capas do Drive?', html: '<p style="margin:0">Todas entram em <b>revisão</b> na empresa escolhida. Nada é confirmado nem enviado ao Omie. As que tiverem COD já usado em outra capa ficam de fora, com o motivo.</p>', ok: 'Sim, importar'})) return;
    ocupado = true; contar();
    const pg = CFModal.progresso({titulo: 'Importando do Drive', atraso: 200}); let fila = ids.slice(), res = [], erro = null, voltas = 0;
    while (fila.length && voltas++ < 60) {
      pg.pct(Math.round(100 * res.length / ids.length), res.length + ' de ' + ids.length + ' arquivo(s)…');
      let j = null;
      try { const r = await fetch('/capa-financeira/drive.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({acao: 'importar', csrf, empresa: emp, ids: fila, unico: ids.length === 1 ? 1 : 0})}); j = await r.json(); } catch (e) { j = null; }
      if (!j || !j.ok) { erro = j && j.erro ? j.erro : 'O portal não respondeu no meio da importação. O que já foi importado está na lista de capas; atualize a página e importe de novo as que faltaram.'; break; }
      res = res.concat(j.resultados); if (!j.resultados.length && j.restantes.length === fila.length) { erro = 'A importação não avançou (o servidor está lento). Tente de novo com menos arquivos.'; break; }
      fila = j.restantes;
    }
    ocupado = false;
    const ROT = {ok: ['cf-ok', 'importada'], sozinha: ['cf-leve', 'ficou de fora'], ja: ['cf-ok', 'já importada'], erro: ['cf-grave', 'erro']}, nOk = res.filter(r => r.st === 'ok').length;
    const html = (erro ? '<p><b>' + esc(erro) + '</b></p>' : '') + '<div style="text-align:left;max-height:50vh;overflow:auto">' + res.map(r => '<div style="margin:6px 0"><span class="cf-flag ' + ROT[r.st][0] + '">' + ROT[r.st][1] + '</span> <b>' + esc(r.nome) + '</b><br><small>' + (r.id ? '<a href="/capa-financeira/revisar.php?id=' + r.id + '">capa #' + r.id + '</a> · ' : '') + esc(r.msg) + '</small></div>').join('') + '</div>' +
      (nOk > 1 ? '<p style="margin-top:10px"><a class="btn" href="/capa-financeira/lote.php">Resolver alertas repetidos em lote</a></p>' : '');
    await pg.resultado({tipo: erro ? 'erro' : (nOk === res.length ? 'ok' : 'parcial'), titulo: nOk + ' de ' + ids.length + ' capa(s) importadas', html});
    if (nOk === 1 && ids.length === 1) { location.href = '/capa-financeira/revisar.php?id=' + res.find(r => r.st === 'ok').id; return; }
    location.reload();
  });
  ler(false);
  setInterval(() => { if (!document.hidden && $('drv-on').style.display !== 'none') ler(false); }, 45000);
})();
</script>
<p style="margin:10px 0"><a class="btn cf-btn-sec" href="/capa-financeira/lote.php" title="resolve de uma vez os alertas que se repetem nas capas em revisão: data da venda com ano arrastado, grafia parecida e condições fora do dicionário">⚡ Resolver alertas repetidos em lote</a></p>

<div class="admin-tabs">
  <?php foreach (['' => 'Todas', 'revisao' => 'Em revisão', 'confirmada' => 'Confirmadas', 'substituida' => 'Substituídas', 'descartada' => 'Descartadas'] as $k => $n): ?>
    <a class="<?= $k === $filtro ? 'on' : '' ?>" href="/capa-financeira/?status=<?= $k ?>"><?= $n ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$capas): ?>
  <p class="home-sub">Nenhuma capa por aqui ainda.</p>
<?php else: ?>
<div class="cf-tbl-wrap">
<table class="grid cf-lista">
  <thead><tr><th>#</th><th>COD</th><th>Cliente</th><th>Construtora</th><th>Venda</th><th>Empresa</th><th>Linhas</th><th>A pagar</th><th>A receber</th><th>Status</th><th>Enviada</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($capas as $c): $fl = json_decode((string)$c['capa_flags'], true) ?: []; ?>
    <tr>
      <td><a href="/capa-financeira/revisar.php?id=<?= (int)$c['id'] ?>"><?= (int)$c['id'] ?></a><?= (int)$c['versao'] > 1 ? ' <span class="cf-tag">v' . (int)$c['versao'] . '</span>' : '' ?></td>
      <td><?= h((string)$c['cod']) ?: '<span class="cf-tag cf-grave">sem COD</span>' ?></td>
      <td><a href="/capa-financeira/revisar.php?id=<?= (int)$c['id'] ?>"><?= h($c['cliente']) ?></a></td>
      <td><?= h($c['construtora']) ?></td>
      <td><?= cf_data_br($c['data_venda']) ?></td>
      <td><?= h(strtoupper($c['empresa'])) ?></td>
      <td><?= (int)$c['n_linhas'] ?><?= (int)$c['n_pendencias'] ? ' <span class="cf-tag cf-grave" title="pendências graves">' . (int)$c['n_pendencias'] . ' ⚠</span>' : '' ?></td>
      <td><?= cf_brl($c['total_pagar']) ?></td>
      <td><?= cf_brl($c['total_receber']) ?></td>
      <td><span class="cf-status cf-st-<?= h($c['status']) ?>"><?= h($c['status']) ?></span><?= $fl ? ' <span class="cf-tag" title="' . h(implode(' | ', $fl)) . '">' . count($fl) . ' aviso(s)</span>' : '' ?></td>
      <td><?= h(substr((string)$c['criado_em'], 0, 16)) ?><br><small><?= h((string)$c['enviado_nome']) ?></small></td>
      <td><?php if (in_array($c['status'], ['revisao', 'descartada'], true)): ?><button type="button" class="cf-x cf-excluir" data-id="<?= (int)$c['id'] ?>" data-nome="<?= h($c['cliente']) ?>" title="excluir esta capa de vez (ainda não foi confirmada)">✕</button><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="cf-dica">✕ exclui de vez uma capa enviada mas ainda não confirmada (arquivo e linhas). Capas confirmadas ficam no histórico.</p>
<script>
(function(){
  const csrf = <?= json_encode(csrf_token()) ?>;
  document.querySelectorAll('.cf-excluir').forEach(b => b.addEventListener('click', async () => {
    if (!confirm('Excluir a capa #' + b.dataset.id + ' (' + b.dataset.nome + ') de vez? Não tem como desfazer — dá pra reenviar a planilha depois.')) return;
    const r = await fetch('/capa-financeira/acao.php', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF':csrf}, body: JSON.stringify({acao:'excluir', capa_id: +b.dataset.id, csrf})});
    let j = null; try { j = await r.json(); } catch(e) {}
    if (!j || !j.ok) { const t = j && j.erro ? j.erro : 'erro ' + r.status; if (window.CFModal) CFModal.aviso({tipo: 'erro', titulo: 'Não excluiu', texto: t}); else alert('Não excluiu: ' + t); return; }
    b.closest('tr').remove();
  }));
})();
</script>
<?php endif; ?>
<?php portal_footer();
