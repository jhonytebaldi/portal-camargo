/* alterador-omie/assets/ao.js — tela do Alterador Omie (sem dependências). */
(function () {
  'use strict';
  const raiz = document.getElementById('ao'); if (!raiz || !document.getElementById('ao-emp')) return;
  const CSRF = raiz.dataset.csrf;
  const $ = (s, el) => (el || document).querySelector(s), $$ = (s, el) => [...(el || document).querySelectorAll(s)];
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
  // o Omie devolve alguns textos já com entidades HTML ("C &amp; P"): só para exibir
  const dec = s => String(s == null ? '' : s).replace(/&(amp|lt|gt|quot|#39);/g, (x, k) => ({amp: '&', lt: '<', gt: '>', quot: '"', '#39': "'"}[k]));
  const brl = v => 'R$ ' + (+v || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const norm = s => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  const isoBr = s => { const m = String(s || '').match(/^(\d{2})\/(\d{2})\/(\d{4})/); return m ? m[3] + m[2] + m[1] : ''; };
  const dorme = ms => new Promise(r => setTimeout(r, ms));
  const hora = ts => ts ? new Date(ts * 1000).toLocaleString('pt-BR', {day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'}) : '—';

  /* ---------------- API ---------------- */
  async function get(params) {
    const r = await fetch('api.php?' + new URLSearchParams(params), {credentials: 'same-origin'});
    const j = await r.json().catch(() => ({erro: 'resposta inválida do servidor (HTTP ' + r.status + ')'}));
    if (j.erro) throw new Error(j.erro);
    return j;
  }
  async function post(dados) {
    const fd = new FormData(); fd.append('csrf', CSRF); fd.append('dados', JSON.stringify(dados));
    const r = await fetch('api.php', {method: 'POST', body: fd, credentials: 'same-origin'});
    const t = await r.text(); let j;
    try { j = JSON.parse(t); } catch (e) { throw new Error(r.status === 403 ? 'sessão expirada — recarregue a página' : 'resposta inválida do servidor (HTTP ' + r.status + ')'); }
    if (j.erro) throw new Error(j.erro);
    return j;
  }
  function aviso(html, tipo) {
    const m = $('#ao-msg');
    m.innerHTML = html ? '<div class="' + (tipo === 'erro' ? 'erro' : tipo === 'nota' ? 'ao-nota' : 'ok-box') + '">' + html + '</div>' : '';
  }

  /* ---------------- estado ---------------- */
  const S = {emp: $('#ao-emp').value, tipos: 'P', rows: [], porK: new Map(), estado: {}, cat: null, receitas: [], forn: {},
    sel: new Map(), selSeq: 0, filtros: {}, ord: {k: 'venc', dir: 1}, limite: 400, ops: [], opts: {ordem: 'venc', seq: {inicio: 1, passo: 1, digitos: 3, reinicia: 'nada'}, seqdata: {inicio: '', passo: 1, unidade: 'm', fmt: 'mm/aaaa'}},
    ultimoClique: null, filtrada: [], ocupado: false, sujo: false, rodada: null, rodando: false};

  const COLS = [
    {k: 'tipo', rot: 'Tipo', f: 'set', so: () => S.tipos === 'PR'},
    {k: 'prev', rot: 'Previsão', f: 'date'}, {k: 'venc', rot: 'Vencimento', f: 'date'},
    {k: 'fn', rot: 'Fornecedor / Cliente', f: 'text'}, {k: 'doc', rot: 'Nº documento', f: 'text'}, {k: 'nf', rot: 'Nota Fiscal', f: 'text'},
    {k: 'parc', rot: 'Parcela', f: 'text'}, {k: 'obs', rot: 'Observação', f: 'text'}, {k: 'valor', rot: 'Valor', f: 'num'},
    {k: 'catn', rot: 'Categoria', f: 'text'}, {k: 'ccn', rot: 'Conta corrente', f: 'set'}, {k: 'status', rot: 'Situação', f: 'set'},
    {k: 'emis', rot: 'Emissão', f: 'date'}, {k: 'ci', rot: 'Cód. integração', f: 'text'},
  ];
  const cols = () => COLS.filter(c => !c.so || c.so());

  /* ---------------- carga ---------------- */
  function tiposLista() { return S.tipos === 'PR' ? ['P', 'R'] : [S.tipos]; }

  async function atualizarLoop(tipo, modo, cadastros) {
    const rot = tipo === 'R' ? 'contas a receber' : 'contas a pagar';
    for (let i = 0; i < 200; i++) {
      const r = await post({a: 'atualizar', emp: S.emp, tipo, modo, cadastros: cadastros && i === 0 ? 1 : (cadastros ? 1 : 0)});
      aviso('<span class="ao-spin"></span>' + esc(r.msg || ('lendo ' + rot + '…')), 'nota');
      if (r.estado) S.estado[tipo] = r.estado;
      if (!r.continuar) return r;
      cadastros = cadastros && /cadastro/i.test(r.msg || '');
      await dorme(500);
    }
  }

  async function carregar(forcarTitulos) {
    S.ocupado = true; botoes();
    try {
      aviso('<span class="ao-spin"></span>carregando…', 'nota');
      const e = await get({a: 'estado', emp: S.emp});
      S.cat = e.catalogo; S.receitas = e.receitas || []; S.estado = e.cache;
      if (e.erro_catalogo) aviso('Não consegui ler categorias/contas do Omie: ' + esc(e.erro_catalogo), 'erro');
      let primeiro = true;
      for (const t of tiposLista()) if (!S.estado[t].tem || S.estado[t].carregando) { await atualizarLoop(t, 'auto', primeiro); primeiro = false; }
      if (!S.cat) { const e2 = await get({a: 'estado', emp: S.emp}); S.cat = e2.catalogo; }
      await carregarTitulos();
      aviso('');
    } catch (err) { aviso('Erro ao carregar: ' + esc(err.message), 'erro'); }
    S.ocupado = false; botoes(); receitasUI();
  }

  async function carregarTitulos() {
    const rows = []; S.forn = {};
    for (const t of tiposLista()) {
      const j = await get({a: 'titulos', emp: S.emp, tipo: t});
      S.estado[t] = j.estado; Object.assign(S.forn, j.forn);
      for (const r of j.rows) rows.push(r);
    }
    const cat = S.cat || {cat: [], cc: []};
    const catN = Object.fromEntries((cat.cat || []).map(c => [c.id, c.d])), ccN = Object.fromEntries((cat.cc || []).map(c => [c.id, c.d]));
    for (const r of rows) {
      r.k = r.tipo + ':' + r.id; r.fn = dec(S.forn[r.forn] || ('#' + r.forn)); r.obs = dec(r.obs); r.doc = dec(r.doc); r.nf = dec(r.nf);
      r.catn = r.cat + (catN[r.cat] ? ' ' + catN[r.cat] : ''); r.ccn = ccN[r.cc] || String(r.cc || '—');
      r._prev = isoBr(r.prev); r._venc = isoBr(r.venc); r._emis = isoBr(r.emis);
    }
    S.rows = rows; S.porK = new Map(rows.map(r => [r.k, r]));
    for (const k of [...S.sel.keys()]) if (!S.porK.has(k)) S.sel.delete(k);
    S.sujo = false;
    montarCabecalho(); aplicar(); quando();
  }

  function quando() {
    const ts = tiposLista().map(t => S.estado[t] || {});
    const at = Math.min(...ts.map(e => e.atualizada || 0)), co = Math.min(...ts.map(e => e.completa || 0));
    $('#ao-quando').innerHTML = at ? 'Última atualização: <b>' + hora(at) + '</b> · leitura completa: ' + hora(co) : 'ainda não lido';
  }
  function botoes() {
    const b = $('#ao-atualizar'), c = $('#ao-completa');
    const ts = tiposLista().map(t => S.estado[t] || {});
    const li = Math.max(0, ...ts.map(e => e.livre_incremental || 0)), lc = Math.max(0, ...ts.map(e => e.livre_completa || 0));
    b.disabled = S.ocupado || S.rodando || li > 0; b.textContent = li > 0 ? '↻ disponível em ' + li + 's' : '↻ Atualizar títulos';
    c.disabled = S.ocupado || S.rodando || lc > 0; c.title = lc > 0 ? 'liberada em ' + Math.ceil(lc / 60) + ' min (o Omie limita leituras completas)' : 'relê todos os títulos do Omie (necessário para sumir com títulos excluídos por lá); pode levar ~1 min';
  }
  setInterval(() => { let mud = false; for (const t in S.estado) { const e = S.estado[t]; if (e.livre_incremental > 0) { e.livre_incremental--; mud = true; } if (e.livre_completa > 0) { e.livre_completa--; mud = true; } } if (mud) botoes(); }, 1000);

  async function atualizar(modo) {
    if (S.ocupado) return;
    S.ocupado = true; botoes();
    const msgs = [];
    try {
      let primeiro = true;
      for (const t of tiposLista()) { const r = await atualizarLoop(t, modo, primeiro); primeiro = false; if (r && r.msg) msgs.push(r.msg); }
      await carregarTitulos();
      aviso(esc(msgs.join(' · ')));
    } catch (err) { aviso('Erro ao atualizar: ' + esc(err.message), 'erro'); }
    S.ocupado = false; botoes();
  }

  /* ---------------- tabela ---------------- */
  function montarCabecalho() {
    const th = $('#ao-tbl thead');
    let h1 = '<tr><th style="width:26px"><input type="checkbox" id="ao-ck-todos" title="selecionar/desmarcar todos os filtrados"></th>', h2 = '<tr class="ao-f"><th></th>';
    for (const c of cols()) {
      h1 += '<th class="ord' + (S.ord.k === c.k ? (S.ord.dir > 0 ? ' asc' : ' desc') : '') + '" data-k="' + c.k + '">' + esc(c.rot) + '</th>';
      const v = S.filtros[c.k];
      if (c.f === 'set') h2 += '<th><div class="fsel" data-k="' + c.k + '"><button type="button" class="' + (v && v.size ? 'on' : '') + '">' + (v && v.size ? v.size + ' marcado(s)' : 'todos') + '</button></div></th>';
      else h2 += '<th><input class="f' + (v ? ' on' : '') + '" data-k="' + c.k + '" value="' + esc(v || '') + '" placeholder="' + (c.f === 'date' ? 'dd/mm, de-até' : c.f === 'num' ? '>100, 50-90' : 'contém…') + '"></th>';
    }
    th.innerHTML = h1 + '</tr>' + h2 + '</tr>';
    $$('th.ord', th).forEach(t => t.addEventListener('click', () => { const k = t.dataset.k; S.ord = S.ord.k === k ? {k, dir: -S.ord.dir} : {k, dir: 1}; montarCabecalho(); aplicar(); }));
    let tmo; $$('input.f', th).forEach(i => i.addEventListener('input', () => { S.filtros[i.dataset.k] = i.value; i.classList.toggle('on', i.value.trim() !== ''); clearTimeout(tmo); tmo = setTimeout(aplicar, 160); }));
    $$('.fsel', th).forEach(d => d.querySelector('button').addEventListener('click', ev => { ev.stopPropagation(); abrirPop(d); }));
    $('#ao-ck-todos').addEventListener('change', e => { if (e.target.checked) selecionar(S.filtrada, true); else selecionar(S.filtrada, false); });
  }
  function abrirPop(d) {
    $$('.ao-pop').forEach(p => p.remove());
    const k = d.dataset.k, atual = S.filtros[k] || new Set();
    const vals = [...new Set(S.rows.map(r => String(r[k] ?? '')))].sort((a, b) => a.localeCompare(b, 'pt-BR'));
    const cont = {}; S.rows.forEach(r => { const v = String(r[k] ?? ''); cont[v] = (cont[v] || 0) + 1; });
    const p = document.createElement('div'); p.className = 'ao-pop';
    p.innerHTML = '<div class="ao-pop-top"><button type="button" class="ao-link" data-x="todos">todos</button><button type="button" class="ao-link" data-x="nenhum">limpar</button></div>' +
      vals.map(v => '<label><input type="checkbox" value="' + esc(v) + '"' + (atual.has(v) ? ' checked' : '') + '> ' + esc(k === 'tipo' ? (v === 'R' ? 'A receber' : 'A pagar') : (v || '(vazio)')) + ' <span class="cf-dica" style="margin:0">(' + cont[v] + ')</span></label>').join('');
    d.appendChild(p);
    const upd = () => { const s = new Set($$('input:checked', p).map(i => i.value)); S.filtros[k] = s; const b = d.querySelector('button'); b.textContent = s.size ? s.size + ' marcado(s)' : 'todos'; b.classList.toggle('on', s.size > 0); aplicar(); };
    p.addEventListener('change', upd);
    p.addEventListener('click', e => { e.stopPropagation(); const x = e.target.dataset.x; if (!x) return; $$('input', p).forEach(i => i.checked = x === 'todos' ? false : false); upd(); });
  }
  document.addEventListener('click', e => { if (!e.target.closest('.ao-pop')) $$('.ao-pop').forEach(p => p.remove()); });

  const numBR = s => { s = String(s || '').trim().replace(/r\$|\s/gi, ''); if (!s) return NaN; if (/,\d{1,2}$/.test(s) || (s.includes(',') && !s.includes('.'))) s = s.replace(/\./g, '').replace(',', '.'); return parseFloat(s); };
  function okNum(v, f) {
    f = f.trim(); if (!f) return true; let m;
    if ((m = f.match(/^(.+?)\s*(?:[-–]|\sa\s)\s*(.+)$/)) && !/^[<>=]/.test(f)) { const a = numBR(m[1]), b = numBR(m[2]); if (isNaN(a) || isNaN(b)) return true; return v >= Math.min(a, b) - 0.004 && v <= Math.max(a, b) + 0.004; }
    if ((m = f.match(/^(>=|<=|>|<|=)?\s*(.+)$/))) { const n = numBR(m[2]); if (isNaN(n)) return true; const op = m[1] || '='; return op === '>' ? v > n + 0.004 : op === '<' ? v < n - 0.004 : op === '>=' ? v >= n - 0.004 : op === '<=' ? v <= n + 0.004 : Math.abs(v - n) < 0.005; }
    return true;
  }
  const dIso = (s, fim) => {
    s = String(s).trim(); let m;
    if ((m = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/))) return (m[3].length === 2 ? '20' + m[3] : m[3]) + m[2].padStart(2, '0') + m[1].padStart(2, '0');
    if ((m = s.match(/^(\d{1,2})\/(\d{4})$/))) return m[2] + m[1].padStart(2, '0') + (fim ? '31' : '01');
    if ((m = s.match(/^(\d{1,2})\/(\d{1,2})$/))) return String(new Date().getFullYear()) + m[2].padStart(2, '0') + m[1].padStart(2, '0');
    if ((m = s.match(/^(\d{4})$/))) return m[1] + (fim ? '1231' : '0101');
    return null;
  };
  function okData(v, f) {
    f = f.trim(); if (!f) return true;
    if (/^(-|vazio|\(vazio\))$/i.test(f)) return v === '';
    let m = f.match(/^(.+?)\s*(?:[-–]|\sa\s|\s+até\s+)\s*(.+)$/);
    if (m) { const a = dIso(m[1], false), b = dIso(m[2], true); if (a && b) return v !== '' && v >= a && v <= b; }
    if ((m = f.match(/^(>=|<=|>|<)\s*(.+)$/))) { const d = dIso(m[2], m[1].includes('>') ? false : true); if (d) return v !== '' && (m[1] === '>' ? v > d : m[1] === '<' ? v < d : m[1] === '>=' ? v >= d : v <= d); }
    if (/^\d{1,2}\/\d{4}$/.test(f) || /^\d{4}$/.test(f)) { const a = dIso(f, false), b = dIso(f, true); return v >= a && v <= b; }
    const d = dIso(f, false); if (d) return v === d;
    return true;
  }
  function filtrar() {
    const fs = Object.entries(S.filtros).filter(([k, v]) => v instanceof Set ? v.size : String(v || '').trim());
    const tipoCol = Object.fromEntries(COLS.map(c => [c.k, c.f]));
    let l = S.rows.filter(r => {
      for (const [k, f] of fs) {
        const t = tipoCol[k];
        if (t === 'set') { if (!f.has(String(r[k] ?? ''))) return false; }
        else if (t === 'num') { if (!okNum(+r[k], f)) return false; }
        else if (t === 'date') { if (!okData(r['_' + k] || '', f)) return false; }
        else { const ff = f.trim(); if (/^(-|\(vazio\))$/i.test(ff)) { if (String(r[k] || '') !== '') return false; } else if (!norm(r[k]).includes(norm(ff))) return false; }
      }
      return true;
    });
    const k = S.ord.k, dir = S.ord.dir, tp = tipoCol[k];
    const val = r => tp === 'date' ? (r['_' + k] || '99999999') : tp === 'num' ? +r[k] : norm(r[k]);
    l.sort((a, b) => { const x = val(a), y = val(b); return (x < y ? -1 : x > y ? 1 : (a.id - b.id)) * dir; });
    return l;
  }
  function aplicar() { S.filtrada = filtrar(); render(); }
  function celula(c, r) {
    const v = r[c.k];
    switch (c.k) {
      case 'tipo': return '<td><span class="tp ' + r.tipo + '">' + (r.tipo === 'R' ? 'REC' : 'PAG') + '</span></td>';
      case 'valor': return '<td class="n">' + brl(v) + '</td>';
      case 'prev': case 'venc': case 'emis': return '<td class="d">' + esc(v) + '</td>';
      case 'status': return '<td><span class="st ' + esc(String(v).replace(/\s+/g, '-')) + '">' + esc(v) + '</span>' + (r.bol ? ' <span class="st" title="boleto gerado">boleto</span>' : '') + '</td>';
      case 'obs': return '<td class="obs" title="' + esc(String(v || '').replace(/\|/g, '\n')) + '"><div>' + esc(String(v || '').replace(/\|/g, ' ⏎ ')) + '</div></td>';
      case 'catn': return '<td title="' + esc(v) + '">' + esc(r.cat) + (r.ncat > 1 ? ' <span class="st">rateio</span>' : '') + '</td>';
      default: return '<td>' + esc(v) + '</td>';
    }
  }
  function render() {
    const tb = $('#ao-tbl tbody'), l = S.filtrada, cs = cols();
    const mostra = l.slice(0, S.limite);
    tb.innerHTML = mostra.map((r, i) => '<tr data-k="' + r.k + '" data-i="' + i + '"' + (S.sel.has(r.k) ? ' class="sel"' : '') + '><td><input type="checkbox"' + (S.sel.has(r.k) ? ' checked' : '') + '></td>' + cs.map(c => celula(c, r)).join('') + '</tr>').join('')
      || '<tr><td colspan="' + (cs.length + 1) + '" style="padding:18px;text-align:center;color:var(--mute)">' + (S.rows.length ? 'nenhum título com esses filtros' : 'sem títulos carregados') + '</td></tr>';
    $('#ao-mais').innerHTML = l.length > S.limite ? 'mostrando ' + S.limite + ' de ' + l.length + ' — <button type="button" class="ao-link" id="ao-mostrar-mais">mostrar mais 400</button> (a seleção e os filtros valem para todos, mesmo os não mostrados)' : '';
    const mm = $('#ao-mostrar-mais'); if (mm) mm.onclick = () => { S.limite += 400; render(); };
    contadores();
  }
  function contadores() {
    const l = S.filtrada, soma = l.reduce((s, r) => s + (+r.valor || 0), 0);
    let ns = 0, ss = 0; for (const k of S.sel.keys()) { const r = S.porK.get(k); if (r) { ns++; ss += +r.valor || 0; } }
    $('#ao-cont').innerHTML = '<b>' + l.length + '</b> de ' + S.rows.length + ' título(s) · ' + brl(soma) + (ns ? ' · <b>' + ns + ' selecionado(s)</b> · ' + brl(ss) : '');
    const ck = $('#ao-ck-todos'); if (ck) { const nf = l.filter(r => S.sel.has(r.k)).length; ck.checked = nf > 0 && nf === l.length; ck.indeterminate = nf > 0 && nf < l.length; }
    $('#ao-dock').hidden = ns === 0 || !$('#ao-alt').hidden;
    $('#ao-dock-n').textContent = ns + ' título(s) selecionado(s) · ' + brl(ss);
    $('#ao-alt-n').textContent = ns + ' título(s)';
  }
  function selecionar(lista, on) {
    for (const r of lista) { if (on) { if (!S.sel.has(r.k)) S.sel.set(r.k, ++S.selSeq); } else S.sel.delete(r.k); }
    if (lista.length > 300) { render(); return; }
    // só as linhas afetadas (não redesenha a tabela inteira)
    const tb = $('#ao-tbl tbody');
    for (const r of lista) { const tr = tb.querySelector('tr[data-k="' + r.k + '"]'); if (!tr) continue; tr.classList.toggle('sel', on); const ck = tr.querySelector('input[type=checkbox]'); if (ck) ck.checked = on; }
    contadores();
  }
  $('#ao-tbl tbody').addEventListener('click', e => {
    const tr = e.target.closest('tr[data-k]'); if (!tr) return;
    if (!e.shiftKey && e.target.tagName !== 'INPUT' && (window.getSelection() + '').length) return;   // deixa selecionar texto para copiar
    if (e.shiftKey) window.getSelection().removeAllRanges();
    const i = +tr.dataset.i, r = S.filtrada[i]; if (!r) return;
    const on = !S.sel.has(r.k);
    if (e.shiftKey && S.ultimoClique != null) { const a = Math.min(S.ultimoClique, i), b = Math.max(S.ultimoClique, i); selecionar(S.filtrada.slice(a, b + 1), on); }
    else selecionar([r], on);
    S.ultimoClique = i;
  });
  $('#ao-tbl tbody').addEventListener('mousedown', e => { if (e.shiftKey) e.preventDefault(); });   // Shift+clique = intervalo, não seleção de texto
  $('#ao-sel-filtrados').onclick = () => selecionar(S.filtrada, true);
  $('#ao-sel-limpar').onclick = () => { S.sel.clear(); render(); };
  $('#ao-filtros-limpar').onclick = () => { S.filtros = {}; montarCabecalho(); aplicar(); };

  /* ---------------- alterações ---------------- */
  const CAMPOS = {
    doc: ['Nº do documento', 'texto', 20], nf: ['Nota Fiscal', 'texto', 20], parc: ['Parcela', 'texto', 7], obs: ['Observação', 'texto', 0],
    prev: ['Previsão', 'data'], venc: ['Vencimento', 'data'], emis: ['Emissão', 'data'], reg: ['Registro', 'data'],
    cat: ['Categoria', 'cadastro'], cc: ['Conta corrente', 'cadastro'], forn: ['Fornecedor / Cliente', 'cadastro'], proj: ['Projeto', 'cadastro'],
    deps: ['Departamentos (rateio)', 'deps'], valor: ['Valor', 'valor'], pix: ['Forma de pagamento → Pix (chave do cadastro)', 'pix'],
  };
  const TOKENS = ['{seq}', '{n}', '{total}', '{data}', '{venc}', '{venc:mm/aaaa}', '{prev}', '{emis}', '{forn}', '{doc}', '{nf}', '{parc}', '{valor}', '{atual}'];

  function opcoesCampo() {
    const s = $('#ao-add-campo'), usados = new Set(S.ops.map(o => o.campo));
    s.innerHTML = '<option value="">+ adicionar campo para alterar…</option>' + Object.entries(CAMPOS).filter(([c]) => !usados.has(c) && !(c === 'pix' && S.tipos !== 'P') && !(c === 'cat' && S.tipos === 'PR'))
      .map(([c, d]) => '<option value="' + c + '">' + esc(d[0]) + '</option>').join('');
  }
  function novaOp(campo) {
    const t = CAMPOS[campo][1];
    if (t === 'texto') return {campo, modo: campo === 'obs' ? 'depois' : 'substituir', texto: '', sep: campo === 'obs' ? '' : ' ', procurar: ''};
    if (t === 'data') return {campo, modo: 'somar', n: 7, uteis: false, ajuste: 'manter', data: '', dia: 10, de: 'venc'};
    if (t === 'cadastro') return {campo, valor: ''};
    if (t === 'deps') return {campo, itens: [['', 100]]};
    if (t === 'valor') return {campo, modo: 'percent', n: 0};
    return {campo};
  }
  function selCatalogo(lista, filtro, valor) {
    return '<option value="">escolha…</option>' + (lista || []).filter(filtro).map(x => '<option value="' + esc(x.id) + '"' + (String(valor) === String(x.id) ? ' selected' : '') + '>' + esc((x.lanc !== undefined ? x.id + ' — ' : '') + x.d) + '</option>').join('');
  }
  function exemplo(op) {
    const ids = [...S.sel.entries()].sort((a, b) => a[1] - b[1]).map(([k]) => S.porK.get(k)).filter(Boolean);
    const r = ids[0]; if (!r || !op.texto) return '';
    const fd = (s, f) => { const m = String(s || '').match(/^(\d{2})\/(\d{2})\/(\d{4})/); if (!m) return ''; return (f || 'dd/mm/aaaa').replace(/aaaa/i, m[3]).replace(/aa/i, m[3].slice(2)).replace(/dd/i, m[1]).replace(/mm/i, m[2]); };
    const sq = S.opts.seq;
    const rr = op.texto.replace(/\{(\w+)(?::([^}]*))?\}/g, (x, k, a) => {
      k = k.toLowerCase();
      if (k === 'seq') return String(+sq.inicio || 1).padStart(a ? +a : (+sq.digitos || 3), '0');
      if (k === 'n') return String(+sq.inicio || 1); if (k === 'total') return String(S.sel.size);
      if (k === 'data') return S.opts.seqdata.inicio ? fd(S.opts.seqdata.inicio.split('-').reverse().join('/'), a || S.opts.seqdata.fmt) : '{data?}';
      if (['venc', 'prev', 'emis', 'reg'].includes(k)) return fd(r[k], a);
      if (k === 'forn') return r.fn; if (k === 'valor') return (+r.valor).toLocaleString('pt-BR', {minimumFractionDigits: 2});
      if (k === 'atual') return r[op.campo] || '';
      return r[k] != null ? r[k] : x;
    });
    const at = r[op.campo] || '';
    if (op.campo === 'obs') return '1º título: acrescenta a linha <code>' + esc(rr) + '</code> no fim da observação';
    let v = op.modo === 'substituir' ? rr : op.modo === 'antes' ? (at ? rr + op.sep + at : rr) : op.modo === 'depois' ? (op.campo === 'obs' ? (at ? at + ' ⏎ ' + rr : rr) : (at ? at + op.sep + rr : rr)) : (op.procurar && norm(at).includes(norm(op.procurar)) ? at.replace(new RegExp(op.procurar.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi'), rr) : at);
    const max = CAMPOS[op.campo][2];
    return '1º título: <code>' + esc(at || '(vazio)') + '</code> → <code>' + esc(v) + '</code>' + (max && v.length > max ? ' <b style="color:#a23f1d">(' + v.length + ' caracteres — máximo ' + max + ')</b>' : '');
  }
  function renderOps() {
    const el = $('#ao-ops'), c = S.cat || {};
    el.innerHTML = S.ops.map((op, i) => {
      const d = CAMPOS[op.campo], t = d[1]; let h = '';
      if (t === 'texto') {
        h = '<div class="lin"><label>Como aplicar<select data-p="modo"' + (op.campo === 'obs' ? ' disabled' : '') + '>' +
          [['substituir', 'substituir tudo'], ['antes', 'inserir antes do conteúdo'], ['depois', op.campo === 'obs' ? 'acrescentar linha no fim' : 'inserir depois do conteúdo'], ['trocar', 'trocar um trecho']].map(([v, r]) => '<option value="' + v + '"' + (op.modo === v ? ' selected' : '') + '>' + r + '</option>').join('') + '</select></label>' +
          (op.modo === 'trocar' ? '<label>Procurar<input data-p="procurar" value="' + esc(op.procurar) + '"></label>' : '') +
          '<label>' + (op.modo === 'trocar' ? 'Trocar por' : 'Texto') + '<input class="tpl" data-p="texto" value="' + esc(op.texto) + '" placeholder="ex.: 1484-P{seq}"></label>' +
          ((op.modo === 'antes' || op.modo === 'depois') && op.campo !== 'obs' ? '<label>Separador<input data-p="sep" value="' + esc(op.sep) + '" style="width:70px" title="o que vai entre o texto novo e o conteúdo existente"></label>' : '') + '</div>' +
          '<div class="ao-chips">' + TOKENS.map(k => '<button type="button" data-tok="' + esc(k) + '">' + esc(k) + '</button>').join('') + '</div>' +
          '<div class="ao-ex" data-ex>' + exemplo(op) + '</div>' +
          (op.campo === 'obs' ? '<div class="ao-nota">Pela API o Omie só <b>acrescenta uma linha nova</b> no fim da observação. Para substituir ou inserir no início, use "Alterar Contas Selecionadas" na tela do Omie.</div>' : '');
      } else if (t === 'data') {
        h = '<div class="lin"><label>Regra<select data-p="modo">' + [['somar', 'somar/subtrair dias à data atual de cada título'], ['fixa', 'mesma data para todos'], ['dia', 'trocar só o dia (mantém mês/ano)'], ['copiar', 'copiar de outra data do título']].map(([v, r]) => '<option value="' + v + '"' + (op.modo === v ? ' selected' : '') + '>' + r + '</option>').join('') + '</select></label>' +
          (op.modo === 'somar' ? '<label>Dias<input type="number" data-p="n" value="' + esc(op.n) + '" style="width:90px" title="use número negativo para voltar"></label><label class="ck"><input type="checkbox" data-p="uteis"' + (op.uteis ? ' checked' : '') + '> dias úteis</label>' : '') +
          (op.modo === 'fixa' ? '<label>Data<input type="date" data-p="data" value="' + esc(op.data) + '"></label>' : '') +
          (op.modo === 'dia' ? '<label>Dia<input type="number" min="1" max="31" data-p="dia" value="' + esc(op.dia) + '" style="width:80px"></label>' : '') +
          (op.modo === 'copiar' ? '<label>De<select data-p="de">' + ['venc', 'prev', 'emis', 'reg'].filter(x => x !== op.campo).map(x => '<option value="' + x + '"' + (op.de === x ? ' selected' : '') + '>' + CAMPOS[x][0] + '</option>').join('') + '</select></label><label>± dias<input type="number" data-p="n" value="' + esc(op.n || 0) + '" style="width:80px"></label>' : '') +
          (!(op.modo === 'somar' && op.uteis) ? '<label>Se cair em fim de semana/feriado<select data-p="ajuste">' + [['manter', 'manter'], ['proximo', 'próximo dia útil'], ['anterior', 'dia útil anterior']].map(([v, r]) => '<option value="' + v + '"' + (op.ajuste === v ? ' selected' : '') + '>' + r + '</option>').join('') + '</select></label>' : '') +
          '</div><div class="ao-ex">Feriados considerados: nacionais, Carnaval, Sexta-feira Santa, Corpus Christi e aniversário de Joinville (09/03).' + (['venc', 'prev'].includes(op.campo) ? ' Em títulos já pagos/recebidos o Omie não altera esta data.' : '') + '</div>';
      } else if (t === 'cadastro') {
        if (op.campo === 'cat') h = '<div class="lin"><label>Nova categoria<select data-p="valor">' + selCatalogo(c.cat, x => x.lanc && (S.tipos === 'P' ? x.desp : x.rec), op.valor) + '</select></label></div>';
        if (op.campo === 'cc') h = '<div class="lin"><label>Nova conta corrente<select data-p="valor">' + selCatalogo(c.cc, x => x.ativa, op.valor) + '</select></label></div><div class="ao-ex">Só em títulos sem baixa.</div>';
        if (op.campo === 'proj') h = '<div class="lin"><label>Novo projeto<select data-p="valor">' + selCatalogo(c.proj, x => x.ativa, op.valor) + '</select></label></div>';
        if (op.campo === 'forn') h = '<div class="lin"><label>Buscar cadastro (nome ou CPF/CNPJ)<input data-busca-forn value="" placeholder="digite 2+ letras"></label><label>Escolhido<select data-p="valor"><option value="' + esc(op.valor) + '">' + esc(op.valorNome || 'nenhum') + '</option></select></label></div><div class="ao-ex">Só em títulos sem baixa.</div>';
      } else if (t === 'deps') {
        const soma = op.itens.reduce((s, x) => s + (+x[1] || 0), 0);
        h = op.itens.map((x, j) => '<div class="ao-deps-l"><select data-dep="' + j + '">' + selCatalogo(c.deps, d => d.ativa, x[0]) + '</select><input type="number" step="0.01" data-perc="' + j + '" value="' + esc(x[1]) + '" style="width:90px"> %' + (op.itens.length > 1 ? ' <button type="button" class="ao-link" data-deprm="' + j + '">remover</button>' : '') + '</div>').join('') +
          '<button type="button" class="ao-link" data-depadd>+ departamento</button> <span class="cf-dica">total: <b style="color:' + (Math.abs(soma - 100) < 0.01 ? '#256b43' : '#a23f1d') + '">' + soma.toLocaleString('pt-BR') + '%</b> (substitui o rateio atual)</span>';
      } else if (t === 'valor') {
        h = '<div class="lin"><label>Regra<select data-p="modo">' + [['fixo', 'valor fixo'], ['percent', 'reajustar em %'], ['somar', 'somar/subtrair R$']].map(([v, r]) => '<option value="' + v + '"' + (op.modo === v ? ' selected' : '') + '>' + r + '</option>').join('') + '</select></label><label>Número<input type="number" step="0.01" data-p="n" value="' + esc(op.n) + '" style="width:120px"></label></div><div class="ao-nota">Valor mexe em dinheiro: confira bem a prévia. Só em títulos sem baixa; o rateio por departamento acompanha pelo percentual.</div>';
      } else if (t === 'pix') {
        h = '<div class="ao-ex">Troca a forma de pagamento para <b>Transferência → Transferência por chave PIX</b>, usando a chave Pix do cadastro do fornecedor no Omie. Títulos de fornecedor sem chave ficam de fora (a prévia mostra). Não tem como desfazer pela API (o Omie não devolve a chave antiga).</div>';
      }
      return '<div class="ao-op" data-i="' + i + '"><div class="ao-op-h"><b>' + esc(d[0]) + '</b><button type="button" class="ao-link" data-rm>remover</button></div>' + h + '</div>';
    }).join('') || '<p class="cf-dica">Escolha abaixo o(s) campo(s) que quer alterar nesta rodada.</p>';
    opcoesCampo(); renderSeq(); $('#ao-prev').innerHTML = '';
  }
  function renderSeq() {
    const usa = S.ops.some(o => /\{(seq|n|total|data)(:[^}]*)?\}/i.test(o.texto || ''));
    const usaData = S.ops.some(o => /\{data(:[^}]*)?\}/i.test(o.texto || ''));
    const el = $('#ao-seq'); el.hidden = !usa;
    if (!usa) return;
    const o = S.opts;
    el.innerHTML = '<b>Sequência</b><div class="lin"><label>Numerar na ordem de<select data-o="ordem">' +
      [['venc', 'vencimento'], ['prev', 'previsão'], ['emis', 'emissão'], ['id', 'inclusão no Omie'], ['forn', 'fornecedor/cliente (e vencimento)'], ['tabela', 'ordem atual da tabela'], ['selecao', 'ordem em que cliquei']].map(([v, r]) => '<option value="' + v + '"' + (o.ordem === v ? ' selected' : '') + '>' + r + '</option>').join('') + '</select></label>' +
      '<label>Começa em<input type="number" data-o="seq.inicio" value="' + o.seq.inicio + '"></label><label>Passo<input type="number" data-o="seq.passo" value="' + o.seq.passo + '"></label><label>Dígitos ({seq})<input type="number" min="1" max="10" data-o="seq.digitos" value="' + o.seq.digitos + '"></label>' +
      '<label class="ck" style="flex-direction:row;font-weight:500"><input type="checkbox" data-o="seq.reinicia"' + (o.seq.reinicia === 'forn' ? ' checked' : '') + '> recomeçar a cada fornecedor/cliente</label></div>' +
      (usaData ? '<div class="lin"><label>{data}: primeira data<input type="date" data-o="seqdata.inicio" value="' + esc(o.seqdata.inicio) + '"></label><label>Avança<input type="number" data-o="seqdata.passo" value="' + o.seqdata.passo + '"></label><label>Unidade<select data-o="seqdata.unidade"><option value="m"' + (o.seqdata.unidade === 'm' ? ' selected' : '') + '>mês(es)</option><option value="d"' + (o.seqdata.unidade === 'd' ? ' selected' : '') + '>dia(s)</option></select></label><label>Formato<input data-o="seqdata.fmt" value="' + esc(o.seqdata.fmt) + '" style="width:110px" title="dd, mm, aaaa, aa"></label></div>' : '') +
      '<div class="ao-ex">Títulos cancelados/bloqueados não ocupam número. A prévia mostra o número de cada título.</div>';
  }
  $('#ao-ops').addEventListener('input', e => {
    const card = e.target.closest('.ao-op'); if (!card) return; const op = S.ops[+card.dataset.i];
    if (e.target.dataset.p) {
      const p = e.target.dataset.p; op[p] = e.target.type === 'checkbox' ? e.target.checked : e.target.value;
      if (p === 'texto' || p === 'sep' || p === 'procurar') { const ex = $('[data-ex]', card); if (ex) ex.innerHTML = exemplo(op); renderSeqSemPerder(); $('#ao-prev').innerHTML = ''; return; }
      if (p === 'valor' && op.campo === 'forn') op.valorNome = e.target.selectedOptions[0]?.textContent;
      if (e.target.tagName === 'SELECT' || e.target.type === 'checkbox') renderOps(); else $('#ao-prev').innerHTML = '';
      return;
    }
    if (e.target.dataset.dep !== undefined) { op.itens[+e.target.dataset.dep][0] = e.target.value; renderOps(); }
    if (e.target.dataset.perc !== undefined) { op.itens[+e.target.dataset.perc][1] = e.target.value; }
    if (e.target.matches('[data-busca-forn]')) buscarForn(e.target, card, op);
  });
  $('#ao-ops').addEventListener('change', e => { if (e.target.dataset.perc !== undefined) renderOps(); });
  function renderSeqSemPerder() { const a = document.activeElement; const had = $('#ao-seq').hidden; renderSeq(); if (had !== $('#ao-seq').hidden && a) a.focus(); }
  let tmoForn;
  function buscarForn(inp, card, op) {
    clearTimeout(tmoForn);
    tmoForn = setTimeout(async () => {
      const q = inp.value.trim(); if (q.length < 2) return;
      try {
        const j = await get({a: 'forn', emp: S.emp, q});
        const s = $('select[data-p="valor"]', card);
        s.innerHTML = '<option value="">' + (j.itens.length ? j.itens.length + ' encontrado(s) — escolha…' : 'nada encontrado') + '</option>' + j.itens.map(x => '<option value="' + esc(x.id) + '">' + esc(x.nome + (x.doc ? ' — ' + x.doc : '')) + '</option>').join('');
        op.valor = ''; s.focus();
      } catch (err) { aviso(esc(err.message), 'erro'); }
    }, 300);
  }
  $('#ao-ops').addEventListener('click', e => {
    const card = e.target.closest('.ao-op'); if (!card) return; const i = +card.dataset.i, op = S.ops[i];
    if (e.target.matches('[data-rm]')) { S.ops.splice(i, 1); renderOps(); }
    if (e.target.dataset.tok) { const inp = $('input[data-p="texto"]', card); const a = inp.selectionStart ?? inp.value.length, b = inp.selectionEnd ?? a; inp.value = inp.value.slice(0, a) + e.target.dataset.tok + inp.value.slice(b); op.texto = inp.value; inp.focus(); inp.selectionStart = inp.selectionEnd = a + e.target.dataset.tok.length; $('[data-ex]', card).innerHTML = exemplo(op); renderSeqSemPerder(); $('#ao-prev').innerHTML = ''; }
    if (e.target.matches('[data-depadd]')) { op.itens.push(['', 0]); renderOps(); }
    if (e.target.dataset.deprm !== undefined) { op.itens.splice(+e.target.dataset.deprm, 1); renderOps(); }
  });
  $('#ao-seq').addEventListener('input', e => {
    const p = e.target.dataset.o; if (!p) return;
    const [a, b] = p.split('.');
    const v = e.target.type === 'checkbox' ? (e.target.checked ? 'forn' : 'nada') : e.target.value;
    if (b) S.opts[a][b] = v; else S.opts[a] = v;
    $$('.ao-op').forEach(card => { const ex = $('[data-ex]', card); if (ex) ex.innerHTML = exemplo(S.ops[+card.dataset.i]); });
    if (e.target.tagName === 'SELECT' || e.target.type === 'checkbox') renderSeq();
    $('#ao-prev').innerHTML = '';
  });
  $('#ao-add-campo').addEventListener('change', e => { const c = e.target.value; if (!c) return; S.ops.push(novaOp(c)); renderOps(); });
  $('#ao-abrir-alt').onclick = () => { $('#ao-alt').hidden = false; $('#ao-dock').hidden = true; renderOps(); $('#ao-alt').scrollIntoView({behavior: 'smooth', block: 'start'}); };
  $('#ao-fechar-alt').onclick = () => { $('#ao-alt').hidden = true; contadores(); };

  function opsParaEnvio() {
    return S.ops.map(o => {
      const x = {...o}; delete x.valorNome;
      if (x.data && /^\d{4}-\d{2}-\d{2}$/.test(x.data)) x.data = x.data.split('-').reverse().join('/');
      if (x.itens) x.itens = x.itens.map(d => [d[0], +d[1] || 0]);
      return x;
    });
  }
  function optsParaEnvio() {
    const o = JSON.parse(JSON.stringify(S.opts));
    if (o.seqdata.inicio && /^\d{4}-\d{2}-\d{2}$/.test(o.seqdata.inicio)) o.seqdata.inicio = o.seqdata.inicio.split('-').reverse().join('/');
    if (o.ordem === 'selecao') o.ordem = 'tabela';
    return o;
  }
  function itensParaEnvio() {
    let ks;
    if (S.opts.ordem === 'selecao') ks = [...S.sel.entries()].sort((a, b) => a[1] - b[1]).map(x => x[0]);
    else { const naTabela = S.filtrada.filter(r => S.sel.has(r.k)).map(r => r.k); const set = new Set(naTabela); ks = naTabela.concat([...S.sel.entries()].sort((a, b) => a[1] - b[1]).map(x => x[0]).filter(k => !set.has(k))); }
    return ks.map(k => { const [t, id] = k.split(':'); return [t, +id]; });
  }

  $('#ao-previa').onclick = async () => {
    const pv = $('#ao-prev'); pv.innerHTML = '<p><span class="ao-spin"></span>calculando a prévia…</p>';
    try {
      const j = await post({a: 'previa', emp: S.emp, itens: itensParaEnvio(), ops: opsParaEnvio(), opts: optsParaEnvio()});
      if (j.erros) { pv.innerHTML = '<div class="erro">' + j.erros.map(esc).join('<br>') + '</div>'; return; }
      const muda = j.linhas.filter(l => l.campos.length), bloq = j.linhas.filter(l => !l.campos.length && l.bloqueios.length), nada = j.linhas.filter(l => !l.campos.length && !l.bloqueios.length);
      const parc = muda.filter(l => l.bloqueios.length).length, av = j.linhas.filter(l => l.avisos.length).length;
      const usaSeq = S.ops.some(o => /\{(seq|n|total|data)/i.test(o.texto || ''));
      let h = '<div class="ao-res"><span class="ok"><b>' + muda.length + '</b> título(s) vão mudar</span>' + (parc ? '<span class="av">' + parc + ' com parte bloqueada</span>' : '') + (bloq.length ? '<span class="bl">' + bloq.length + ' bloqueado(s)</span>' : '') + (nada.length ? '<span>' + nada.length + ' já estão assim</span>' : '') + (av ? '<span class="av">' + av + ' com aviso</span>' : '') + '</div>';
      h += '<div class="ao-scroll"><table class="ao-ptbl"><thead><tr>' + (usaSeq ? '<th>#</th>' : '') + '<th>Tipo</th><th>Fornecedor / Cliente</th><th>Vencimento</th><th>Valor</th><th>Campo</th><th>Antes</th><th>Depois</th><th>Observações</th></tr></thead><tbody>';
      for (const l of j.linhas) {
        const n = Math.max(1, l.campos.length), msgs = l.bloqueios.map(m => '<div class="msgb">⛔ ' + esc(m) + '</div>').join('') + l.avisos.map(m => '<div class="msga">⚠ ' + esc(m) + '</div>').join('');
        const cab = (usaSeq ? '<td rowspan="' + n + '">' + (l.campos.length ? l.seq : '—') + '</td>' : '') + '<td rowspan="' + n + '"><span class="tp ' + l.tipo + '">' + (l.tipo === 'R' ? 'REC' : 'PAG') + '</span></td><td rowspan="' + n + '">' + esc(dec(l.nome)) + '</td><td rowspan="' + n + '">' + esc(l.venc) + '</td><td rowspan="' + n + '" class="n">' + brl(l.valor) + '</td>';
        if (!l.campos.length) { h += '<tr class="' + (l.bloqueios.length ? 'bloq' : '') + '">' + cab + '<td colspan="3" class="cf-dica">' + (l.bloqueios.length ? 'nada será alterado' : 'já está assim') + '</td><td>' + msgs + '</td></tr>'; continue; }
        l.campos.forEach((c, i) => { h += '<tr>' + (i === 0 ? cab : '') + '<td class="campo">' + esc(c.rot) + '</td><td class="antes">' + esc(dec(c.antes)).replace(/\|/g, ' ⏎ ') + '</td><td class="depois">' + esc(dec(c.depois)).replace(/\|/g, ' ⏎ ') + '</td>' + (i === 0 ? '<td rowspan="' + n + '">' + msgs + '</td>' : '') + '</tr>'; });
      }
      h += '</tbody></table></div>';
      const emp = JSON.parse(raiz.dataset.empresas)[S.emp] || S.emp;
      if (muda.length) h += '<div class="ao-conf"><span>' + esc(j.resumo) + '</span><button type="button" class="btn" id="ao-executar">Confirmar e alterar ' + muda.length + ' título(s) na ' + esc(emp) + '</button></div>';
      pv.innerHTML = h;
      const bx = $('#ao-executar'); if (bx) bx.onclick = () => executar(bx);
      pv.scrollIntoView({behavior: 'smooth', block: 'start'});
    } catch (err) { pv.innerHTML = '<div class="erro">' + esc(err.message) + '</div>'; }
  };

  async function executar(bx) {
    bx.disabled = true; bx.textContent = 'criando a rodada…';
    try {
      const j = await post({a: 'criar', emp: S.emp, itens: itensParaEnvio(), ops: opsParaEnvio(), opts: optsParaEnvio()});
      if (j.erros) { aviso(j.erros.map(esc).join('<br>'), 'erro'); bx.disabled = false; return; }
      S.sel.clear(); $('#ao-alt').hidden = true; $('#ao-prev').innerHTML = ''; S.sujo = true;
      abrirRodada(j, true);
    } catch (err) { aviso(esc(err.message), 'erro'); bx.disabled = false; bx.textContent = 'tentar de novo'; }
  }

  /* ---------------- receitas ---------------- */
  function receitasUI() {
    const s = $('#ao-rec-sel');
    s.innerHTML = '<option value="">usar uma receita salva…</option>' + S.receitas.map(r => '<option value="' + r.id + '">' + esc(r.nome) + (r.por ? ' (' + esc(r.por) + ')' : '') + '</option>').join('');
    $('#ao-rec-excluir').hidden = true;
  }
  $('#ao-rec-sel').onchange = e => {
    const r = S.receitas.find(x => String(x.id) === e.target.value); $('#ao-rec-excluir').hidden = !r; if (!r) return;
    S.ops = JSON.parse(JSON.stringify(r.ops)).filter(o => CAMPOS[o.campo]).map(o => { if (o.data && /^\d{2}\/\d{2}\/\d{4}$/.test(o.data)) o.data = o.data.split('/').reverse().join('-'); return o; });
    const op = r.opts || {}; S.opts = {ordem: op.ordem || 'venc', seq: Object.assign({inicio: 1, passo: 1, digitos: 3, reinicia: 'nada'}, op.seq || {}), seqdata: Object.assign({inicio: '', passo: 1, unidade: 'm', fmt: 'mm/aaaa'}, op.seqdata || {})};
    if (S.opts.seqdata.inicio && /^\d{2}\/\d{2}\/\d{4}$/.test(S.opts.seqdata.inicio)) S.opts.seqdata.inicio = S.opts.seqdata.inicio.split('/').reverse().join('-');
    renderOps();
  };
  $('#ao-rec-salvar').onclick = async () => {
    if (!S.ops.length) { aviso('Monte as alterações antes de salvar a receita.', 'erro'); return; }
    const nome = prompt('Nome da receita (ex.: "Código padrão P00X no Nº doc"):'); if (!nome) return;
    try { await post({a: 'receita_salvar', nome, ops: opsParaEnvio(), opts: optsParaEnvio()}); const e = await get({a: 'estado', emp: S.emp}); S.receitas = e.receitas; receitasUI(); aviso('Receita salva.'); }
    catch (err) { aviso(esc(err.message), 'erro'); }
  };
  $('#ao-rec-excluir').onclick = async () => {
    const id = $('#ao-rec-sel').value; if (!id || !confirm('Excluir esta receita?')) return;
    try { await post({a: 'receita_excluir', id}); S.receitas = S.receitas.filter(r => String(r.id) !== id); receitasUI(); } catch (err) { aviso(esc(err.message), 'erro'); }
  };

  /* ---------------- rodada (execução) ---------------- */
  const FINAL = new Set(['conferido', 'ja_ok', 'bloqueado', 'conflito', 'nao_aplicado', 'aviso', 'erro', 'pulado']);
  function aba(nome) {
    $$('.ao-abas button').forEach(b => b.classList.toggle('on', b.dataset.aba === nome));
    $$('section[data-painel]').forEach(s => s.hidden = s.dataset.painel !== nome);
    $('.ao-barra').hidden = nome !== 'titulos'; if (nome !== 'titulos') aviso('');
    if (nome === 'historico') historico();
    if (nome === 'titulos' && S.sujo && !S.rodando) carregarTitulos().catch(err => aviso(esc(err.message), 'erro'));
  }
  $$('.ao-abas button').forEach(b => b.onclick = () => aba(b.dataset.aba));

  function abrirRodada(v, rodar) {
    S.rodada = v; aba('rodada'); renderRodada();
    if (rodar) rodarFila();
  }
  function contagem() {
    const c = {}; for (const i of S.rodada.itens) c[i.status] = (c[i.status] || 0) + 1; return c;
  }
  function renderRodada() {
    const v = S.rodada, r = v.rodada, c = contagem(), n = v.itens.length, feitos = v.itens.filter(i => FINAL.has(i.status)).length;
    const abertos = (c.pendente || 0) + (c.enviado || 0);
    let h = '<div class="card"><div class="ao-alt-top"><h2>Rodada #' + r.id + ' — ' + esc(r.empresa_nome) + '</h2><button type="button" class="ao-link" data-acao="voltar">← voltar aos títulos</button></div>' +
      '<p class="cf-dica" style="margin:4px 0">' + esc(r.resumo) + ' · por ' + esc(r.por || '—') + ' em ' + esc(r.em) + (r.desfaz ? ' · desfaz a rodada #' + r.desfaz : '') + '</p>' +
      (v.aviso ? '<div class="ao-nota">' + esc(v.aviso) + '</div>' : '') +
      '<div class="ao-prog"><div style="width:' + (n ? Math.round(feitos / n * 100) : 100) + '%"></div></div>' +
      '<div class="ao-res">' + Object.entries(c).map(([s, q]) => '<span class="is ' + s + '">' + q + ' ' + esc(STATUS[s] || s) + '</span>').join('') + '</div>' +
      '<p id="ao-run-msg" class="cf-dica">' + (S.rodando ? '<span class="ao-spin"></span>' + esc(S.runMsg || 'executando…') + ' Não feche esta aba.' : abertos ? (r.status === 'pausada' ? 'Rodada pausada.' : abertos + ' título(s) ainda em aberto.') : 'Rodada concluída.') + '</p>' +
      '<div class="ao-alt-bot">' +
      (S.rodando ? '<button type="button" class="btn" data-acao="parar" style="background:#a23f1d">Interromper</button>' : '') +
      (!S.rodando && abertos ? '<button type="button" class="btn" data-acao="continuar">Continuar a rodada</button>' : '') +
      (!S.rodando && (c.aviso || c.nao_aplicado) ? '<button type="button" class="ao-link" data-acao="reconferir">reconferir avisos/não aplicados</button>' : '') +
      (!S.rodando && !abertos && (c.conferido || c.aviso) ? '<button type="button" class="ao-link" data-acao="desfazer">desfazer esta rodada</button>' : '') +
      '<a class="ao-link" href="api.php?a=exportar&id=' + r.id + '">exportar CSV</a></div></div>';
    h += '<div class="ao-scroll" style="max-height:none"><table class="ao-ptbl"><thead><tr><th>#</th><th>Tipo</th><th>Fornecedor / Cliente</th><th>Vencimento</th><th>Valor</th><th>Campo</th><th>Antes</th><th>Pedido</th><th>Situação</th></tr></thead><tbody>';
    for (const i of v.itens) {
      const nn = Math.max(1, i.campos.length);
      const cab = '<td rowspan="' + nn + '">' + i.seq + '</td><td rowspan="' + nn + '"><span class="tp ' + i.tipo + '">' + (i.tipo === 'R' ? 'REC' : 'PAG') + '</span></td><td rowspan="' + nn + '">' + esc(dec(i.nome)) + '<div class="cf-dica" style="margin:0">' + esc(i.omie_id) + (i.ci ? ' · ' + esc(i.ci) : '') + '</div></td><td rowspan="' + nn + '">' + esc(i.venc) + '</td><td rowspan="' + nn + '" class="n">' + brl(i.valor) + '</td>';
      const sit = '<td rowspan="' + nn + '" data-it="' + i.id + '"><span class="is ' + i.status + '">' + esc(i.status_rot) + '</span>' + (i.msg ? '<div class="cf-dica" style="margin:3px 0 0;max-width:340px">' + esc(i.msg) + '</div>' : '') + '</td>';
      if (!i.campos.length) { h += '<tr>' + cab + '<td colspan="3"></td>' + sit + '</tr>'; continue; }
      i.campos.forEach((cc, k) => { h += '<tr>' + (k === 0 ? cab : '') + '<td class="campo">' + esc(cc.rot) + '</td><td class="antes">' + esc(dec(cc.antes)).replace(/\|/g, ' ⏎ ') + '</td><td class="depois">' + esc(dec(cc.alvo)).replace(/\|/g, ' ⏎ ') + '</td>' + (k === 0 ? sit : '') + '</tr>'; });
    }
    h += '</tbody></table></div>';
    $('#ao-rodada').innerHTML = h;
  }
  const STATUS = {pendente: 'na fila', enviado: 'conferindo', conferido: 'alterado e conferido', ja_ok: 'já estava certo', bloqueado: 'não alterável', conflito: 'mudou desde a prévia', nao_aplicado: 'Omie não aplicou', aviso: 'alterado (com aviso)', erro: 'erro', pulado: 'não enviado'};
  let renderTmo;
  function atualizaItem(it) {
    const i = S.rodada.itens.findIndex(x => x.id === it.id); if (i >= 0) S.rodada.itens[i] = it;
    clearTimeout(renderTmo); renderTmo = setTimeout(renderRodada, 120);
  }
  $('#ao-rodada').addEventListener('click', async e => {
    const a = e.target.dataset.acao; if (!a) return;
    const id = S.rodada.rodada.id;
    if (a === 'voltar') { aba('titulos'); return; }
    if (a === 'parar') { S.parar = true; S.runMsg = 'interrompendo depois do título atual…'; renderRodada(); return; }
    try {
      if (a === 'continuar') { const v = await post({a: 'retomar', rodada: id}); S.rodada = v; renderRodada(); rodarFila(); }
      if (a === 'reconferir') { const v = await post({a: 'reconferir', rodada: id}); S.rodada = v; renderRodada(); rodarFila(); }
      if (a === 'desfazer') {
        if (!confirm('Criar uma rodada que volta os campos desta rodada ao valor anterior? Você verá cada título antes de enviar.')) return;
        const v = await post({a: 'desfazer', rodada: id});
        if (v.erros) { alert(v.erros.join('\n')); return; }
        S.rodada = v; renderRodada();
        const n = v.itens.filter(i => i.status === 'pendente').length;
        if (n && confirm('A rodada #' + v.rodada.id + ' vai voltar ' + n + ' título(s) ao valor anterior (veja a lista). Enviar agora?')) rodarFila();
      }
    } catch (err) { alert(err.message); }
  });

  async function rodarFila() {
    if (S.rodando) return;
    S.rodando = true; S.parar = false; botoes();
    const envio = S.rodada.itens.filter(i => i.status === 'pendente').map(i => i.id);
    const conf = new Map(S.rodada.itens.filter(i => i.status === 'enviado').map(i => [i.id, Date.now()]));
    let pausaAte = 0, errosSeguidos = 0;
    S.runMsg = 'enviando…'; renderRodada();
    try {
      while ((envio.length && !S.parar) || conf.size) {
        const agora = Date.now();
        const vencida = [...conf.entries()].filter(([, t]) => t <= agora).sort((a, b) => a[1] - b[1])[0];
        if (vencida) {
          const j = await post({a: 'conferir', item: vencida[0]});
          atualizaItem(j.item);
          if (j.item.status === 'enviado') conf.set(vencida[0], Date.now() + Math.max(1, j.tentar_em || 5) * 1000); else conf.delete(vencida[0]);
          if (j.parar) { S.parar = true; S.runMsg = 'o Omie bloqueou a API — rodada pausada'; }
        } else if (envio.length && !S.parar && agora >= pausaAte) {
          const id = envio[0];
          S.runMsg = 'enviando ' + (S.rodada.itens.filter(i => FINAL.has(i.status) || i.status === 'enviado').length + 1) + ' de ' + S.rodada.itens.length + '…';
          const j = await post({a: 'enviar', item: id});
          atualizaItem(j.item);
          if (j.item.status === 'pendente') { pausaAte = Date.now() + Math.max(5, j.tentar_em || 30) * 1000; S.runMsg = j.item.msg; if (j.parar) S.parar = true; }
          else {
            envio.shift();
            if (j.item.status === 'enviado') { conf.set(id, Date.now() + Math.max(1, j.tentar_em || 3) * 1000); errosSeguidos = 0; }
            else if (j.item.status === 'erro') { errosSeguidos++; if (errosSeguidos >= 3) { S.parar = true; S.runMsg = '3 erros seguidos — rodada pausada para você verificar (o Omie bloqueia a API no 10º erro seguido)'; } }
            else errosSeguidos = 0;
          }
        } else {
          const prox = Math.min(...[...conf.values()], envio.length && !S.parar ? pausaAte : Infinity);
          if (!envio.length || S.parar) S.runMsg = 'conferindo os últimos títulos (a conferência final leva até ~1 min)…';
          renderRodada();
          await dorme(Math.max(300, Math.min(2000, prox - Date.now())));
          continue;
        }
        await dorme(250);
      }
      if (S.parar && envio.length) { const v = await post({a: 'parar', rodada: S.rodada.rodada.id}); S.rodada = v; }
    } catch (err) { S.runMsg = ''; alert('A rodada parou: ' + err.message + '\nVocê pode continuar pelo Histórico.'); }
    S.rodando = false; S.parar = false; botoes();
    try { S.rodada = await get({a: 'rodada', id: S.rodada.rodada.id}); } catch (e) {}
    renderRodada();
  }
  window.addEventListener('beforeunload', e => { if (S.rodando) { e.preventDefault(); e.returnValue = ''; } });

  /* ---------------- histórico ---------------- */
  async function historico() {
    const el = $('#ao-hist'); el.innerHTML = '<p><span class="ao-spin"></span>carregando…</p>';
    try {
      const j = await get({a: 'historico'});
      const emp = JSON.parse(raiz.dataset.empresas);
      el.innerHTML = '<div class="card"><h2>Rodadas</h2>' + (j.rodadas.length ? '<table class="ao-ptbl ao-hist"><thead><tr><th>#</th><th>Quando</th><th>Quem</th><th>Empresa</th><th>Alteração</th><th>Títulos</th><th>Resultado</th></tr></thead><tbody>' +
        j.rodadas.map(r => '<tr data-id="' + r.id + '"><td>' + r.id + '</td><td>' + esc(r.em) + '</td><td>' + esc(r.por || '') + '</td><td>' + esc(emp[r.empresa] || r.empresa) + ' · ' + (r.tipos === 'PR' ? 'pagar+receber' : r.tipos === 'R' ? 'receber' : 'pagar') + '</td><td>' + esc(r.resumo) + '</td><td class="n">' + r.n + '</td><td>' +
          (r.ok ? '<span class="is conferido">' + r.ok + ' ok</span> ' : '') + (r.avisos ? '<span class="is aviso">' + r.avisos + ' aviso</span> ' : '') + (r.erros ? '<span class="is erro">' + r.erros + ' erro</span> ' : '') + (r.abertos ? '<span class="is enviado">' + r.abertos + ' em aberto</span>' : '') + '</td></tr>').join('') + '</tbody></table>' : '<p class="cf-dica">Nenhuma rodada ainda.</p>') + '</div>';
      $$('tr[data-id]', el).forEach(tr => tr.onclick = async () => { try { abrirRodada(await get({a: 'rodada', id: tr.dataset.id}), false); } catch (err) { alert(err.message); } });
    } catch (err) { el.innerHTML = '<div class="erro">' + esc(err.message) + '</div>'; }
  }

  /* ---------------- topo ---------------- */
  $('#ao-emp').onchange = e => { S.emp = e.target.value; S.sel.clear(); S.ops = []; S.filtros = {}; $('#ao-alt').hidden = true; carregar(); };
  $$('.ao-tipos button').forEach(b => b.onclick = () => {
    if (S.ocupado) return;
    $$('.ao-tipos button').forEach(x => x.classList.toggle('on', x === b)); S.tipos = b.dataset.tipo; S.sel.clear();
    S.ops = S.ops.filter(o => !(o.campo === 'pix' && S.tipos !== 'P') && !(o.campo === 'cat' && S.tipos === 'PR'));
    delete S.filtros.tipo; $('#ao-alt').hidden = true; carregar();
  });
  $('#ao-atualizar').onclick = () => atualizar('incremental');
  $('#ao-completa').onclick = () => { if (confirm('Reler TODOS os títulos do Omie? Pode levar cerca de 1 minuto.')) atualizar('completa'); };

  carregar();
})();
