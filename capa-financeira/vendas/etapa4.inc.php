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
.cv-res{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.cv-res div{background:#f7f7f4;border-radius:8px;padding:8px 10px;font-size:12px;color:var(--mute)}.cv-res b{display:block;font-size:16px;color:var(--ink)}
</style>
<div class="cv-cols">
<form id="cv-form4" class="cv-f" autocomplete="off" onsubmit="return false">
  <?php if ($faltam): ?><div class="aviso" style="margin-bottom:10px">Falta concluir <?= h(implode(' e ', $faltam)) ?> — dá para ver o cálculo e ajustar datas, mas só dá para liberar pagamentos depois.</div><?php endif; ?>
  <section class="card">
    <div class="cv-bloco-h"><h2>Pagamentos aos corretores</h2><small><?= $podeLiberar ? 'você pode liberar pagamentos' : 'só o financeiro libera — aqui você ajusta datas e condições' ?></small></div>
    <p class="cf-dica" style="margin-top:0">Cada etapa paga quando o cliente atinge Arras / 50% / 75% / 100% da comissão base. Data sugerida: cliente pagou até segunda → quinta da mesma semana; de terça a domingo → quinta da semana seguinte (quinta feriado → quarta).
      Travas e condições pedem data manual. Liberar um pagamento coloca a conta a pagar na capa da venda (em revisão na Capa Financeira), de onde vai ao Omie.</p>
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
  <button type="button" class="btn cf-btn-sec" id="cv-salvar4">Salvar datas e condições</button>
  <?php if ($podeLiberar): ?>
  <button type="button" class="btn cf-btn-sec" id="cv-desfazer4" disabled>Desfazer liberação</button>
  <button type="button" class="btn" id="cv-liberar4" disabled>Liberar marcados</button>
  <?php endif; ?>
<?php endif; ?>
</div></div>

<script>
(() => {
  const esc = CV.esc, brl = CV.brl, dbr = d => d ? d.split('-').reverse().join('/') : '';
  const pode = <?= $podeLiberar ? 'true' : 'false' ?>;
  const cfg = <?= json_encode((object)($e4['etapas'] ?? []), JSON_UNESCAPED_UNICODE) ?>;   // {chave: {data_manual, condicao}}
  const sel = new Set();
  let ultimo = null, seq = 0;
  const box = document.getElementById('cv-etapas');

  function situacao(p, st) {
    if (!p.liberado) return '<span class="cv-st ag">aguardando liberação</span>';
    const quem = 'liberado por ' + esc(p.liberado.por) + ' em ' + esc(dbr((p.liberado.em || '').slice(0, 10)));
    const s = (st && st.status) || '';
    let chip = s === 'exportado' ? '<span class="cv-st omie" title="' + quem + '">no Omie ' + esc(st.codigo || '') + '</span>'
             : s === 'confirmado' ? '<span class="cv-st conf" title="' + quem + '">confirmado · aguardando envio ao Omie</span>'
             : s === 'revisao' ? '<span class="cv-st lib" title="' + quem + '">liberado · em revisão na Capa Financeira</span>'
             : '<span class="cv-st lib" title="' + quem + '">liberado' + (s ? ' · ' + esc(s) : '') + '</span>';
    if (p.alterado_depois) chip += '<br><span class="cv-st mal">o cálculo mudou: liberado ' + brl(p.liberado.valor) + ' em ' + esc(dbr(p.liberado.data)) + (p.liberado.condicao ? ' · ' + esc(p.liberado.condicao) : '') + ' — libere de novo</span>';
    return chip + '<br><small class="cf-dica" style="margin:0">' + quem + '</small>';
  }
  const podeMarcar = (p, st) => pode && !CV.somenteLeitura && (!p.liberado || p.alterado_depois || (st && st.status) !== 'exportado');

  function render(j) {
    ultimo = j;
    const c = j.calc, t = c.totais || {};
    document.getElementById('cv-res').innerHTML = '<div>Distribuição (etapa 2)<b>' + brl(t.previsto) + '</b></div><div>Soma dos pagamentos<b>' + brl(t.total) + '</b></div>'
      + '<div>Centavos p/ a imobiliária<b>' + brl(t.sobra_centavos) + '</b></div><div>Já liberado<b>' + brl(t.liberado) + '</b></div>';
    if (!c.etapas.length) { box.innerHTML = '<p class="aviso">Sem etapas de pagamento — preencha o fluxo do cliente (etapa 3) e a distribuição (etapa 2).</p>'; }
    else box.innerHTML = c.etapas.map(e => {
      const ps = c.pagamentos.filter(p => p.etapa === e.chave);
      const k = esc(e.chave), cf = cfg[e.chave] || {};
      const soma = ps.reduce((a, p) => a + p.valor, 0);
      const marcaveis = ps.filter(p => podeMarcar(p, j.status[p.chave]));
      return '<section class="cv-et ' + (e.exige_manual && !e.data_manual ? 'manual' : 'ok') + '">'
        + '<div class="cv-et-h"><h3>' + esc(e.rotulo) + '</h3><small>' + (e.bonus ? 'bônus · na última parcela do cliente' : 'cliente atinge ' + CV.pc(e.pct_preenchido) + ' da comissão base') + ' · líquido da etapa ' + brl(e.liquido) + '</small></div>'
        + '<div class="cv-et-dt">'
        + '<div><label>Cliente paga em</label><div class="v">' + esc(dbr(e.data_cliente)) + '</div></div>'
        + '<div><label>Data sugerida</label><div class="v">' + (e.exige_manual ? '<s style="color:var(--mute)">' + esc(dbr(e.data_sugerida)) + '</s>' : esc(dbr(e.data_sugerida))) + '</div></div>'
        + '<label>Condição <small>(vai para a nota/G)</small><input list="cv-conds" data-cond="' + k + '" value="' + esc(cf.condicao || '') + '" placeholder="ex.: SE ASSINAR CONTRATO"></label>'
        + '<label>Data de pagamento' + (e.exige_manual ? ' <b style="color:var(--clay)">obrigatória</b>' : ' <small>(manual, opcional)</small>') + '<input type="date" data-dm="' + k + '" value="' + esc(cf.data_manual || '') + '"'
        + (e.exige_manual && !e.data_manual ? ' class="cv-erro"' : '') + '>' + (e.motivo_manual ? '<span class="cv-sug">' + esc(e.motivo_manual) + '</span>' : '') + '</label>'
        + '</div>'
        + '<table class="cv-tab"><thead><tr><th style="width:28px">' + (marcaveis.length ? '<input type="checkbox" data-todos="' + k + '" title="marcar todos desta etapa"' + (marcaveis.every(p => sel.has(p.chave)) ? ' checked' : '') + '>' : '') + '</th>'
        + '<th>Cargo</th><th>Nome</th><th class="n">%</th><th class="n">Valor</th><th>Data</th><th>Situação</th></tr></thead><tbody>'
        + ps.map(p => {
            const st = j.status[p.chave];
            return '<tr' + (sel.has(p.chave) ? ' class="sel"' : '') + '><td>' + (podeMarcar(p, st) ? '<input type="checkbox" data-pag="' + esc(p.chave) + '"' + (sel.has(p.chave) ? ' checked' : '') + '>' : '') + '</td>'
              + '<td><b>' + esc(p.cargo) + '</b>' + (p.natureza === 'BONUS' ? ' <small>bônus</small>' : '') + '</td><td>' + esc(p.nome) + (p.obs ? '<span class="cv-desc-linha">' + esc(p.obs) + '</span>' : '') + '</td>'
              + '<td class="n">' + (p.pct > 0 ? CV.pc(p.pct) : '') + '</td><td class="n">' + brl(p.valor) + '</td><td>' + (p.data ? esc(dbr(p.data)) : '<i style="color:var(--clay)">sem data</i>') + '</td><td>' + situacao(p, st) + '</td></tr>';
          }).join('')
        + '</tbody><tfoot><tr><td></td><td colspan="3">total da etapa</td><td class="n">' + brl(soma) + '</td><td colspan="2"></td></tr></tfoot></table></section>';
    }).join('');
    const pend = document.getElementById('cv-pend4');
    pend.innerHTML = '<h2>Pendências</h2>' + (j.erros.length ? '<div class="e"><b>Erros</b>' + CV.lista(j.erros) + '</div>' : '')
      + (j.avisos.length ? '<div class="a"><b>Avisos</b>' + CV.lista(j.avisos) + '</div>' : '') + (!j.erros.length && !j.avisos.length ? '<p class="cf-dica">Nada pendente.</p>' : '');
    if (CV.somenteLeitura) box.querySelectorAll('input').forEach(x => x.disabled = true);
    botoes();
  }
  function botoes() {
    if (!ultimo) return;
    const marc = ultimo.calc.pagamentos.filter(p => sel.has(p.chave));
    const lib = document.getElementById('cv-liberar4'), des = document.getElementById('cv-desfazer4');
    if (lib) { lib.disabled = !marc.length; lib.textContent = marc.length ? 'Liberar ' + marc.length + ' (' + brl(marc.reduce((a, p) => a + p.valor, 0)) + ')' : 'Liberar marcados'; }
    if (des) des.disabled = !marc.some(p => p.liberado && (ultimo.status[p.chave] || {}).status !== 'exportado');
    document.getElementById('cv-sel-txt').textContent = marc.length ? marc.length + ' marcado(s)' : '';
  }
  async function previa() { const n = ++seq; const j = await CV.api({acao: 'previa4', e4: {etapas: cfg}}); if (n === seq) { if (j.ok) render(j); else CV.msg('erro', CV.lista(j.erros || [])); } }

  box.addEventListener('change', ev => {
    const i = ev.target;
    if (i.dataset.cond !== undefined || i.dataset.dm !== undefined) {
      const k = i.dataset.cond ?? i.dataset.dm;
      cfg[k] = cfg[k] || {};
      if (i.dataset.cond !== undefined) cfg[k].condicao = i.value.trim().toUpperCase(); else cfg[k].data_manual = i.value;
      CV.sujo = true; previa(); return;
    }
    if (i.dataset.pag) { i.checked ? sel.add(i.dataset.pag) : sel.delete(i.dataset.pag); i.closest('tr').classList.toggle('sel', i.checked); botoes(); return; }
    if (i.dataset.todos) {
      ultimo.calc.pagamentos.filter(p => p.etapa === i.dataset.todos && podeMarcar(p, ultimo.status[p.chave])).forEach(p => i.checked ? sel.add(p.chave) : sel.delete(p.chave));
      render(ultimo);
    }
  });

  async function enviar(acao, chaves) {
    document.querySelectorAll('.cv-barra-fim button').forEach(b => b.disabled = true);
    const j = await CV.api({acao, e4: {etapas: cfg}, chaves});
    if (j.rev) CV.rev = j.rev;
    if (j.ok) return CV.vai(j.msg + (j.avisos && j.avisos.length ? ' Atenção: ' + j.avisos.join(' · ') : ''));
    document.querySelectorAll('.cv-barra-fim button').forEach(b => b.disabled = false); botoes();
    CV.msg('erro', '<b>' + esc(j.msg || 'Não foi possível gravar:') + '</b>' + CV.lista(j.erros || []));
  }
  const bS = document.getElementById('cv-salvar4'); if (bS) bS.onclick = () => enviar('salvar4', []);
  const bL = document.getElementById('cv-liberar4');
  if (bL) bL.onclick = () => {
    const m = ultimo.calc.pagamentos.filter(p => sel.has(p.chave));
    const novos = m.filter(p => (ultimo.status[p.chave] || {}).status === 'exportado');
    if (!confirm('Liberar ' + m.length + ' pagamento(s), total ' + brl(m.reduce((a, p) => a + p.valor, 0)) + '?\n\n' + m.map(p => '• ' + p.nome + ' (' + p.cargo + ') ' + brl(p.valor) + ' em ' + dbr(p.data)).join('\n')
      + (novos.length ? '\n\n' + novos.length + ' deles já está(ão) no Omie com outro valor/data — a capa vai sair com a alteração para conferir na Capa Financeira.' : '')
      + '\n\nAs contas a pagar vão para a capa da venda (em revisão na Capa Financeira).')) return;
    enviar('liberar4', m.map(p => p.chave));
  };
  const bD = document.getElementById('cv-desfazer4');
  if (bD) bD.onclick = () => {
    const m = ultimo.calc.pagamentos.filter(p => sel.has(p.chave) && p.liberado && (ultimo.status[p.chave] || {}).status !== 'exportado');
    if (!confirm('Desfazer a liberação de ' + m.length + ' pagamento(s)? Eles saem das contas a pagar da capa (ainda não foram ao Omie).')) return;
    enviar('desfazer4', m.map(p => p.chave));
  };
  previa();
})();
</script>
