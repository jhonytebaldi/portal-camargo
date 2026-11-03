<?php
/* capa-financeira/vendas/etapa2.inc.php — etapa 2 (distribuição das comissões e do bônus +
   conclusão do processo). Incluído por editar.php. Valores sempre recalculados no servidor. */
declare(strict_types=1);

$e2 = $v['e2'] ?? Distribuicao::vazia();
$pessoasNomes = array_column(cf_pessoas(true), 'nome');
$e2ok = $v['e2_status'] !== 'rascunho';
$res = cv_resolver_pessoa();
$padraoRecebem = array_map(function ($x) use ($res) { $r = $res($x['nome']); return ['nome' => $r[1] ?? $x['nome'], 'cargo' => $x['cargo'], 'valor' => $x['valor']]; }, Distribuicao::CONCLUSAO_PADRAO['recebem']);
$pctTxt = fn($p) => $p === null ? '' : rtrim(rtrim(number_format((float)$p * 100, 3, ',', ''), '0'), ',');
// retenção: até 03/11 a linha tinha "nome" (ex.: QLV); agora o nome vira a observação
$linhasJs = array_map(fn($l) => ['bloco' => $l['bloco'], 'cargo' => $l['cargo'], 'nome' => $l['nome'], 'obs' => ($l['obs'] ?? '') !== '' ? $l['obs'] : (Distribuicao::retencao($l['bloco']) ? $l['nome'] : ''), 'pct' => $pctTxt($l['pct'])], $e2['linhas']);
$ultimos = array_map(fn($x) => ['pct' => $pctTxt($x['pct']), 'venda' => $x['venda']], cv_ultimos_pct((int)$id));
$empresaNome = '';
foreach (cf_config('empresas', []) as $em) if (($em['id'] ?? '') === $v['empresa']) $empresaNome = (string)$em['nome'];
$c = $e2['conclusao'];
$concJs = ['ativo' => (bool)$c['ativo'], 'valor' => number_format((float)($c['valor'] ?? 200), 2, ',', '.'), 'descricao' => (string)$c['descricao'],
           'recebem' => array_map(fn($x) => ['nome' => $x['nome'], 'cargo' => $x['cargo'], 'valor' => $x['valor'] !== null ? number_format((float)$x['valor'], 2, ',', '.') : ''], $c['recebem']),
           'descontos' => array_map(fn($x) => ['ref' => $x['ref'], 'valor' => $x['valor'] !== null ? number_format((float)$x['valor'], 2, ',', '.') : ''], $c['descontos'])];
