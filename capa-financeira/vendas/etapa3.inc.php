<?php
/* capa-financeira/vendas/etapa3.inc.php — etapa 3 (fluxo de pagamento do cliente + recebidos + travas).
   Ao concluir, gera as contas a receber na Capa Financeira (em revisão) para conferência e envio ao Omie. */
declare(strict_types=1);

$e3 = $v['e3'] ?? Fluxo::vazio();
$e3ok = $v['e3_status'] !== 'rascunho';
$contas = cv_contas((string)$v['empresa']);
$compJs = array_map(fn($c) => ['nome' => $c['nome']], $v['compradores']);
$parcJs = array_map(fn($p) => ['data' => $p['data_contrato'] ?? $p['data'], 'valor' => $p['valor'] !== null ? number_format((float)$p['valor'], 2, ',', '.') : '', 'pagador' => $p['pagador'],
                               'outro_nome' => $p['outro_nome'], 'outro_doc' => $p['outro_doc'] ? Venda::docFormatado($p['outro_doc']) : '', 'conta' => $p['conta'], 'ref' => $p['ref'],
                               'recebido' => $p['recebido'], 'arras' => $p['arras']], Fluxo::ordenadas($e3['parcelas']));
$empresaNome = '';
foreach (cf_config('empresas', []) as $em) if (($em['id'] ?? '') === $v['empresa']) $empresaNome = (string)$em['nome'];
$sugestao = trim((string)$v['construtora']) !== '' && $v['empresa'] ? cv_construtora_sugestao((string)$v['construtora'], (string)$v['empresa']) : null;
$qpJs = ['quem_paga' => $e3['quem_paga'] ?? null, 'construtora_cad' => $e3['construtora_cad'] ?? null, 'construtora' => (string)$v['construtora'], 'empresa' => (string)$v['empresa'],
         'empresaNome' => $empresaNome, 'sugestao' => $sugestao, 'temCapa' => (bool)$v['capa_id']];
