<?php
/* capa-financeira/vendas/etapa4.inc.php — etapa 4 (pagamentos aos corretores).
   O servidor monta os pagamentos (Pagamentos::calcular): etapas Arras/50/75/100 + bônus na quitação,
   data sugerida (quinta-feira pela regra da segunda), datas manuais (travas/condições).
   Só o financeiro libera; cada liberação regera a capa com as contas a pagar liberadas (em revisão na Capa Financeira). */
declare(strict_types=1);

cv_atualizar_conclusao($id, $u);   // títulos podem ter ido ao Omie pela Capa Financeira desde a última visita
$v = cv_carregar($id) ?? $v;
$e4 = $v['e4'] ?? ['etapas' => [], 'liberados' => []];
$podeLiberar = cv_pode_liberar($u);
$conds = array_keys((array)cf_config('nf_dict', []));
$faltam = [];
if ($v['e2_status'] === 'rascunho') $faltam[] = 'a etapa 2 (distribuição)';
if ($v['e3_status'] === 'rascunho') $faltam[] = 'a etapa 3 (fluxo do cliente)';
?>
<style>
.cv-et{border:1px solid var(--line);border-radius:10px;background:#fff;margin:12px 0;padding:12px 14px}
.cv-et.manual{border-left:4px solid var(--clay)}.cv-et.ok{border-left:4px solid var(--moss)}
.cv-et-h{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:baseline}
.cv-et-h h3{margin:0;font-size:17px}.cv-et-h small{color:var(--mute)}
.cv-et-dt{display:grid;grid-template-columns:1fr 1fr 1.4fr 1fr;gap:10px;margin:10px 0 6px}
@media(max-width:800px){.cv-et-dt{grid-template-columns:1fr 1fr}}
.cv-et-dt .v{font-size:15px;font-weight:700;margin-top:6px}
.cv-st{display:inline-block;font-size:11.5px;font-weight:600;border-radius:10px;padding:1px 8px;white-space:nowrap}
.cv-st.ag{background:#eee;color:var(--mute)}.cv-st.lib{background:#fff1cc;color:#7a5a00}.cv-st.conf{background:#e3ede8;color:var(--moss)}.cv-st.omie{background:var(--moss);color:#fff}.cv-st.mal{background:rgba(180,81,47,.14);color:var(--clay)}
.cv-tab tr.sel td{background:#f3f8f4}
.cv-tab td.ed{cursor:default}.cv-lapis{border:0;background:none;cursor:pointer;color:var(--mute);font-size:14px;padding:0 4px}.cv-lapis:hover{color:var(--moss)}
.cv-editado{background:#fff8e1;border-radius:4px;padding:0 3px}.cv-tab tr.editando td{background:#fffdf3}
.cv-tab tr.editando input{width:100%;box-sizing:border-box}
.cv-res{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.cv-res div{background:#f7f7f4;border-radius:8px;padding:8px 10px;font-size:12px;color:var(--mute)}.cv-res b{display:block;font-size:16px;color:var(--ink)}
</style>
<div class="cv-cols">
<form id="cv-form4" class="cv-f" autocomplete="off" onsubmit="return false">
  <?php if ($faltam): ?><div class="aviso" style="margin-bottom:10px">Falta concluir <?= h(implode(' e ', $faltam)) ?> — dá para ver o cálculo e ajustar datas, mas só dá para liberar pagamentos depois.</div><?php endif; ?>
  <section class="card">
    <div class="cv-bloco-h"><h2>Pagamentos aos corretores</h2><small><?= $podeLiberar ? 'você pode liberar pagamentos' : 'só o financeiro libera — aqui você ajusta datas e condições' ?></small></div>
    <p class="cf-dica" style="margin-top:0">Cada etapa paga quando o cliente atinge Arras / 50% / 75% / 100% da comissão base. Data sugerida: cliente pagou até segunda → quinta da mesma semana; de terça a domingo → quinta da semana seguinte (quinta feriado → quarta).
      Travas e condições pedem data manual. Para mudar uma linha só, clique no ✎ (ou dê dois cliques na linha). “Liberar todos” coloca todas as contas a pagar na capa da venda (em revisão na Capa Financeira), de onde vão ao Omie — igual às contas a receber.</p>
    <div class="cv-res" id="cv-res"></div>
  </section>
  <div id="cv-etapas"><p class="cf-dica">Calculando…</p></div>
  <datalist id="cv-conds"><?php foreach ($conds as $c) echo '<option value="' . h((string)$c) . '">'; ?></datalist>
</form>
<aside class="cv-lado">
  <section class="card cv-pend" id="cv-pend4"></section>
  <?php cv_aside_comum($venda, $hist, $cancelada); ?>
</aside>
</div>
<div class="cv-barra-fim cv-botoes"><div class="cv-nav">
  <a class="btn cf-btn-sec" href="/capa-financeira/vendas/editar.php?id=<?= (int)$id ?>&etapa=3">← Etapa 3</a>
  <span class="cv-status-txt" id="cv-sel-txt"><?= $v['status'] === 'concluida' ? 'Venda concluída — todos os pagamentos no Omie' : ($v['e4_status'] !== 'rascunho' ? 'Todos os pagamentos liberados' : '') ?></span>
</div><div class="cv-acoes">
<?php if (!$cancelada): ?>
  <button type="button" class="btn cf-btn-sec" id="cv-salvar4">Salvar alterações</button>
  <?php if ($podeLiberar): ?>
  <button type="button" class="cf-x" id="cv-parcial" title="mostra as caixas para liberar/desfazer só alguns pagamentos">liberação parcial…</button>
  <button type="button" class="btn cf-btn-sec" id="cv-desfazer4" style="display:none" disabled>Desfazer liberação</button>
  <button type="button" class="btn" id="cv-liberar-marc" style="display:none" disabled>Liberar marcados</button>
  <button type="button" class="btn" id="cv-liberar-todos" disabled>Liberar todos</button>
  <?php endif; ?>
<?php endif; ?>
</div></div>

<script>
(() => {
  const esc = CV.esc, brl = CV.brl, dbr = d => d ? d.split('-').reverse().join('/') : '';
  const pode = <?= $podeLiberar ? 'true' : 'false' ?>;
  const cfg = <?= json_encode((object)($e4['etapas'] ?? []), JSON_UNESCAPED_UNICODE) ?>;   // {chave etapa: {data_manual, condicao}}
  const lin = <?= json_encode((object)($e4['linhas'] ?? []), JSON_UNESCAPED_UNICODE) ?>;    // {chave pagamento: {data, valor, condicao, obs}} — ajustes manuais
  const sel = new Set();
  let ultimo = null, seq = 0, parcial = false, editando = null;
  const box = document.getElementById('cv-etapas');
  const e4 = () => ({etapas: cfg, linhas: lin});
  const st = p => (ultimo.status[p.chave] || {}).status || '';
  const pendente = p => !p.liberado || p.alterado_depois;

  function situacao(p) {
    if (!p.liberado) return '<span class="cv-st ag">aguardando liberação</span>';
    const s = ultimo.status[p.chave] || {}, quem = 'liberado por ' + esc(p.liberado.por) + ' em ' + esc(dbr((p.liberado.em || '').slice(0, 10)));
    let chip = s.status === 'exportado' ? '<span class="cv-st omie" title="' + quem + '">no Omie ' + esc(s.codigo || '') + '</span>'
             : s.status === 'confirmado' ? '<span class="cv-st conf" title="' + quem + '">confirmado · aguardando envio ao Omie</span>'
             : '<span class="cv-st lib" title="' + quem + '">liberado · em revisão na Capa Financeira</span>';
    if (p.alterado_depois) chip += '<br><span class="cv-st mal">mudou depois de liberado (era ' + brl(p.liberado.valor) + ' em ' + esc(dbr(p.liberado.data)) + ') — libere de novo</span>';
    return chip;
  }
  const marca = (p, campo, html) => (p.editado || []).includes(campo)
    ? '<span class="cv-editado" title="ajustado à mão · calculado: ' + esc(campo === 'valor' ? brl(p.calc.valor) : campo === 'data' ? dbr(p.calc.data) || 'sem data' : p.calc[campo] || '(vazio)') + '">' + html + ' ✎</span>' : html;
  const podeMarcar = p => pode && !CV.somenteLeitura && (pendente(p) || st(p) !== 'exportado');
  const numBr = v => (+v || 0).toFixed(2).replace('.', ',');

  function linha(p) {
    const ed = editando === p.chave && !CV.somenteLeitura, k = esc(p.chave);
    const cx = parcial ? '<td>' + (podeMarcar(p) ? '<input type="checkbox" data-pag="' + k + '"' + (sel.has(p.chave) ? ' checked' : '') + '>' : '') + '</td>' : '';
    const pre = '<td><b>' + esc(p.cargo) + '</b>' + (p.natureza === 'BONUS' ? ' <small>bônus</small>' : '') + '</td>';
    if (ed) return '<tr class="editando" data-linha="' + k + '">' + cx + pre
      + '<td>' + esc(p.nome) + '<input data-e="obs" value="' + esc(p.obs) + '" placeholder="observação" style="margin-top:4px"></td><td class="n">' + (p.pct > 0 ? CV.pc(p.pct) : '') + '</td>'
      + '<td class="n"><input data-e="valor" class="cv-num" inputmode="decimal" value="' + numBr(p.valor) + '"><span class="cv-sug">calculado ' + brl(p.calc.valor) + '</span></td>'
      + '<td><input data-e="data" type="date" value="' + esc(p.data || '') + '"><input data-e="condicao" list="cv-conds" value="' + esc(p.condicao) + '" placeholder="condição" style="margin-top:4px"></td>'
      + '<td><button type="button" class="cv-mini-btn" data-ok-ed>✓ aplicar</button> <button type="button" class="cv-mini-btn" data-x-ed>cancelar</button>'
      + ((p.editado || []).length ? '<br><button type="button" class="cv-mini-btn" data-calc-ed style="margin-top:4px">↺ voltar ao calculado</button>' : '') + '</td></tr>';
    return '<tr data-linha="' + k + '"' + (sel.has(p.chave) ? ' class="sel"' : '') + '>' + cx + pre
      + '<td>' + esc(p.nome) + (p.obs ? '<span class="cv-desc-linha">' + marca(p, 'obs', esc(p.obs)) + '</span>' : '') + '</td>'
      + '<td class="n">' + (p.pct > 0 ? CV.pc(p.pct) : '') + '</td><td class="n">' + marca(p, 'valor', brl(p.valor)) + '</td>'
      + '<td>' + marca(p, 'data', p.data ? esc(dbr(p.data)) : '<i style="color:var(--clay)">sem data</i>') + (p.condicao ? '<span class="cv-desc-linha">' + marca(p, 'condicao', esc(p.condicao)) + '</span>' : '') + '</td>'
      + '<td>' + situacao(p) + '</td><td>' + (CV.somenteLeitura ? '' : '<button type="button" class="cv-lapis" data-lapis title="editar esta linha">✎</button>') + '</td></tr>';
  }

  function render(j) {
    if (j) ultimo = j;
    const c = ultimo.calc, t = c.totais || {};
    document.getElementById('cv-res').innerHTML = '<div>Distribuição (etapa 2)<b>' + brl(t.previsto) + '</b></div><div>Soma dos pagamentos<b>' + brl(t.total) + '</b></div>'
      + '<div>Diferença (fica com a imobiliária)<b>' + brl(t.sobra_centavos) + '</b></div><div>Já liberado<b>' + brl(t.liberado) + '</b></div>';
    if (!c.etapas.length) box.innerHTML = '<p class="aviso">Sem etapas de pagamento — preencha o fluxo do cliente (etapa 3) e a distribuição (etapa 2).</p>';
    else box.innerHTML = c.etapas.map(e => {
      const ps = c.pagamentos.filter(p => p.etapa === e.chave), k = esc(e.chave), cf = cfg[e.chave] || {};
      const marcaveis = ps.filter(podeMarcar);
      const semData = ps.filter(p => !p.data).length;
      return '<section class="cv-et ' + (semData ? 'manual' : 'ok') + '">'
        + '<div class="cv-et-h"><h3>' + esc(e.rotulo) + '</h3><small>' + (e.bonus ? 'bônus · na última parcela do cliente' : 'cliente atinge ' + CV.pc(e.pct_preenchido) + ' da comissão base') + ' · líquido da etapa ' + brl(e.liquido) + '</small></div>'
        + '<div class="cv-et-dt">'
        + '<div><label>Cliente paga em</label><div class="v">' + esc(dbr(e.data_cliente)) + '</div></div>'
        + '<div><label>Data sugerida</label><div class="v">' + (e.exige_manual ? '<s style="color:var(--mute)">' + esc(dbr(e.data_sugerida)) + '</s>' : esc(dbr(e.data_sugerida))) + '</div></div>'
        + '<label>Condição da etapa <small>(todas as linhas)</small><input list="cv-conds" data-cond="' + k + '" value="' + esc(cf.condicao || '') + '" placeholder="ex.: SE ASSINAR CONTRATO"></label>'
        + '<label>Data de todas as linhas' + (e.exige_manual ? ' <b style="color:var(--clay)">obrigatória</b>' : ' <small>(opcional)</small>') + '<input type="date" data-dm="' + k + '" value="' + esc(cf.data_manual || '') + '"'
        + (e.exige_manual && !e.data_manual ? ' class="cv-erro"' : '') + '>' + (e.motivo_manual ? '<span class="cv-sug">' + esc(e.motivo_manual) + '</span>' : '') + '</label>'
        + '</div>'
        + '<table class="cv-tab"><thead><tr>' + (parcial ? '<th style="width:28px">' + (marcaveis.length ? '<input type="checkbox" data-todos="' + k + '" title="marcar todos desta etapa"' + (marcaveis.every(p => sel.has(p.chave)) ? ' checked' : '') + '>' : '') + '</th>' : '')
        + '<th>Cargo</th><th>Nome</th><th class="n">%</th><th class="n" style="width:120px">Valor</th><th style="width:150px">Data</th><th>Situação</th><th style="width:26px"></th></tr></thead><tbody>'
        + ps.map(linha).join('')
        + '</tbody><tfoot><tr>' + (parcial ? '<td></td>' : '') + '<td colspan="3">total da etapa</td><td class="n">' + brl(ps.reduce((a, p) => a + p.valor, 0)) + '</td><td colspan="3"></td></tr></tfoot></table></section>';
    }).join('');
    const pend = document.getElementById('cv-pend4');
    pend.innerHTML = '<h2>Pendências</h2>' + (ultimo.erros.length ? '<div class="e"><b>Erros</b>' + CV.lista(ultimo.erros) + '</div>' : '')
      + (ultimo.avisos.length ? '<div class="a"><b>Avisos</b>' + CV.lista(ultimo.avisos) + '</div>' : '') + (!ultimo.erros.length && !ultimo.avisos.length ? '<p class="cf-dica">Nada pendente.</p>' : '');
    if (CV.somenteLeitura) box.querySelectorAll('input').forEach(x => x.disabled = true);
    botoes();
  }
  function botoes() {
    if (!ultimo) return;
    const ps = ultimo.calc.pagamentos, falta = ps.filter(pendente), marc = ps.filter(p => sel.has(p.chave));
    const $ = id => document.getElementById(id);
    if ($('cv-liberar-todos')) {
      $('cv-liberar-todos').style.display = parcial ? 'none' : '';
      $('cv-liberar-todos').disabled = !falta.length;
      $('cv-liberar-todos').textContent = falta.length ? 'Liberar todos (' + falta.length + ' · ' + brl(falta.reduce((a, p) => a + p.valor, 0)) + ')' : 'Tudo liberado';
      $('cv-liberar-marc').style.display = $('cv-desfazer4').style.display = parcial ? '' : 'none';
      $('cv-liberar-marc').disabled = !marc.length;
      $('cv-liberar-marc').textContent = marc.length ? 'Liberar ' + marc.length + ' marcado(s) (' + brl(marc.reduce((a, p) => a + p.valor, 0)) + ')' : 'Liberar marcados';
      $('cv-desfazer4').disabled = !marc.some(p => p.liberado && st(p) !== 'exportado');
      $('cv-parcial').textContent = parcial ? 'voltar para “liberar todos”' : 'liberação parcial…';
    }
    if (parcial) $('cv-sel-txt').textContent = marc.length + ' marcado(s)';
  }
  async function previa() { const n = ++seq; const j = await CV.api({acao: 'previa4', e4: e4()}); if (n === seq) { if (j.ok) render(j); else CV.msg('erro', CV.lista(j.erros || [])); } }
  const pag = k => ultimo.calc.pagamentos.find(p => p.chave === k);

  function aplicarEdicao(tr) {
    const p = pag(tr.dataset.linha), o = {...(lin[p.chave] || {})};
    tr.querySelectorAll('[data-e]').forEach(i => {
      const f = i.dataset.e; let val = i.value.trim();
      if (f === 'condicao') val = val.toUpperCase();
      let calc = p.calc[f] ?? '';
      if (f === 'valor') { const n = Number(val.replace(/[R$\s]/g, '').replace(/\./g, '').replace(',', '.')); if (!isFinite(n) || val === '') { i.classList.add('cv-erro'); throw new Error('valor'); } val = Math.round(n * 100) / 100; calc = Math.round(calc * 100) / 100; }
      if (String(val) === String(calc ?? '')) delete o[f]; else o[f] = val;
    });
    if (Object.keys(o).length) lin[p.chave] = o; else delete lin[p.chave];
    editando = null; CV.sujo = true; previa();
  }
  box.addEventListener('click', ev => {
    const tr = ev.target.closest('tr[data-linha]'); if (!tr) return;
    if (ev.target.closest('[data-lapis]')) { editando = tr.dataset.linha; render(); const v = box.querySelector('tr.editando [data-e=valor]'); if (v) v.focus(); }
    else if (ev.target.closest('[data-ok-ed]')) { try { aplicarEdicao(tr); } catch (e) {} }
    else if (ev.target.closest('[data-x-ed]')) { editando = null; render(); }
    else if (ev.target.closest('[data-calc-ed]')) { delete lin[tr.dataset.linha]; editando = null; CV.sujo = true; previa(); }
  });
  box.addEventListener('dblclick', ev => {
    const tr = ev.target.closest('tr[data-linha]'); if (!tr || tr.classList.contains('editando') || CV.somenteLeitura || ev.target.closest('input')) return;
    editando = tr.dataset.linha; render();
  });
  box.addEventListener('keydown', ev => {
    const tr = ev.target.closest('tr.editando'); if (!tr) return;
    if (ev.key === 'Enter') { ev.preventDefault(); try { aplicarEdicao(tr); } catch (e) {} }
    if (ev.key === 'Escape') { editando = null; render(); }
  });
  box.addEventListener('focusout', ev => { if (ev.target.dataset.e === 'valor') CV.numBlur(ev.target, false); });
  box.addEventListener('change', ev => {
    const i = ev.target;
    if (i.dataset.cond !== undefined || i.dataset.dm !== undefined) {
      const k = i.dataset.cond ?? i.dataset.dm;
      cfg[k] = cfg[k] || {};
      const campo = i.dataset.cond !== undefined ? 'condicao' : 'data';
      // "alterar todas as linhas da etapa": quem tinha ajuste próprio nesse campo pergunta antes
      const proprias = ultimo.calc.pagamentos.filter(p => p.etapa === k && (lin[p.chave] || {})[campo] !== undefined);
      if (proprias.length && confirm(proprias.length + ' linha(s) desta etapa têm ' + (campo === 'data' ? 'data' : 'condição') + ' própria. Aplicar o novo valor também nelas?'))
        proprias.forEach(p => { delete lin[p.chave][campo]; if (!Object.keys(lin[p.chave]).length) delete lin[p.chave]; });
      if (campo === 'condicao') cfg[k].condicao = i.value.trim().toUpperCase(); else cfg[k].data_manual = i.value;
      CV.sujo = true; previa(); return;
    }
    if (i.dataset.pag) { i.checked ? sel.add(i.dataset.pag) : sel.delete(i.dataset.pag); i.closest('tr').classList.toggle('sel', i.checked); botoes(); return; }
    if (i.dataset.todos) { ultimo.calc.pagamentos.filter(p => p.etapa === i.dataset.todos && podeMarcar(p)).forEach(p => i.checked ? sel.add(p.chave) : sel.delete(p.chave)); render(); }
  });

  async function enviar(acao, extra) {
    if (editando) { alert('Aplique ou cancele a linha que está editando.'); return; }
    document.querySelectorAll('.cv-barra-fim button').forEach(b => b.disabled = true);
    const j = await CV.api({acao, e4: e4(), ...extra});
    if (j.rev) CV.rev = j.rev;
    if (j.ok) return CV.vai(j.msg + (j.avisos && j.avisos.length ? ' Atenção: ' + j.avisos.join(' · ') : ''));
    document.querySelectorAll('.cv-barra-fim button').forEach(b => b.disabled = false); botoes();
    CV.msg('erro', '<b>' + esc(j.msg || 'Não foi possível gravar:') + '</b>' + CV.lista(j.erros || []));
  }
  const resumo = ps => {
    const porEt = {}; ps.forEach(p => { const r = ultimo.calc.etapas.find(e => e.chave === p.etapa).rotulo; (porEt[r] = porEt[r] || []).push(p); });
    return Object.entries(porEt).map(([r, l]) => '• ' + r + ': ' + l.length + ' pagamento(s), ' + brl(l.reduce((a, p) => a + p.valor, 0))).join('\n');
  };
  const $ = id => document.getElementById(id);
  if ($('cv-salvar4')) $('cv-salvar4').onclick = () => enviar('salvar4', {});
  if ($('cv-parcial')) $('cv-parcial').onclick = () => { parcial = !parcial; if (!parcial) sel.clear(); $('cv-sel-txt').textContent = ''; render(); };
  if ($('cv-liberar-todos')) $('cv-liberar-todos').onclick = () => {
    const ps = ultimo.calc.pagamentos.filter(pendente);
    if (!confirm('Liberar TODOS os pagamentos que faltam — ' + ps.length + ', total ' + brl(ps.reduce((a, p) => a + p.valor, 0)) + '?\n\n' + resumo(ps)
      + '\n\nAs contas a pagar vão para a capa da venda (em revisão na Capa Financeira), de onde seguem ao Omie.')) return;
    enviar('liberar4', {todos: true});
  };
  if ($('cv-liberar-marc')) $('cv-liberar-marc').onclick = () => {
    const m = ultimo.calc.pagamentos.filter(p => sel.has(p.chave));
    if (!confirm('Liberar só ' + m.length + ' pagamento(s), total ' + brl(m.reduce((a, p) => a + p.valor, 0)) + '?\n\n' + resumo(m))) return;
    enviar('liberar4', {chaves: m.map(p => p.chave)});
  };
  if ($('cv-desfazer4')) $('cv-desfazer4').onclick = () => {
    const m = ultimo.calc.pagamentos.filter(p => sel.has(p.chave) && p.liberado && st(p) !== 'exportado');
    if (!confirm('Desfazer a liberação de ' + m.length + ' pagamento(s)? Eles saem das contas a pagar da capa (ainda não foram ao Omie).')) return;
    enviar('desfazer4', {chaves: m.map(p => p.chave)});
  };
  previa();
})();
</script>