?>
<datalist id="cv-lista-pessoas"><?php foreach ($pessoasNomes as $n): ?><option value="<?= h($n) ?>"><?php endforeach; ?></datalist>
<div class="cv-cols">
<form id="cv-form2" class="cv-f" autocomplete="off" onsubmit="return false">
  <?php if ($v['e1_status'] === 'rascunho'): ?>
    <div class="aviso" style="margin-bottom:10px">A etapa 1 ainda não foi concluída — dá para ir preenchendo a distribuição, mas só dá para concluir depois da etapa 1 (os valores dependem dela).</div>
  <?php endif; ?>
  <?php foreach (Distribuicao::BLOCOS as $b => [$titulo, $base, $sub]): ?>
  <section class="card" data-bloco="<?= $b ?>">
    <div class="cv-bloco-h"><h2><?= h($titulo) ?></h2><small><?= h($sub) ?>: <b data-base>—</b></small></div>
    <?php if (Distribuicao::retencao($b)): ?><p class="cf-dica" style="margin-top:0">Fica com a própria empresa da venda (<b><?= h($empresaNome ?: 'escolha a empresa na etapa 1') ?></b>) — <b>não</b> vira conta a pagar. Use a observação para dizer do que se trata (ex.: INTEGRAÇÃO QLV).</p><?php endif; ?>
    <table class="cv-tab">
      <thead><tr><th style="width:170px">Cargo</th><th><?= Distribuicao::retencao($b) ? 'Observação' : 'Nome (cadastro de Pessoas)' ?></th><th style="width:90px">%</th><th style="width:130px" class="n">Valor</th><th style="width:34px"></th></tr></thead>
      <tbody></tbody>
      <tfoot><tr><td></td><td style="text-align:right">total</td><td class="n" data-soma-pct></td><td class="n" data-soma-val></td><td></td></tr></tfoot>
    </table>
    <button type="button" class="cv-mini-btn" data-add-linha style="margin-top:6px">+ linha</button>
  </section>
  <?php endforeach; ?>

  <section class="card" id="cv-conc">
    <h2>Conclusão do processo</h2>
    <label style="display:flex;gap:8px;align-items:center;font-size:14px;font-weight:600"><input type="checkbox" id="cv-conc-ativo" style="width:auto;margin:0"> O processo <u>não</u> foi concluído pelo corretor (outra pessoa concluiu)</label>
    <div id="cv-conc-corpo" style="display:none;margin-top:10px">
      <div class="cv-g cv-g2">
        <label>Valor total da conclusão<input class="cv-num" id="cv-conc-valor" inputmode="decimal"></label>
        <label>Descrição (o que foi feito e por quê)<textarea id="cv-conc-desc" rows="2" placeholder="ex.: financeiro e SAC concluíram a documentação e o contrato com a construtora"></textarea></label>
      </div>
      <h3 style="font-size:14px;margin:12px 0 4px">Quem concluiu (recebe)</h3>
      <table class="cv-tab"><thead><tr><th>Nome</th><th style="width:170px">Cargo</th><th style="width:130px">Valor</th><th style="width:34px"></th></tr></thead><tbody id="cv-receb"></tbody></table>
      <button type="button" class="cv-mini-btn" id="cv-add-receb" style="margin-top:6px">+ pessoa (até 3)</button>
      <h3 style="font-size:14px;margin:14px 0 4px">Descontado de</h3>
      <p class="cf-dica" style="margin:0 0 6px">Sai do 1º pagamento de cada um quando ele cobrir o desconto; senão, do 2º — nunca parcial.
        Atalhos: <button type="button" class="cv-mini-btn" data-div="CORRETOR">só o(s) corretor(es)</button><button type="button" class="cv-mini-btn" data-div="CAPTADOR">só do captador</button><button type="button" class="cv-mini-btn" data-div="CORRETOR,CAPTADOR">corretor + captador</button><button type="button" class="cv-mini-btn" data-div="CORRETOR,CAPTADOR,COORDENADOR">corretor + captador + coordenador</button></p>
      <table class="cv-tab"><thead><tr><th>Linha da comissão</th><th style="width:130px">Valor</th><th style="width:34px"></th></tr></thead><tbody id="cv-descs"></tbody></table>
      <button type="button" class="cv-mini-btn" id="cv-add-desc" style="margin-top:6px">+ desconto</button>
    </div>
  </section>
</form>

<aside class="cv-lado">
  <section class="card">
    <h2>Resumo da distribuição</h2>
    <table class="cv-calc" id="cv-res"></table>
  </section>
  <section class="card">
    <h2>Por pessoa</h2>
    <table class="cv-calc" id="cv-pessoas"></table>
  </section>
  <section class="card cv-pend" id="cv-pend"></section>
  <?php cv_aside_comum($venda, $hist, $cancelada); ?>
</aside>
</div>
<?php cv_barra_acoes(2, $venda, $e2ok, $cancelada, 'cv-salvar2', 'cv-concluir2', 'Concluir etapa 2', $e2ok ? 'Etapa 2 concluída em ' . h(substr((string)$venda['e2_em'], 0, 16)) . ' — alterações continuam possíveis (ficam no histórico)' : ''); ?>