?>
<style>.cv-qp-grade{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px}
.cv-qp-op{display:flex;gap:8px;align-items:flex-start;border:2px solid var(--line);border-radius:8px;padding:10px 12px;cursor:pointer;font-weight:600;font-size:14px;background:#fff}
.cv-qp-op small{font-weight:400;color:var(--mute)}.cv-qp-op.on{border-color:var(--moss);background:#f1f7f3}.cv-qp-op input{width:auto;margin:3px 0 0}
.cv-alca{cursor:grab;touch-action:none;color:var(--mute);user-select:none;text-align:center;font-weight:700;vertical-align:middle!important}tr.cv-arrastando td{background:#fff8e1;opacity:.7}</style>
<div class="cv-cols">
<form id="cv-form3" class="cv-f" autocomplete="off" onsubmit="return false">
  <?php if ($v['e1_status'] === 'rascunho'): ?><div class="aviso" style="margin-bottom:10px">A etapa 1 ainda não foi concluída — dá para preencher o fluxo, mas só dá para concluir depois dela.</div><?php endif; ?>
  <section class="card">
    <h2>Travas de pagamento</h2>
    <?php $antigo = Pagamentos::metodo($v) === 'antigo'; ?>
    <?php if ($antigo): ?>
    <p class="cf-dica" style="margin-top:0"><b>Método antigo</b>: os últimos 20% da comissão ficam sempre retidos até a entrega das chaves (etapa 100% travada, condição ENTREGA CHAVES). <b>Pré-Reserva</b>: comissão paga só até exatamente 50% (80% e 100% travados); bônus normal. Pagamento travado = data e condição obrigatórias na etapa 4.</p>
    <?php else: ?>
    <p class="cf-dica" style="margin-top:0"><b>Pré-Reserva</b>: comissão paga só até exatamente 50% (75% e 100% travados) e o bônus em 2 — metade na quitação, metade travada. <b>IR Futuro / abaixo de 70%</b>: comissão paga até exatamente 75% (100% travado); bônus normal. Pagamento travado = data e condição obrigatórias na etapa 4.</p>
    <?php endif; ?>
    <div class="cv-radio" style="flex-wrap:wrap">
      <?php foreach (Fluxo::TRAVAS as $k => $t): if ($antigo && $k !== 'PRE_RESERVA') continue; ?><label><input type="checkbox" name="trava" value="<?= $k ?>"<?= in_array($k, $e3['travas'], true) ? ' checked' : '' ?>> <?= h($t) ?></label><?php endforeach; ?>
    </div>
  </section>

  <section class="card">
    <div class="cv-bloco-h"><h2>Fluxo de pagamento do cliente</h2><small>tem que fechar em <b data-total-esp>—</b> (comissão + bônus)</small></div>
    <p class="cf-dica" style="margin-top:0">Cada linha vira uma conta a receber no Omie da <b><?= h($empresaNome ?: '(escolha a empresa na etapa 1)') ?></b>, em aberto — a baixa é dada lá. O que já entrou: marque “recebida” (com a data em que entrou) e, se for sinal, “arras”.</p>
    <div id="cv-qp-barra" class="cv-comp" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 10px"></div>
    <table class="cv-tab" id="cv-parc">
      <thead><tr><th style="width:22px" title="arraste para reorganizar"></th><th style="width:140px">Data</th><th style="width:120px">Valor</th><th style="min-width:200px">Quem paga</th><th style="width:130px">Conta</th><th style="width:110px">Referência</th><th style="width:70px">Recebida</th><th style="width:55px">Arras</th><th style="width:105px" class="n">% / acum.</th><th style="width:30px"></th></tr></thead>
      <tbody></tbody>
      <tfoot><tr><td></td><td style="text-align:right">total</td><td class="n" data-soma></td><td colspan="5" data-dif></td><td class="n" data-soma-pct></td><td></td></tr></tfoot>
    </table>
    <div class="cv-botoes" style="margin-top:8px">
      <button type="button" class="cv-mini-btn" id="cv-add-parc">+ parcela</button>
      <button type="button" class="cv-mini-btn" id="cv-gerar-parc">gerar várias parcelas…</button>
      <button type="button" class="cv-mini-btn" id="cv-completar">ajustar a última com a diferença</button>
      <button type="button" class="cv-mini-btn" id="cv-ord-data" title="volta para a ordem por data">ordenar por data</button>
      <span class="cf-dica" id="cv-ord-info" style="margin:0 0 0 6px"></span>
    </div>
    <div id="cv-gerador" class="cv-comp" style="display:none;margin-top:10px">
      <div class="cv-comp-top"><span>Gerar várias parcelas</span><button type="button" class="cf-x" data-ger-fechar title="fechar">✕</button></div>
      <div class="cv-g cv-g4">
        <label>Quantidade de parcelas<input data-g="n" type="number" min="1" max="120" value="12"></label>
        <label>Valor de cada parcela<input data-g="valor" class="cv-num" inputmode="decimal" placeholder="0,00"></label>
        <label>Data da 1ª parcela<input data-g="data" type="date"></label>
        <label>Periodicidade
          <select data-g="passo">
            <option value="1">mensal (todo mês)</option><option value="2">a cada 2 meses</option><option value="3">trimestral (a cada 3)</option>
            <option value="4">a cada 4 meses</option><option value="6">semestral (a cada 6)</option><option value="12">anual (a cada 12)</option>
          </select>
        </label>
        <label>Nº da 1ª na referência<input data-g="ref0" type="number" min="0" placeholder="ex.: 2 → PARCELA 02"></label>
        <label>Pular de quantos em quantos na referência<input data-g="refpasso" type="number" min="1" value="1" title="ex.: parcelas 02, 04, 06 → 2"></label>
        <label>Quem paga<select data-g="pagador"></select>
          <span data-g-outro style="display:none"><input data-g="outro_nome" placeholder="nome completo" style="margin-top:4px"><input data-g="outro_doc" placeholder="CPF ou CNPJ" style="margin-top:4px"></span></label>
        <label>Conta<select data-g="conta"></select></label>
      </div>
      <p class="cf-dica" data-g-previa style="margin:8px 0 0"></p>
      <div class="cv-botoes" style="margin-top:8px"><button type="button" class="btn" data-ger-ok>Adicionar estas parcelas</button><button type="button" class="btn cf-btn-sec" data-ger-fechar>Cancelar</button></div>
    </div>
  </section>

  <section class="card">
    <label style="display:flex;gap:8px;align-items:center;font-size:14px;font-weight:600"><input type="checkbox" id="cv-conferido" style="width:auto;margin:0"<?= !empty($e3['conferido']) ? ' checked' : '' ?>> Conferi o fluxo com o contrato e o termo de honorários</label>
    <?php if (!empty($e3['conferido_por'])): ?><p class="cf-dica">Conferido por <?= h($e3['conferido_por']) ?> em <?= h((string)($e3['conferido_em'] ?? '')) ?>.</p><?php endif; ?>
  </section>
</form>

<aside class="cv-lado">
  <section class="card"><h2>Resumo do fluxo</h2><table class="cv-calc" id="cv-res3"></table></section>
  <section class="card"><h2>Gatilhos (prévia da etapa 4)</h2><table class="cv-calc" id="cv-gat"></table>
    <p class="cf-dica">Quando o que o cliente pagou atinge <?= $antigo ? '50%, 80%' : '50%, 75%' ?> e 100% da comissão base dos corretores.</p></section>
  <section class="card cv-pend" id="cv-pend"></section>
  <?php if ($v['capa_id']): ?><section class="card"><p class="cf-dica" style="margin:0">Contas a receber na <a href="/capa-financeira/revisar.php?id=<?= (int)$v['capa_id'] ?>">capa #<?= (int)$v['capa_id'] ?></a> (revisar, confirmar e enviar pela Capa Financeira). Alterar as parcelas gera uma nova versão da capa.</p></section><?php endif; ?>
  <?php cv_aside_comum($venda, $hist, $cancelada); ?>
</aside>
</div>
<?php cv_barra_acoes(3, $venda, $e3ok, $cancelada, 'cv-salvar3', 'cv-concluir3', 'Concluir e gerar contas a receber', $e3ok ? 'Etapa 3 concluída' : ''); ?>

<script>
(function(){
  const comps = <?= json_encode($compJs, JSON_UNESCAPED_UNICODE) ?>;
  const parc0 = <?= json_encode($parcJs, JSON_UNESCAPED_UNICODE) ?>;
  const contas = <?= json_encode($contas['lista'], JSON_UNESCAPED_UNICODE) ?>, contaPadrao = <?= json_encode($contas['padrao'], JSON_UNESCAPED_UNICODE) ?>;
  const dataVenda = <?= json_encode((string)$v['data_venda']) ?>, DIAS_ARRAS = <?= Fluxo::DIAS_ARRAS ?>;
  const {esc, brl, pc} = CV;
  const form = document.getElementById('cv-form3'), tb = document.querySelector('#cv-parc tbody');
  let k = 0, ultimo = null;
  const fmt = n => n.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  const num = s => { s = String(s || '').replace(/[R$\s]/g, ''); if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.'); return Number(s) || 0; };
  const addDias = (iso, n) => { const d = new Date(iso + 'T12:00:00'); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10); };

  /* ---------- quem paga as parcelas ---------- */
  const QP = <?= json_encode($qpJs, JSON_UNESCAPED_UNICODE) ?>;
  let quemPaga = QP.quem_paga, construtoraCad = QP.construtora_cad;
  const nomeCons = () => construtoraCad ? construtoraCad.nome : (QP.construtora || 'construtora');
  const rotPag = v => v === 'construtora' ? 'Construtora — ' + nomeCons() : v === 'outro' ? 'Outro pagador' : /^c\d+$/.test(v || '') ? 'Comprador ' + (+v.slice(1) + 1) + ' — ' + ((comps[+v.slice(1)] || {}).nome || '?') : '—';
  const padraoPag = () => quemPaga ? (quemPaga.modo === 'construtora' ? 'construtora' : quemPaga.pagador || 'c0') : 'c0';
  function optsPag(sel) {
    return comps.map((c, i) => '<option value="c' + i + '"' + (sel === 'c' + i ? ' selected' : '') + '>Comprador ' + (i + 1) + ' — ' + esc(c.nome) + '</option>').join('')
      + '<option value="construtora"' + (sel === 'construtora' ? ' selected' : '') + '>Construtora — ' + esc(nomeCons()) + ' (repasse +7 dias)</option>'
      + '<option value="outro"' + (sel === 'outro' ? ' selected' : '') + '>Outro pagador (parente, empresa…)</option>';
  }
  function addParc(p) {
    const tr = document.createElement('tr'); tr.dataset.k = k++;
    const conta = p.conta || contaPadrao;
    tr.innerHTML = '<td class="cv-alca" title="arraste para reorganizar">⋮⋮</td><td><input type="date" data-f="data" value="' + esc(p.data || '') + '"><small data-repasse style="display:block;color:var(--moss);font-weight:600"></small></td>'
      + '<td><input data-f="valor" class="cv-num" inputmode="decimal" value="' + esc(p.valor || '') + '"></td>'
      + '<td><select data-f="pagador">' + optsPag(p.pagador || padraoPag()) + '</select><div data-outro style="display:none;margin-top:4px"><input data-f="outro_nome" placeholder="nome completo" value="' + esc(p.outro_nome || '') + '" style="margin-bottom:4px"><input data-f="outro_doc" placeholder="CPF ou CNPJ" value="' + esc(p.outro_doc || '') + '"><button type="button" class="cv-mini-btn" data-outro-omie style="margin:4px 0 0">🔍 buscar no Omie</button></div></td>'
      + '<td><select data-f="conta">' + contas.map(c => '<option' + (c === conta ? ' selected' : '') + '>' + esc(c) + '</option>').join('') + (contas.includes(conta) ? '' : '<option selected>' + esc(conta) + '</option>') + '</select></td>'
      + '<td><input data-f="ref" value="' + esc(p.ref || '') + '" placeholder="PARCELA 01"></td>'
      + '<td style="text-align:center"><input type="checkbox" data-f="recebido"' + (p.recebido ? ' checked' : '') + ' style="width:auto;margin:6px auto"></td>'
      + '<td style="text-align:center"><input type="checkbox" data-f="arras"' + (p.arras ? ' checked' : '') + ' style="width:auto;margin:6px auto"></td>'
      + '<td class="n" data-pct>—</td><td><button type="button" class="cf-x">✕</button></td>';
    const sel = tr.querySelector('[data-f=pagador]'), outro = tr.querySelector('[data-outro]');
    const mostra = () => outro.style.display = sel.value === 'outro' ? '' : 'none'; mostra(); sel.addEventListener('change', mostra);
    tr.querySelector('[data-outro-omie]').onclick = async () => {
      const c = await CFOmie.escolher({empresa: QP.empresa, empresaNome: QP.empresaNome, termo: tr.querySelector('[data-f=outro_nome]').value || '', doc: tr.querySelector('[data-f=outro_doc]').value, titulo: 'Quem paga esta parcela — cadastro no Omie'});
      if (c) { tr.querySelector('[data-f=outro_nome]').value = c.nome; tr.querySelector('[data-f=outro_doc]').value = c.doc; mudou(); }
    };
    tr.querySelector('.cf-x').onclick = () => { tr.remove(); mudou(); };
    tr.querySelector('[data-f=valor]').addEventListener('blur', e => CV.numBlur(e.target, false));
    const rec = tr.querySelector('[data-f=recebido]'), arr = tr.querySelector('[data-f=arras]');
    rec.addEventListener('change', () => {   // recebimento até 10 dias depois da venda: arras por padrão
      const d = tr.querySelector('[data-f=data]').value;
      if (rec.checked && d && dataVenda && d <= addDias(dataVenda, DIAS_ARRAS)) arr.checked = true;
      if (!rec.checked) arr.checked = false;
    });
    const pagSinal = () => {   // construtora repassa: a parcela do sinal é de quem pagou o sinal
      if (!(quemPaga && quemPaga.modo === 'construtora' && quemPaga.sinal)) return;
      const alvo = arr.checked ? quemPaga.sinal : 'construtora';
      if (sel.value !== alvo) { sel.innerHTML = optsPag(alvo); sel.dispatchEvent(new Event('change')); }
    };
    rec.addEventListener('change', pagSinal);
    arr.addEventListener('change', () => { if (arr.checked) rec.checked = true; pagSinal(); });
    tb.appendChild(tr);
  }
  parc0.forEach(addParc);
  if (!parc0.length) addParc({});
  document.getElementById('cv-add-parc').onclick = () => { addParc({}); mudou(); };   // linha nova entra no fim; ao preencher a data, reordena (se a ordem não for manual)
  /* gerador de parcelas: painel na própria página (não some ao trocar de aba) */
  const ger = document.getElementById('cv-gerador'), g = k => ger.querySelector('[data-g=' + k + ']');
  g('pagador').innerHTML = optsPag(padraoPag());
  const gOutro = ger.querySelector('[data-g-outro]');
  g('pagador').addEventListener('change', () => gOutro.style.display = g('pagador').value === 'outro' ? '' : 'none');
  g('conta').innerHTML = contas.map(c => '<option' + (c === contaPadrao ? ' selected' : '') + '>' + esc(c) + '</option>').join('');
  g('valor').addEventListener('blur', e => CV.numBlur(e.target, false));
  g('passo').addEventListener('change', () => { if (!g('refpasso').dataset.mexeu) g('refpasso').value = g('passo').value; previaGer(); });
  g('refpasso').addEventListener('input', () => g('refpasso').dataset.mexeu = '1');
  function datasGer() {
    const n = parseInt(g('n').value, 10), passo = parseInt(g('passo').value, 10) || 1, d0 = g('data').value;
    if (!n || n < 1 || n > 120 || !/^\d{4}-\d{2}-\d{2}$/.test(d0)) return [];
    const [y, m, dia] = d0.split('-').map(Number), out = [];
    for (let i = 0; i < n; i++) {
      const base = new Date(y, m - 1 + i * passo, 1), ult = new Date(base.getFullYear(), base.getMonth() + 1, 0).getDate();
      out.push(base.getFullYear() + '-' + String(base.getMonth() + 1).padStart(2, '0') + '-' + String(Math.min(dia, ult)).padStart(2, '0'));
    }
    return out;
  }
  function refGer(i) { const r0 = parseInt(g('ref0').value, 10), rp = parseInt(g('refpasso').value, 10) || 1; return r0 > 0 ? 'PARCELA ' + String(r0 + i * rp).padStart(2, '0') : ''; }
  function previaGer() {
    const ds = datasGer(), v = num(g('valor').value), el = ger.querySelector('[data-g-previa]');
    if (!ds.length) { el.textContent = 'Preencha a quantidade e a data da 1ª parcela.'; return; }
    const br = d => d.split('-').reverse().join('/');
    el.innerHTML = ds.length + ' parcela(s)' + (v ? ' de ' + brl(v) + ' = <b>' + brl(v * ds.length) + '</b>' : '') + ': ' + esc(br(ds[0])) + (ds.length > 1 ? ', ' + esc(br(ds[1])) : '') + (ds.length > 2 ? ' … ' + esc(br(ds[ds.length - 1])) : '')
      + (refGer(0) ? ' · referências ' + esc(refGer(0)) + (ds.length > 1 ? ' … ' + esc(refGer(ds.length - 1)) : '') : '');
  }
  ger.addEventListener('input', previaGer); ger.addEventListener('change', previaGer);
  document.getElementById('cv-gerar-parc').onclick = () => { ger.style.display = ''; g('n').focus(); previaGer(); };
  ger.querySelectorAll('[data-ger-fechar]').forEach(b => b.onclick = () => ger.style.display = 'none');
  ger.querySelector('[data-ger-ok]').onclick = () => {
    const ds = datasGer(), v = num(g('valor').value);
    if (!ds.length) { CV.alerta('Preencha a quantidade e a data da 1ª parcela.'); return; }
    if (!v) { CV.alerta('Informe o valor de cada parcela.'); return; }
    [...tb.querySelectorAll('tr')].forEach(tr => { if (!tr.querySelector('[data-f=data]').value && !tr.querySelector('[data-f=valor]').value) tr.remove(); });
    if (g('pagador').value === 'outro' && (!g('outro_nome').value.trim() || !g('outro_doc').value.trim())) { CV.alerta('Informe o nome e o CPF/CNPJ de quem paga.'); return; }
    ds.forEach((d, i) => addParc({data: d, valor: fmt(v), ref: refGer(i), pagador: g('pagador').value, outro_nome: g('outro_nome').value.toUpperCase().trim(), outro_doc: g('outro_doc').value, conta: g('conta').value}));
    ger.style.display = 'none';
    if (!manual) ordenaPorData();
    mudou();
  };
  document.getElementById('cv-completar').onclick = () => {
    if (!ultimo) return; const rows = [...tb.querySelectorAll('tr')]; if (!rows.length) return;
    const dif = ultimo.calc.diferenca; if (Math.abs(dif) < 0.005) { CV.alerta('O fluxo já fecha.'); return; }
    const i = rows[rows.length - 1].querySelector('[data-f=valor]'); const nv = num(i.value) - dif;
    if (nv <= 0) { CV.alerta('A diferença é maior que a última parcela.'); return; }
    i.value = fmt(nv); mudou();
  };

  /* ---------- ordem: por data por padrão; arrastar pela alça (⋮⋮) muda para ordem manual ---------- */
  let manual = <?= !empty($e3['ordem_manual']) ? 'true' : 'false' ?>;
  const info = document.getElementById('cv-ord-info');
  const mostraOrdem = () => info.textContent = manual ? 'ordem manual (arrastada) — os gatilhos continuam calculados pela data' : 'ordenado por data';
  function ordenaPorData() {
    const rows = [...tb.querySelectorAll('tr')];
    rows.map((tr, i) => [tr.querySelector('[data-f=data]').value || '9999-99-99', i, tr]).sort((a, b) => a[0] < b[0] ? -1 : a[0] > b[0] ? 1 : a[1] - b[1]).forEach(x => tb.appendChild(x[2]));
  }
  document.getElementById('cv-ord-data').onclick = () => { manual = false; ordenaPorData(); mostraOrdem(); mudou(); };
  // reordena por data só quando a pessoa termina a linha (sai dela com data e valor preenchidos — e nome/CPF se for outro pagador),
  // para a linha não "pular" de lugar no meio do preenchimento
  const linhaCompleta = tr => tr.querySelector('[data-f=data]').value && num(tr.querySelector('[data-f=valor]').value) > 0
    && (tr.querySelector('[data-f=pagador]').value !== 'outro' || (tr.querySelector('[data-f=outro_nome]').value.trim() && tr.querySelector('[data-f=outro_doc]').value.trim()));
  tb.addEventListener('focusout', ev => {
    const tr = ev.target.closest('tr'); if (!tr || manual) return;
    setTimeout(() => { if (!tr.contains(document.activeElement) && linhaCompleta(tr)) ordenaPorData(); }, 0);
  });
  // arrastar com ponteiro (mouse ou toque) pela alça ⋮⋮ — sem depender do drag-and-drop nativo
  let arrastando = null;
  tb.addEventListener('pointerdown', ev => {
    const alca = ev.target.closest('.cv-alca'); if (!alca || CV.somenteLeitura) return;
    ev.preventDefault(); arrastando = alca.closest('tr'); arrastando.classList.add('cv-arrastando');
    alca.setPointerCapture(ev.pointerId);
  });
  tb.addEventListener('pointermove', ev => {
    if (!arrastando) return;
    for (const tr of tb.querySelectorAll('tr')) {
      if (tr === arrastando) continue;
      const r = tr.getBoundingClientRect();
      if (ev.clientY >= r.top && ev.clientY <= r.bottom) { tb.insertBefore(arrastando, ev.clientY < r.top + r.height / 2 ? tr : tr.nextSibling); break; }
    }
  });
  const solta = () => { if (!arrastando) return; arrastando.classList.remove('cv-arrastando'); arrastando = null; manual = true; mostraOrdem(); mudou(); };
  tb.addEventListener('pointerup', solta); tb.addEventListener('pointercancel', solta);
  if (!manual) ordenaPorData();
  mostraOrdem();

  /* modal "Quem vai pagar as parcelas?" — obrigatório com o fluxo vazio; depois, "trocar quem paga em todas as parcelas" */
  function barraQP() {
    const b = document.getElementById('cv-qp-barra');
    let txt;
    if (quemPaga) {
      txt = '<b>Quem paga as parcelas:</b> ' + esc(quemPaga.modo === 'construtora' ? 'a construtora (' + nomeCons() + ') — repasse 7 dias depois do cliente, próximo dia útil' : rotPag(quemPaga.pagador))
        + (quemPaga.modo === 'construtora' ? ' · <b>sinal:</b> ' + esc(quemPaga.sinal === 'construtora' ? 'pela construtora' : rotPag(quemPaga.sinal)) : '');
    } else txt = '<b>Quem paga as parcelas:</b> definido parcela a parcela';
    b.innerHTML = '<span style="font-size:13.5px">' + txt + '</span>' + (CV.somenteLeitura ? '' : '<button type="button" class="cv-mini-btn" id="cv-qp-trocar">trocar quem paga em todas as parcelas…</button>');
    const bt = document.getElementById('cv-qp-trocar'); if (bt) bt.onclick = () => modalQP(false);
  }
  function modalQP(obrigatorio) {
    return new Promise(res => {
      const sug = QP.sugestao;
      let modo = quemPaga ? quemPaga.modo : (sug ? (sug.modo === 'construtora' ? 'construtora' : sug.modo === 'cliente' ? 'comprador' : '') : '');
      let pag = quemPaga && /^c\d+$/.test(quemPaga.pagador || '') ? quemPaga.pagador : 'c0';
      let sinal = quemPaga && quemPaga.sinal ? quemPaga.sinal : (sug && sug.sinal === 'construtora' ? 'construtora' : 'c0');
      let cad = construtoraCad || (sug && sug.cadastro) || null;
      const tro = [...tb.querySelectorAll('tr')].find(tr => tr.querySelector('[data-f=pagador]').value === 'outro');
      let outro = tro ? {nome: tro.querySelector('[data-f=outro_nome]').value, doc: tro.querySelector('[data-f=outro_doc]').value} : null;
      const m = document.createElement('div'); m.className = 'cv-modal'; m.style.zIndex = 800;
      const opc = (nome, val, atual, rot) => '<label class="cv-qp-op' + (val === atual ? ' on' : '') + '"><input type="radio" name="' + nome + '" value="' + esc(val) + '"' + (val === atual ? ' checked' : '') + '> <span>' + rot + '</span></label>';
      function desenha() {
        const comp = comps.map((c, i) => 'c' + i);
        m.innerHTML = '<div style="max-width:700px"><h2 style="margin-top:0">Quem vai pagar as parcelas?</h2>'
          + (sug ? '<p class="cv-msg ok" style="margin:6px 0 12px">⭐ <b>Sugestão:</b> ' + esc(sug.texto) + '</p>' : '<p class="cf-dica">Sem histórico desta construtora ainda — a resposta passa a ser a sugestão nas próximas vendas dela.</p>')
          + '<div class="cv-qp-grade">' + comp.map(v => opc('qp', 'comprador:' + v, modo === 'comprador' ? 'comprador:' + pag : '', 'Comprador ' + (+v.slice(1) + 1) + '<br><small>' + esc(comps[+v.slice(1)].nome) + '</small>')).join('')
          + opc('qp', 'construtora', modo, 'A construtora<br><small>' + esc(QP.construtora || '(sem construtora na etapa 1)') + ' — recebe do cliente e repassa</small>')
          + opc('qp', 'outro', modo, 'Outro<br><small>parente, empresa…</small>') + '</div>'
          + (modo === 'construtora' ? '<div class="cv-comp" style="margin-top:12px"><b>Cadastro da construtora no Omie da ' + esc(QP.empresaNome) + '</b><br>'
              + (cad ? '<span class="cv-chip ok">' + esc(cad.nome) + ' · ' + esc(cad.doc || 'sem documento') + '</span> ' : '<span class="cv-chip mal">não escolhido</span> ')
              + '<button type="button" class="cv-mini-btn" data-cad>' + (cad ? 'trocar' : '🔍 escolher / cadastrar no Omie') + '</button>'
              + '<p class="cf-dica" style="margin:6px 0 0">Às vezes quem paga é a SPE do empreendimento (outro CNPJ) — escolha o cadastro certo.</p>'
              + '<p style="margin:12px 0 4px"><b>De quem veio o sinal de negócio (arras)?</b></p><div class="cv-qp-grade">'
              + comp.map(v => opc('sinal', v, sinal, 'Comprador ' + (+v.slice(1) + 1) + '<br><small>' + esc(comps[+v.slice(1)].nome) + '</small>')).join('')
              + opc('sinal', 'construtora', sinal, 'Da construtora') + '</div>'
              + '<p class="cf-dica" style="margin:6px 0 0">Datas: digite a do quadro resumo do contrato — a conta a receber vai para 7 dias depois (próximo dia útil).</p></div>' : '')
          + (modo === 'outro' ? '<div class="cv-comp" style="margin-top:12px"><b>Quem paga</b><br>' + (outro && outro.nome ? '<span class="cv-chip ok">' + esc(outro.nome) + ' · ' + esc(outro.doc) + '</span> ' : '<span class="cv-chip mal">não escolhido</span> ')
              + '<button type="button" class="cv-mini-btn" data-outro>🔍 buscar / cadastrar no Omie</button></div>' : '')
          + (!obrigatorio && QP.temCapa ? '<p class="cf-dica" style="margin-top:10px">Parcelas que já foram ao Omie: a troca entra em <b>Exportar p/ Omie → Alterações depois da exportação</b>, onde dá para aplicar no Omie pela API (títulos já baixados ficam para ajuste manual).</p>' : '')
          + '<div class="cv-botoes" style="margin-top:14px"><button type="button" class="btn" data-ok>' + (obrigatorio ? 'Confirmar e preencher as parcelas' : 'Trocar em todas as parcelas') + '</button>'
          + (obrigatorio ? '<a class="btn cf-btn-sec" href="/capa-financeira/vendas/editar.php?id=' + CV.id + '&etapa=2">← voltar à etapa 2</a>' : '<button type="button" class="btn cf-btn-sec" data-x>Cancelar</button>') + '</div></div>';
        m.querySelectorAll('input[name=qp]').forEach(r => r.onchange = () => { const v = r.value; if (v.startsWith('comprador:')) { modo = 'comprador'; pag = v.split(':')[1]; } else modo = v; desenha(); });
        m.querySelectorAll('input[name=sinal]').forEach(r => r.onchange = () => { sinal = r.value; desenha(); });
        const bc = m.querySelector('[data-cad]'); if (bc) bc.onclick = async () => { const c = await CFOmie.escolher({empresa: QP.empresa, empresaNome: QP.empresaNome, termo: cad ? cad.nome : QP.construtora, titulo: 'Construtora que paga — cadastro no Omie'}); if (c) { cad = c; desenha(); } };
        const bo = m.querySelector('[data-outro]'); if (bo) bo.onclick = async () => { const c = await CFOmie.escolher({empresa: QP.empresa, empresaNome: QP.empresaNome, termo: outro ? outro.nome : '', titulo: 'Quem paga as parcelas — cadastro no Omie'}); if (c) { outro = c; desenha(); } };
        const bx = m.querySelector('[data-x]'); if (bx) bx.onclick = () => { m.remove(); res(false); };
        m.querySelector('[data-ok]').onclick = () => {
          if (!modo) { CV.alerta('Escolha quem paga as parcelas.'); return; }
          if (modo === 'construtora' && !cad) { CV.alerta('Escolha (ou cadastre) a construtora no Omie.'); return; }
          if (modo === 'outro' && !(outro && outro.nome && outro.doc)) { CV.alerta('Escolha (ou cadastre) no Omie quem paga — com CPF/CNPJ.'); return; }
          quemPaga = {modo, pagador: modo === 'construtora' ? 'construtora' : modo === 'outro' ? 'outro' : pag, sinal: modo === 'construtora' ? sinal : ''};
          if (modo === 'construtora') construtoraCad = cad;
          // aplica nas parcelas: arras → quem pagou o sinal; demais → quem paga
          tb.querySelectorAll('tr').forEach(tr => {
            const alvo = modo === 'construtora' && tr.querySelector('[data-f=arras]').checked ? sinal : quemPaga.pagador;
            const sel = tr.querySelector('[data-f=pagador]'); sel.innerHTML = optsPag(alvo); sel.dispatchEvent(new Event('change'));
            if (alvo === 'outro' && outro) { tr.querySelector('[data-f=outro_nome]').value = outro.nome; tr.querySelector('[data-f=outro_doc]').value = outro.doc; }
          });
          g('pagador').innerHTML = optsPag(padraoPag());
          if (modo === 'outro' && outro) { g('outro_nome').value = outro.nome; g('outro_doc').value = outro.doc; g('pagador').dispatchEvent(new Event('change')); }
          m.remove(); barraQP(); mudou(); res(true);
        };
      }
      desenha(); document.body.appendChild(m);
    });
  }
  barraQP();
  if (!CV.somenteLeitura && !quemPaga && !parc0.length) modalQP(true);

  function coleta() {
    const val = (tr, f) => { const i = tr.querySelector('[data-f=' + f + ']'); return i.type === 'checkbox' ? i.checked : i.value; };
    return {travas: [...form.querySelectorAll('[name=trava]:checked')].map(x => x.value), conferido: document.getElementById('cv-conferido').checked, ordem_manual: manual,
            quem_paga: quemPaga, construtora_cad: construtoraCad,
            parcelas: [...tb.querySelectorAll('tr')].map(tr => ({k: +tr.dataset.k, data: val(tr, 'data'), valor: val(tr, 'valor'), pagador: val(tr, 'pagador'), outro_nome: val(tr, 'outro_nome'),
                        outro_doc: val(tr, 'outro_doc'), conta: val(tr, 'conta'), ref: val(tr, 'ref'), recebido: val(tr, 'recebido'), arras: val(tr, 'arras')}))};
  }
  function render(j) {
    ultimo = j; const c = j.calc;
    form.querySelector('[data-total-esp]').textContent = brl(c.total_esperado);
    const porK = {}; c.linhas.forEach(l => porK[l.k] = l);
    tb.querySelectorAll('tr').forEach(tr => { const l = porK[tr.dataset.k];
      const rp = tr.querySelector('[data-repasse]'); if (rp) rp.textContent = l && l.repasse && l.venc ? '→ repasse ' + l.venc.split('-').reverse().join('/') : '';
      tr.querySelector('[data-pct]').innerHTML = l ? pc(l.pct) + '<br><small>' + pc(l.pct_acum) + '</small>' + (l.gatilhos.length ? '<br><span class="cv-chip ok">' + esc(l.gatilhos.join(' / ')) + '</span>' : '') : '—'; });
    document.querySelector('#cv-parc [data-soma]').textContent = brl(c.total_fluxo);
    document.querySelector('#cv-parc [data-soma-pct]').textContent = c.total_esperado ? pc(c.total_fluxo / c.total_esperado) : '';
    const dif = c.diferenca, df = document.querySelector('#cv-parc [data-dif]');
    df.innerHTML = Math.abs(dif) < 0.005 ? '<span style="color:var(--moss);font-weight:700">✔ fecha com a comissão + bônus</span>' : '<span style="color:var(--clay);font-weight:700">' + (dif > 0 ? 'sobra ' : 'falta ') + brl(Math.abs(dif)) + '</span>';
    document.getElementById('cv-res3').innerHTML = [['Comissão + bônus da venda', c.total_esperado, 't'], ['Total do fluxo', c.total_fluxo], ['Já recebido', c.recebido], ['· dos quais arras', c.arras, 'sub'], ['Valor ainda devido', c.devido, 't']]
      .map(([r, x, cl]) => '<tr class="' + (cl || '') + '"><td>' + r + '</td><td>' + brl(x) + '</td></tr>').join('');
    document.getElementById('cv-gat').innerHTML = c.gatilhos.length ? c.gatilhos.map(g => '<tr><td>' + esc(g.gatilhos.join(' / ')) + '</td><td>' + (g.quando ? g.quando.split('-').reverse().join('/') : '—') + ' (' + pc(g.pct_base) + ')</td></tr>').join('') : '<tr><td>—</td><td></td></tr>';
    const p = document.getElementById('cv-pend');
    p.innerHTML = (j.erros.length ? '<b class="e">Falta para concluir a etapa 3:</b><ul>' + j.erros.map(e => '<li class="e">' + esc(e) + '</li>').join('') + '</ul>' : '<b style="color:var(--moss)">✔ Fluxo pronto para concluir</b>')
      + (j.avisos.length ? '<b class="a" style="display:block;margin-top:8px">Atenção:</b><ul>' + j.avisos.map(e => '<li class="a">' + esc(e) + '</li>').join('') + '</ul>' : '');
  }
  let t = null, seq = 0;
  async function previa() { const n = ++seq; const j = await CV.api({acao:'previa3', e3: coleta()}); if (n === seq) { if (j.ok) render(j); else CV.msg('erro', CV.lista(j.erros || [])); } }
  function mudou() { CV.sujo = true; clearTimeout(t); t = setTimeout(previa, 350); }
  form.addEventListener('input', mudou); form.addEventListener('change', mudou);
  if (CV.somenteLeitura) form.querySelectorAll('input,select,textarea,button').forEach(x => x.disabled = true);
  previa();

  async function salvar(acao, confirmar) {
    document.querySelectorAll('.cv-botoes .btn').forEach(b => b.disabled = true);
    const j = await CV.api({acao, e3: coleta(), confirmar_omie: confirmar || []});
    document.querySelectorAll('.cv-botoes .btn').forEach(b => b.disabled = false);
    if (j.rev) CV.rev = j.rev;
    if (j.ok) return CV.vai(j.msg + (j.avisos && j.avisos.length ? ' Atenção: ' + j.avisos.join(' · ') : ''));
    if (j.confirmar) return modalOmie(j.confirmar, j.empresa);
    CV.msg('erro', '<b>' + esc(j.msg || 'Não foi possível gravar:') + '</b>' + CV.lista(j.erros || []));
  }
  function modalOmie(pend, emp) {
    const m = document.createElement('div'); m.className = 'cv-modal';
    m.innerHTML = '<div><h2 style="margin-top:0">Cadastrar no Omie da ' + esc(emp) + '?</h2><p class="cf-dica">O fluxo já foi salvo. As contas a receber ficam no Omie da ' + esc(emp) + ', e estes pagadores ainda não estão cadastrados lá:</p>'
      + pend.map(p => '<div class="cv-pend-omie"><b>' + esc(p.nome) + '</b> · ' + esc(p.doc_fmt) + '<br><span class="' + (p.status === 'so_nome' ? 'cv-chip mal' : 'cv-chip novo') + '">' + esc(p.msg) + '</span>'
          + '<label style="display:flex;gap:6px;margin-top:6px;font-size:13px"><input type="checkbox" data-doc="' + esc(p.documento) + '"' + (p.status === 'so_nome' ? '' : ' checked') + '> ' + (p.status === 'so_nome' ? 'É outra pessoa — cadastrar mesmo assim' : 'Cadastrar no Omie da ' + esc(emp)) + '</label></div>').join('')
      + '<div class="cv-botoes"><button type="button" class="btn" data-ok>Cadastrar e gerar as contas</button><button type="button" class="btn cf-btn-sec" data-x>Voltar</button></div></div>';
    document.body.appendChild(m);
    m.querySelector('[data-x]').onclick = () => { m.remove(); CV.sujo = false; location.href = '/capa-financeira/vendas/editar.php?id=' + CV.id + '&etapa=3'; };
    m.querySelector('[data-ok]').onclick = () => {
      const docs = [...m.querySelectorAll('input[data-doc]:checked')].map(x => x.dataset.doc);
      if (docs.length < pend.length) { CV.alerta('Marque todos para cadastrar — ou volte e corrija os dados.'); return; }
      m.remove(); salvar('concluir3', docs);
    };
  }
  const bs = document.getElementById('cv-salvar3'); if (bs) bs.onclick = () => salvar('salvar3');
  const bc = document.getElementById('cv-concluir3'); if (bc) bc.onclick = () => salvar('concluir3');
})();
</script>