<script>
(function(){
  const CARGOS = <?= json_encode(Distribuicao::CARGOS, JSON_UNESCAPED_UNICODE) ?>;
  const linhas0 = <?= json_encode($linhasJs, JSON_UNESCAPED_UNICODE) ?>;
  const conc0 = <?= json_encode($concJs, JSON_UNESCAPED_UNICODE) ?>;
  const padraoRecebem = <?= json_encode($padraoRecebem, JSON_UNESCAPED_UNICODE) ?>;
  const ultimos = <?= json_encode($ultimos ?: new stdClass(), JSON_UNESCAPED_UNICODE) ?>;
  const RET = ['adm', 'adm_bonus'];
  const {esc, brl, pc} = CV;
  const form = document.getElementById('cv-form2');
  let k = 0, ultimo = null;
  const optsCargo = sel => CARGOS.map(c => '<option' + (c === sel ? ' selected' : '') + '>' + c + '</option>').join('');

  function addLinha(bloco, l) {
    const tb = form.querySelector('[data-bloco="' + bloco + '"] tbody');
    const tr = document.createElement('tr'); tr.dataset.k = k++;
    const ret = RET.includes(bloco);
    tr.innerHTML = '<td><select data-f="cargo"><option value="">—</option>' + optsCargo(l.cargo || '') + '</select></td>'
      + (ret ? '<td><input data-f="obs" value="' + esc(l.obs || '') + '" placeholder="do que se trata (ex.: INTEGRAÇÃO QLV)"><span class="cv-sug" data-omie></span></td>'
             : '<td><input data-f="nome" list="cv-lista-pessoas" value="' + esc(l.nome || '') + '"><span class="cv-desc-linha" data-dsc></span><span class="cv-sug" data-omie></span></td>')
      + '<td><input data-f="pct" class="cv-num" inputmode="decimal" value="' + esc(l.pct || '') + '" placeholder="%"><span class="cv-sug" data-ult></span></td>'
      + '<td class="n" data-val>—</td><td><button type="button" class="cf-x" title="remover a linha">✕</button></td>';
    tr.querySelector('.cf-x').onclick = () => { tr.remove(); mudou(); };
    tr.querySelector('[data-f=pct]').addEventListener('blur', e => CV.numBlur(e.target, true));
    tb.appendChild(tr);
  }
  linhas0.forEach(l => addLinha(l.bloco, l));
  form.querySelectorAll('[data-add-linha]').forEach(b => b.onclick = () => { addLinha(b.closest('[data-bloco]').dataset.bloco, {}); mudou(); });

  /* ---------- conclusão do processo ---------- */
  const ativo = document.getElementById('cv-conc-ativo'), corpo = document.getElementById('cv-conc-corpo');
  const tbR = document.getElementById('cv-receb'), tbD = document.getElementById('cv-descs');
  function addReceb(x) {
    if (tbR.children.length >= 3) { alert('No máximo 3 pessoas.'); return; }
    const tr = document.createElement('tr');
    tr.innerHTML = '<td><input data-f="nome" list="cv-lista-pessoas" value="' + esc(x.nome || '') + '"><span class="cv-sug" data-omie></span></td><td><select data-f="cargo"><option value="">—</option>' + optsCargo(x.cargo || '') + '</select></td>'
      + '<td><input data-f="valor" class="cv-num" inputmode="decimal" value="' + esc(x.valor || '') + '"></td><td><button type="button" class="cf-x">✕</button></td>';
    tr.querySelector('.cf-x').onclick = () => { tr.remove(); mudou(); };
    tr.querySelector('[data-f=valor]').addEventListener('blur', e => CV.numBlur(e.target, false));
    tbR.appendChild(tr);
  }
  function addDesc(x) {
    const tr = document.createElement('tr'); tr.dataset.ref = x.ref || '';
    tr.innerHTML = '<td><select data-f="ref"></select></td><td><input data-f="valor" class="cv-num" inputmode="decimal" value="' + esc(x.valor || '') + '"></td><td><button type="button" class="cf-x">✕</button></td>';
    tr.querySelector('.cf-x').onclick = () => { tr.remove(); mudou(); };
    tr.querySelector('[data-f=valor]').addEventListener('blur', e => CV.numBlur(e.target, false));
    tbD.appendChild(tr); preencheRefs();
  }
  function refsComissao() { return ultimo ? ultimo.calc.linhas.filter(l => l.bloco === 'comissao' && l.nome) : []; }
  function preencheRefs() {
    const refs = refsComissao();
    tbD.querySelectorAll('tr').forEach(tr => {
      const sel = tr.querySelector('select'), atual = sel.value || tr.dataset.ref;
      sel.innerHTML = '<option value="">— escolha —</option>' + refs.map(l => '<option value="' + esc(l.ref) + '"' + (l.ref === atual ? ' selected' : '') + '>' + esc(l.cargo + ' — ' + l.nome) + ' (' + brl(l.bruto) + ')</option>').join('')
        + (atual && !refs.find(l => l.ref === atual) ? '<option value="' + esc(atual) + '" selected>' + esc(atual.replace('|', ' — ')) + ' (não está mais nas comissões)</option>' : '');
      tr.dataset.ref = sel.value;
    });
  }
  const fmt = n => n.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  const num = s => { s = String(s || '').replace(/[R$\s]/g, ''); if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.'); return Number(s) || 0; };
  function dividir(cargos) {
    const refs = refsComissao().filter(l => cargos.includes(l.cargo));
    const vistos = new Set(), alvo = refs.filter(l => !vistos.has(l.ref) && vistos.add(l.ref));
    if (!alvo.length) { alert('Não há ' + cargos.join(' / ') + ' preenchido nas comissões.'); return; }
    const total = num(document.getElementById('cv-conc-valor').value); const cent = Math.round(total * 100);
    const parte = Math.floor(cent / alvo.length), resto = cent - parte * alvo.length;
    tbD.innerHTML = '';
    alvo.forEach((l, i) => addDesc({ref: l.ref, valor: fmt((parte + (i < resto ? 1 : 0)) / 100)}));
    mudou();
  }
  form.querySelectorAll('[data-div]').forEach(b => b.onclick = () => dividir(b.dataset.div.split(',')));
  ativo.onchange = () => {
    corpo.style.display = ativo.checked ? '' : 'none';
    if (ativo.checked && !tbR.children.length) padraoRecebem.forEach(x => addReceb({...x, valor: fmt(x.valor)}));
    if (ativo.checked && !tbD.children.length) setTimeout(() => dividir(['CORRETOR']), 400);   // padrão: só o(s) corretor(es)
    mudou();
  };
  document.getElementById('cv-add-receb').onclick = () => { addReceb({}); mudou(); };
  document.getElementById('cv-add-desc').onclick = () => { addDesc({}); mudou(); };
  ativo.checked = conc0.ativo; corpo.style.display = conc0.ativo ? '' : 'none';
  document.getElementById('cv-conc-valor').value = conc0.valor; document.getElementById('cv-conc-desc').value = conc0.descricao;
  document.getElementById('cv-conc-valor').addEventListener('blur', e => CV.numBlur(e.target, false));
  conc0.recebem.forEach(addReceb); conc0.descontos.forEach(addDesc);

  /* ---------- coleta + prévia ---------- */
  function coleta() {
    const linhas = [];
    const val = (tr, f) => { const i = tr.querySelector('[data-f=' + f + ']'); return i ? i.value : ''; };
    form.querySelectorAll('[data-bloco]').forEach(sec => sec.querySelectorAll('tbody tr').forEach(tr => linhas.push({
      k: +tr.dataset.k, bloco: sec.dataset.bloco, cargo: val(tr, 'cargo'), nome: val(tr, 'nome'), obs: val(tr, 'obs'), pct: val(tr, 'pct')})));
    const rows = (tb, campos) => [...tb.querySelectorAll('tr')].map(tr => Object.fromEntries(campos.map(c => [c, tr.querySelector('[data-f=' + c + ']').value])));
    return {linhas, conclusao: {ativo: ativo.checked, valor: document.getElementById('cv-conc-valor').value, descricao: document.getElementById('cv-conc-desc').value,
            recebem: rows(tbR, ['nome', 'cargo', 'valor']), descontos: rows(tbD, ['ref', 'valor'])}};
  }
  function render(j) {
    ultimo = j;
    const porK = {}; j.calc.linhas.forEach(l => { if (l.k !== undefined) porK[l.k] = l; });
    const base = {comissao: j.c1.valor_final, bonus: j.c1.bonus_distribuicao, adm: j.c1.valor_final, adm_bonus: j.c1.bonus_distribuicao};
    form.querySelectorAll('[data-bloco]').forEach(sec => {
      const b = sec.dataset.bloco; sec.querySelector('[data-base]').textContent = brl(base[b]);
      sec.querySelectorAll('tbody tr').forEach(tr => {
        const l = porK[tr.dataset.k];
        tr.querySelector('[data-val]').textContent = l ? brl(l.valor) : '—';
        const dsc = tr.querySelector('[data-dsc]'); if (dsc) dsc.textContent = l && l.desconto ? 'bruto ' + brl(l.bruto) + ' − ' + brl(l.desconto) + ' (conclusão do processo)' : '';
        const om = tr.querySelector('[data-omie]');
        if (RET.includes(b)) om.textContent = 'recebe: ' + (j.empresa || '(empresa da venda)');
        else om.innerHTML = l && l.pessoa_id ? chipOmie(j.omie && j.omie[l.pessoa_id]) : '';
        const ul = tr.querySelector('[data-ult]'), u = l && l.pessoa_id ? ultimos[b + '|' + l.cargo + '|' + l.pessoa_id] : null, pin = tr.querySelector('[data-f=pct]');
        ul.innerHTML = u && u.pct !== pin.value ? 'último: ' + esc(u.pct) + '% (#' + u.venda + ') <button type="button" data-usar-pct="' + esc(u.pct) + '">usar</button>' : '';
      });
      sec.querySelector('[data-soma-pct]').textContent = pc(j.calc.pct[b]);
      sec.querySelector('[data-soma-val]').textContent = brl(j.calc.tot[b]);
    });
    const R = [['Comissões pagas' + (j.calc.recebem ? ' (com a conclusão do processo)' : ''), j.calc.comissoes], ['Bônus', j.calc.bonus],
               ['Comissões pagas + bônus (' + pc(j.calc.pct_pagos) + ')', j.calc.pagos, 't'], ['Saldo pra imobiliária (' + pc(j.calc.pct_saldo) + ')', j.calc.saldo, 't'],
               ['− Retenções para Imobiliária (' + pc(j.calc.pct_adm) + ')', j.calc.adm, 'sub'], ['Saldo final da imobiliária (' + pc(j.calc.pct_saldo_final) + ')', j.calc.saldo_final, 't']];
    document.getElementById('cv-res').innerHTML = '<tr class="sub"><td>Comissão + bônus da venda</td><td>' + brl(j.c1.comissao_mais_bonus) + '</td></tr>'
      + R.map(([r, x, c]) => '<tr class="' + (c || '') + '"><td>' + esc(r) + '</td><td>' + brl(x) + '</td></tr>').join('')
      + (j.c1.bonus > 0 ? '<tr class="sub"><td>Bônus distribuído</td><td>' + pc(j.calc.pct_bonus_total) + ' de 100%</td></tr>' : '');
    const rIds = j.recebem_ids || [];
    [...tbR.querySelectorAll('tr')].filter(tr => tr.querySelector('[data-f=nome]').value.trim() || tr.querySelector('[data-f=valor]').value.trim())
      .forEach((tr, i) => { const pid = rIds[i]; tr.querySelector('[data-omie]').innerHTML = pid ? chipOmie(j.omie && j.omie[pid]) : (tr.querySelector('[data-f=nome]').value.trim() ? '<span style="color:var(--clay)">⚠ não está no cadastro de Pessoas</span>' : ''); });
    document.getElementById('cv-pessoas').innerHTML = Object.entries(j.calc.por_pessoa).map(([n, x]) => '<tr><td>' + esc(n) + '</td><td>' + brl(x) + '</td></tr>').join('') || '<tr><td>—</td><td></td></tr>';
    const p = document.getElementById('cv-pend');
    p.innerHTML = (j.erros.length ? '<b class="e">Falta para concluir a etapa 2:</b><ul>' + j.erros.map(e => '<li class="e">' + esc(e) + '</li>').join('') + '</ul>' : '<b style="color:var(--moss)">✔ Distribuição pronta para concluir</b>')
      + (j.avisos.length ? '<b class="a" style="display:block;margin-top:8px">Atenção:</b><ul>' + j.avisos.map(e => '<li class="a">' + esc(e) + '</li>').join('') + '</ul>' : '');
    preencheRefs();
  }
  const chaveN = s => String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toUpperCase().replace(/\s+/g, ' ').trim();
  function chipOmie(o) {
    if (!o) return '';
    const ok = o.status === 'ok', cls = ok ? 'color:var(--moss)' : (o.status === 'so_nome' ? 'color:#7a5a00' : 'color:var(--clay)');
    return '<span style="' + cls + '">' + (ok ? '✔ paga para: ' : '⚠ ') + esc(ok ? o.paga_para + (o.doc ? ' · ' + o.doc : '') : o.msg) + '</span>';
  }
  form.addEventListener('click', ev => { const b = ev.target.closest('[data-usar-pct]'); if (!b) return; const i = b.closest('td').querySelector('[data-f=pct]'); i.value = b.dataset.usarPct; b.parentNode.innerHTML = ''; mudou(); });
  let t = null, seq = 0;
  async function previa() { const n = ++seq; const j = await CV.api({acao:'previa2', e2: coleta()}); if (n === seq) { if (j.ok) render(j); else CV.msg('erro', CV.lista(j.erros || [])); } }
  function mudou() { CV.sujo = true; clearTimeout(t); t = setTimeout(previa, 350); }
  form.addEventListener('input', mudou); form.addEventListener('change', mudou);
  if (CV.somenteLeitura) form.querySelectorAll('input,select,textarea,button').forEach(x => x.disabled = true);
  previa();

  async function salvar(acao) {
    document.querySelectorAll('.cv-botoes .btn').forEach(b => b.disabled = true);
    const j = await CV.api({acao, e2: coleta()});
    document.querySelectorAll('.cv-botoes .btn').forEach(b => b.disabled = false);
    if (j.ok) { CV.rev = j.rev; return CV.vai(j.msg + (j.avisos && j.avisos.length ? ' Atenção: ' + j.avisos.join(' · ') : '')); }
    CV.msg('erro', '<b>' + esc(j.msg || 'Não foi possível gravar:') + '</b>' + CV.lista(j.erros || []));
  }
  const bs = document.getElementById('cv-salvar2'); if (bs) bs.onclick = () => salvar('salvar2');
  const bc = document.getElementById('cv-concluir2'); if (bc) bc.onclick = () => salvar('concluir2');
})();
</script>
