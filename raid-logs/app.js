// Interface: carrega relatorios (com cache em disco via servidor local) e desenha as analises.
(function () {
  'use strict';
  const C = WCLCore;
  const $ = id => document.getElementById(id);
  const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const mmss = t => { const neg = t < 0; t = Math.abs(Math.round(t)); return (neg ? '-' : '') + Math.floor(t / 60) + ':' + String(t % 60).padStart(2, '0'); };
  const num = v => v >= 1e6 ? (v / 1e6).toFixed(2).replace('.', ',') + ' M' : v >= 1e3 ? Math.round(v / 1e3) + ' k' : String(Math.round(v));
  const pct = v => v == null ? '' : v.toFixed(1).replace('.', ',') + '%';
  const dataBR = ms => new Date(ms).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
  const DIF = { 3: 'Normal', 4: 'Heroico', 5: 'Mítico' };
  const COR_CLASSE = { DeathKnight: '#C41E3A', DemonHunter: '#A330C9', Druid: '#FF7C0A', Evoker: '#33937F', Hunter: '#AAD372', Mage: '#3FC7EB', Monk: '#00FF98', Paladin: '#F48CBA', Priest: '#FFFFFF', Rogue: '#FFF468', Shaman: '#0070DD', Warlock: '#8788EE', Warrior: '#C69B6D' };
  const selo = (nome, classe) => '<span class="selo" style="background:' + (COR_CLASSE[classe] || '#888') + '"></span>' + esc(nome);

  // Nome de habilidade com link para o Wowhead (o script deles abre a tooltip ao passar o mouse).
  let IDS = {}, NOMES_RE = null;
  // PT = nomes em portugues vindos do Wowhead (id -> nome), guardados no cache do servidor.
  let PT = {}; const pendPt = new Set(); let buscandoPt = false;
  const hab = (nome, id) => {
    id = id || IDS[nome]; if (!id) return esc(nome);
    const pt = PT[id]; if (pt === undefined) pendPt.add(id);
    return '<a class="wh" href="https://www.wowhead.com/pt/spell=' + id + '" target="_blank" rel="noopener">' + esc(pt || nome) + '</a>' + (pt && pt !== nome ? ' <span class="en">(' + esc(nome) + ')</span>' : '');
  };
  async function buscarPt() {
    if (buscandoPt || !pendPt.size) return; buscandoPt = true;
    const ids = [...pendPt]; pendPt.clear(); let novos = 0;
    for (let i = 0; i < ids.length; i += 8) await Promise.all(ids.slice(i, i + 8).map(async id => {
      try { const r = await fetch('https://nether.wowhead.com/tooltip/spell/' + id + '?dataEnv=1&locale=8'); PT[id] = r.ok ? ((await r.json()).name || '') : ''; } catch (e) { PT[id] = ''; }
      if (PT[id]) novos++;
    }));
    buscandoPt = false;
    await gravarArq('nomes_pt', PT);
    const foco = document.activeElement && /INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName);
    if (novos && !foco) desenhar();
  }
  const linkTexto = txt => { const e = esc(txt); return NOMES_RE ? e.replace(NOMES_RE, m => { const k = m.replace(/&#39;|&quot;/g, "'"); return IDS[m] || IDS[k] ? hab(IDS[m] ? m : k) : m; }) : e; };
  function montarIds(ls) {
    IDS = {}; for (const c of C.CDS) if (!IDS[c.nome]) IDS[c.nome] = c.id;
    for (const l of ls) { for (const h of l.habs) if (h.id > 1 && !IDS[h.nome]) IDS[h.nome] = h.id; for (const m of l.mortes) { if (m.golpeId > 1 && !IDS[m.golpe]) IDS[m.golpe] = m.golpeId; for (const d of m.dano) if (d.id > 1 && !IDS[d.nome]) IDS[d.nome] = d.id; } }
    const nomes = Object.keys(IDS).filter(n => n.length > 3).sort((a, b) => b.length - a.length).map(n => esc(n).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
    NOMES_RE = nomes.length ? new RegExp('(?<![\\w>])(' + nomes.join('|') + ')(?![\\w<])', 'g') : null;
  }

  const est = { rels: [], lutas: [], porRel: {}, enc: null, catalogo: [], noites: new Set(), golpe: '', aba: 'geral', fase: null, comDps: false, refs: undefined, nota: undefined, recusas: {}, cura: {}, aoVivo: null };

  async function gql(query, variables) {
    const r = await fetch('api.php?a=graphql', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ query, variables }) });
    const txt = await r.text();
    let j; try { j = JSON.parse(txt); } catch (e) { throw new Error('Resposta inesperada (' + r.status + '): ' + txt.slice(0, 200)); }
    if (r.status === 429 || /too many requests|rate limit/i.test(txt.slice(0, 400))) throw new Error('COTA');
    if (j.erro === 'sem_login') { location.reload(); throw new Error('Login expirado.'); }
    if (j.errors) throw new Error(j.errors.map(e => e.message).join('\n'));
    return j.data;
  }
  async function lerCache(code) { try { const r = await fetch('api.php?a=cache&n=' + code); if (!r.ok) return null; const j = await r.json(); return j && j.v === C.VERSAO_CACHE ? j : null; } catch (e) { return null; } }
  async function gravarCache(code, obj) { try { await fetch('api.php?a=cache&n=' + code, { method: 'PUT', body: JSON.stringify(obj) }); } catch (e) { } }

  async function lerArq(nome) { try { const r = await fetch('api.php?a=cache&n=' + nome); return r.ok ? await r.json() : null; } catch (e) { return null; } }
  async function gravarArq(nome, obj) { try { await fetch('api.php?a=cache&n=' + nome, { method: 'PUT', body: JSON.stringify(obj) }); } catch (e) { } }
  const chaveBoss = () => est.enc.replace(':', '_');
  const MSG_COTA = 'A cota por hora da API do Warcraft Logs acabou. Tente de novo em alguns minutos; o que já foi baixado fica salvo.';

  async function carregarNota() { const n = await lerArq('nota_' + chaveBoss()); est.nota = n && n.texto ? n.texto : null; est.recusas = (await lerArq('recusas_' + chaveBoss())) || {}; }
  const salvarNota = () => gravarArq('nota_' + chaveBoss(), { texto: est.nota, quando: Date.now() });
  const salvarRecusas = () => gravarArq('recusas_' + chaveBoss(), est.recusas);

  const status = (msg, erro) => { const s = $('progresso'); s.textContent = msg || ''; s.className = erro ? 'erro' : 'sub'; };

  const SEIS_H = 6 * 3600e3;
  const salvarCatalogo = () => gravarArq('relatorios', { lista: est.catalogo });
  // Carrega relatorios (codes) para a memoria. Relatorio encerrado e ja baixado vem so do cache, sem gastar cota.
  async function carregar(opcoes) {
    opcoes = opcoes || {};
    const aoVivo = !!opcoes.aoVivo;
    const codes = opcoes.codes || [...est.noites];
    if (!codes.length || est.carregando) return; est.carregando = true;
    $('carregar').disabled = true;
    let cota = null, avisoCota = false, mudouCat = false;
    const antes = est.lutas.length;
    try {
      for (const code of codes) {
        const ja = est.porRel[code];
        if (ja && ja.rel.fim < Date.now() - SEIS_H && (aoVivo || ja.completo)) continue;
        if (!aoVivo) status('Lendo relatório ' + code + '…');
        const cache = (await lerCache(code)) || { v: C.VERSAO_CACHE, lutas: {} };
        let rel = cache.rel;
        const fechado = rel && rel.fim < Date.now() - SEIS_H && rel.lutas.every(f => cache.lutas[f.id]);
        if (!fechado) {
          rel = await C.listarRelatorio(gql, code); cota = rel.cota;
          const faltam = rel.lutas.filter(f => !cache.lutas[f.id]);
          const semRel = !cache.rel || cache.rel.lutas.length !== rel.lutas.length || cache.rel.fim !== rel.fim;
          cache.rel = rel;
          for (let i = 0; i < faltam.length; i += 6) {
            status(rel.titulo + ' (' + dataBR(rel.inicio) + '): baixando tries ' + (i + 1) + '–' + Math.min(i + 6, faltam.length) + ' de ' + faltam.length + '…');
            try {
              const novos = await C.buscarLutas(gql, rel, faltam.slice(i, i + 6));
              for (const l of novos) cache.lutas[l.id] = l;
              await gravarCache(code, cache);
            } catch (e) { if (e.message === 'COTA') { avisoCota = true; break; } throw e; }
          }
          if (semRel && !faltam.length) await gravarCache(code, cache);
        }
        est.porRel[code] = { rel, lutas: rel.lutas.filter(f => cache.lutas[f.id]).map(f => cache.lutas[f.id]), completo: rel.lutas.every(f => cache.lutas[f.id]) };
        const item = { code, titulo: rel.titulo, inicio: rel.inicio, fim: rel.fim, tries: rel.lutas.length }, k = est.catalogo.findIndex(x => x.code === code);
        if (k < 0) { est.catalogo.push(item); mudouCat = true; } else if (est.catalogo[k].tries !== item.tries || est.catalogo[k].fim !== item.fim) { est.catalogo[k] = item; mudouCat = true; }
        if (!aoVivo || !ja) est.noites.add(code);
        if (avisoCota) break;
      }
      if (mudouCat) { est.catalogo.sort((a, b) => a.inicio - b.inicio); await salvarCatalogo(); }
      const usados = Object.keys(est.porRel);
      est.rels = usados.map(c => est.porRel[c].rel).sort((a, b) => a.inicio - b.inicio);
      est.lutas = usados.flatMap(c => est.porRel[c].lutas).sort((a, b) => a.data - b.data || a.id - b.id);
      montarBosses();
      $('filtros').hidden = false;
      status(avisoCota ? MSG_COTA.replace('Tente de novo', 'Clique em Atualizar')
        : est.lutas.length + ' tries carregadas' + (aoVivo ? ' (ao vivo, atualizado às ' + new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) + ')' : '') + '.' + (cota ? ' Cota da API nesta hora: ' + Math.round(cota.pointsSpentThisHour) + ' de ' + cota.limitPerHour + '.' : ''), avisoCota);
      if (est.enc && est.nota === undefined) await carregarNota();
      if (!aoVivo || est.lutas.length !== antes) desenhar();
    } catch (e) { status(e.message === 'COTA' ? MSG_COTA : 'Erro: ' + e.message, true); }
    $('carregar').disabled = false; est.carregando = false;
  }
  // Opcoes de boss a partir das noites marcadas; mantem o boss atual se ele ainda existir.
  function montarBosses() {
    const encs = {}; for (const l of est.lutas) { if (!est.noites.has(l.rel)) continue; const k = l.enc + ':' + l.dif; (encs[k] = encs[k] || { k, boss: l.boss, dif: l.dif, n: 0 }).n++; }
    const lista = Object.values(encs).sort((a, b) => b.n - a.n);
    $('boss').innerHTML = lista.map(e => '<option value="' + e.k + '">' + esc(e.boss) + ' ' + (DIF[e.dif] || '') + ' (' + e.n + ' tries)</option>').join('');
    if (!lista.some(e => e.k === est.enc)) { est.enc = lista.length ? lista[0].k : null; est.refs = undefined; est.nota = undefined; }
    if (est.enc) $('boss').value = est.enc;
  }
  async function marcarNoites(codes, ligar) {
    for (const c of codes) ligar ? est.noites.add(c) : est.noites.delete(c);
    const falta = codes.filter(c => ligar && !est.porRel[c]);
    if (falta.length) await carregar({ codes: falta }); else { montarBosses(); if (est.enc && est.nota === undefined) await carregarNota(); desenhar(); }
  }

  const lutasAtuais = () => est.lutas.filter(l => (l.enc + ':' + l.dif) === est.enc && est.noites.has(l.rel));

  function desenhar() {
    const ls = lutasAtuais();
    const sems = {}; for (const r of est.catalogo) (sems[C.semanaDe(r.inicio)] = sems[C.semanaDe(r.inicio)] || []).push(r);
    $('noites').innerHTML = Object.keys(sems).map(Number).sort((a, b) => b - a).map(sm => { const rs = sems[sm], todas = rs.every(r => est.noites.has(r.code));
      return '<div class="linha semana"><label class="chip forte"><input type="checkbox" data-sem="' + sm + '"' + (todas ? ' checked' : '') + '> Semana ' + sm + '</label>' +
        rs.map(r => '<label class="chip" title="' + esc(r.titulo) + ' · ' + r.tries + ' lutas"><input type="checkbox" data-n="' + r.code + '"' + (est.noites.has(r.code) ? ' checked' : '') + '> ' + dataBR(r.inicio) + '</label>').join('') + '</div>'; }).join('');
    document.querySelectorAll('.aba').forEach(b => b.classList.toggle('ativa', b.dataset.aba === est.aba));
    montarIds(ls);
    const el = $('conteudo');
    if (!ls.length) { el.innerHTML = '<p class="sub">Nenhuma try para os filtros escolhidos.</p>'; return; }
    el.innerHTML = ({ geral: abaGeral, mortes: abaMortes, dano: abaDano, healers: abaHealers, plano: abaPlano, ultima: abaUltima })[est.aba](ls);
    try { if (window.$WowheadPower) window.$WowheadPower.refreshLinks(); } catch (e) { }
    buscarPt();
  }

  // ---------- Visao geral ----------
  const COR_FASE = ['var(--s1)', 'var(--s2)', 'var(--s3)', 'var(--s4)'];
  function nomeFase(l, id) { const r = est.rels.find(x => x.code === l.rel); const f = r && r.fases[l.enc] && r.fases[l.enc].find(p => p.id === id); return f ? f.name.replace(/^Stage (\w+):/, (m, n) => 'Fase ' + ({ One: 1, Two: 2, Three: 3, Four: 4 }[n] || n) + ':').replace('Intermission', 'Intermissão') : 'Fase ' + id; }

  function abaGeral(ls) {
    const r = C.resumo(ls);
    const melhor = Math.min(...ls.map(l => l.pct));
    let h = '<div class="cards"><div class="card"><div class="k">Tries</div><div class="v">' + ls.length + '</div></div>' +
      '<div class="card"><div class="k">Melhor try</div><div class="v">' + pct(melhor) + '</div></div>' +
      '<div class="card"><div class="k">Chegaram na fase 2 ou além</div><div class="v">' + ls.filter(l => l.ultFase > 1).length + '</div></div>' +
      '<div class="card"><div class="k">Duração mediana</div><div class="v">' + mmss(C.mediana(ls.map(l => l.durS))) + '</div></div></div>';
    // grafico: vida do boss por try
    const W = 1040, H = 260, ml = 44, mr = 12, mt = 14, mb = 26, pw = W - ml - mr, ph = H - mt - mb;
    const ymin = Math.max(0, Math.floor((melhor - 5) / 10) * 10), ymax = 100;
    const x = i => ml + (ls.length > 1 ? i / (ls.length - 1) : 0.5) * pw, y = v => mt + (ymax - v) / (ymax - ymin) * ph;
    let g = '';
    for (let v = ymin; v <= ymax; v += 10) g += '<line class="grade" x1="' + ml + '" x2="' + (W - mr) + '" y1="' + y(v) + '" y2="' + y(v) + '"/><text class="eixo" x="' + (ml - 6) + '" y="' + (y(v) + 4) + '" text-anchor="end">' + v + '%</text>';
    let ant = null;
    ls.forEach((l, i) => { if (l.rel !== ant) { g += '<line class="sep" x1="' + (x(i) - 4) + '" x2="' + (x(i) - 4) + '" y1="' + mt + '" y2="' + (H - mb) + '"/><text class="eixo" x="' + x(i) + '" y="' + (H - 8) + '">' + dataBR(l.data) + '</text>'; ant = l.rel; } });
    const fasesVistas = [...new Set(ls.map(l => l.ultFase))].sort();
    ls.forEach((l, i) => { g += '<circle cx="' + x(i) + '" cy="' + y(l.pct) + '" r="4" fill="' + COR_FASE[(l.ultFase - 1) % 4] + '" stroke="var(--sup)" stroke-width="1"><title>Try ' + l.id + ' de ' + dataBR(l.data) + ' — ' + pct(l.pct) + ', ' + mmss(l.durS) + ', ' + nomeFase(l, l.ultFase) + '</title></circle>'; });
    h += '<h2>Vida do boss ao fim de cada try</h2><div class="sub">Quanto mais baixo, mais longe a try chegou. Passe o mouse num ponto para ver os detalhes.</div>' +
      '<div class="legenda">' + fasesVistas.map(f => '<span><i style="background:' + COR_FASE[(f - 1) % 4] + '"></i>Terminou na ' + esc(nomeFase(ls.find(l => l.ultFase === f), f)) + '</span>').join('') + '</div>' +
      '<svg viewBox="0 0 ' + W + ' ' + H + '" class="graf">' + g + '</svg>';
    h += '<h2>Por noite</h2><table><tr><th>Noite</th><th class="n">Tries</th><th class="n">Melhor</th><th class="n">Mediana</th><th class="n">Duração mediana</th><th class="n">Terminaram na fase 1</th><th class="n">Fase 2 ou além</th></tr>' +
      r.noites.map(n => '<tr><td>' + dataBR(n.data) + '</td><td class="n">' + n.tries + '</td><td class="n">' + pct(n.melhor) + '</td><td class="n">' + pct(n.mediana) + '</td><td class="n">' + mmss(n.durMed) + '</td><td class="n">' + (n.fases[1] || 0) + '</td><td class="n">' + (n.tries - (n.fases[1] || 0)) + '</td></tr>').join('') + '</table>';
    return h;
  }

  // ---------- Mortes ----------
  function abaMortes(ls) {
    const todas = C.mortes(ls).causas;
    if (est.golpe && !todas.some(c => c.nome === est.golpe)) est.golpe = '';
    const m = C.mortes(ls, est.golpe);
    let h = '<div class="linha" style="margin-top:14px"><span class="sub">Habilidade que matou:</span><select id="golpe"><option value="">Todas</option>' + todas.map(c => '<option value="' + esc(c.nome) + '"' + (c.nome === est.golpe ? ' selected' : '') + '>' + esc(PT[IDS[c.nome]] ? PT[IDS[c.nome]] + ' / ' + c.nome : c.nome) + ' (' + c.mortes + ')</option>').join('') + '</select>' +
      (est.golpe ? '<span class="sub">' + m.porTry.length + ' de ' + m.wipes + ' wipes tiveram morte inicial por ' + esc(est.golpe) + '.</span>' : '') + '</div>';
    h += '<div class="sub">Só contam as 3 primeiras mortes de cada wipe: são elas que derrubam a try. O que vem depois é consequência.</div>';
    h += '<div class="duas"><div><h2>O que mata primeiro</h2><table><tr><th>Habilidade</th><th class="n">Mortes</th><th class="n">Tries afetadas</th><th class="n">Foi a 1ª morte</th></tr>' +
      m.causas.slice(0, 14).map(c => '<tr><td>' + hab(c.nome) + '</td><td class="n">' + c.mortes + '</td><td class="n">' + c.tries + ' (' + Math.round(100 * c.tries / m.wipes) + '%)</td><td class="n">' + c.primeira + '</td></tr>').join('') + '</table></div>';
    h += '<div><h2>Quem morre primeiro</h2><table><tr><th>Jogador</th><th class="n">Mortes iniciais</th><th class="n">Foi a 1ª morte</th><th class="n">Tries presentes</th><th class="n">Mortes por try</th></tr>' +
      m.quem.slice(0, 14).map(q => { const j = ls.map(l => l.jogadores.find(x => x.nome === q.nome)).find(Boolean) || {}; return '<tr><td>' + selo(q.nome, j.classe) + '</td><td class="n">' + q.mortes + '</td><td class="n">' + q.primeira + '</td><td class="n">' + q.presente + '</td><td class="n">' + (q.mortes / q.presente).toFixed(2).replace('.', ',') + '</td></tr>'; }).join('') + '</table></div></div>';
    h += '<h2>Try a try</h2><table><tr><th>Noite</th><th class="n">Try</th><th class="n">Duração</th><th class="n">Boss</th><th>Fase</th><th>Primeiras mortes</th></tr>' +
      m.porTry.map(({ l, prim }) => '<tr><td>' + dataBR(l.data) + '</td><td class="n">' + l.id + '</td><td class="n">' + mmss(l.durS) + '</td><td class="n">' + pct(l.pct) + '</td><td>' + l.ultFase + '</td><td>' +
        prim.map(d => (est.golpe && d.golpe === est.golpe ? '<b>' + esc(d.nome) + '</b>' : esc(d.nome)) + ' <span class="sub">(' + hab(d.golpe, d.golpeId) + ', ' + mmss(d.t) + ')</span>').join(' · ') + '</td></tr>').join('') + '</table>';
    return h;
  }

  // ---------- Dano e CDs ----------
  function abaDano(ls) {
    const fases = [0, ...[...new Set(ls.flatMap(l => l.fases.map(p => p.id)))].sort((a, b) => a - b)];
    if (!fases.includes(est.fase)) est.fase = 0;
    const c = C.curva(ls, est.fase), pk = C.picos(c, 8);
    const tipos = ['raid', 'ext'];
    const uso = C.usoCds(ls, est.fase, tipos);
    const linhas = uso.linhas.filter(l => est.comDps || l.papel === 'healer');
    let h = '<div class="linha">' + fases.map(f => '<button class="fase' + (f === est.fase ? ' ativa' : '') + '" data-fase="' + f + '">' + (f === 0 ? 'Luta inteira' : esc(nomeFase(ls.find(l => l.fases.some(p => p.id === f)), f))) + '</button>').join('') +
      '<label class="chip"><input type="checkbox" id="comDps"' + (est.comDps ? ' checked' : '') + '> Mostrar CDs de raid dos DPS e tanks</label></div>';
    if (c.len < 10) return h + '<p class="sub">Poucas tries chegaram nesta fase para montar a curva.</p>';
    h += '<h2>Dano médio na raid e uso de CDs</h2><div class="sub">Média de ' + c.alcancaram + ' tries, com o tempo contado a partir do ' + (est.fase === 0 ? 'pull' : 'início da fase') + '. A cascata do wipe (da 4ª morte em diante) fica de fora. Cada ponto é um uso do CD em uma try; o traço é o momento mediano.</div>';
    const W = 1040, ml = 210, mr = 16, pw = W - ml - mr, hc = 190, mt = 22, hl = 22;
    const H = mt + hc + 26 + linhas.length * hl + 8;
    const x = t => ml + t / Math.max(1, c.len - 1) * pw;
    const vmax = Math.max(...c.suave) * 1.12, y = v => mt + hc - v / vmax * hc;
    let g = '';
    const passo = c.len > 150 ? 30 : c.len > 70 ? 15 : 10;
    for (let t = 0; t < c.len; t += passo) g += '<line class="grade" x1="' + x(t) + '" x2="' + x(t) + '" y1="' + mt + '" y2="' + (H - 8) + '"/><text class="eixo" x="' + x(t) + '" y="' + (mt + hc + 16) + '" text-anchor="middle">' + mmss(t) + '</text>';
    const tk = vmax > 4e6 ? 2e6 : 1e6;
    for (let v = 0; v < vmax; v += tk) g += '<line class="grade" x1="' + ml + '" x2="' + (W - mr) + '" y1="' + y(v) + '" y2="' + y(v) + '"/><text class="eixo" x="' + (ml - 6) + '" y="' + (y(v) + 4) + '" text-anchor="end">' + (v / 1e6) + ' M/s</text>';
    const pts = c.suave.map((v, i) => x(i).toFixed(1) + ',' + y(v).toFixed(1));
    g += '<polygon class="area" points="' + x(0) + ',' + y(0) + ' ' + pts.join(' ') + ' ' + x(c.len - 1) + ',' + y(0) + '"/><polyline class="curva" points="' + pts.join(' ') + '"/>';
    pk.forEach((p, i) => { g += '<circle cx="' + x(p.t) + '" cy="' + y(p.dtps) + '" r="4" class="pico"/><text class="rot" x="' + x(p.t) + '" y="' + (y(p.dtps) - 8) + '" text-anchor="middle">' + (i + 1) + '</text>'; });
    linhas.forEach((ln, i) => {
      const yy = mt + hc + 26 + i * hl + hl / 2;
      g += '<line class="grade" x1="' + ml + '" x2="' + (W - mr) + '" y1="' + yy + '" y2="' + yy + '"/>' +
        '<circle cx="8" cy="' + yy + '" r="4" fill="' + (COR_CLASSE[ln.classe] || '#888') + '"/><text class="rot2" x="18" y="' + (yy + 4) + '">' + esc(ln.quem) + ' · ' + esc(ln.nome) + '</text>';
      for (const u of ln.usos) if (u.t >= 0 && u.t < c.len) g += '<circle class="uso" cx="' + x(u.t).toFixed(1) + '" cy="' + yy + '" r="3.5"><title>' + esc(ln.quem + ' — ' + ln.nome) + ' aos ' + mmss(u.t) + ' (try ' + u.luta + (u.alvo ? ', em ' + esc(u.alvo) : '') + ')</title></circle>';
      for (const o of ln.ordens) if (o.n >= 3 && o.med < c.len) g += '<line class="med" x1="' + x(o.med) + '" x2="' + x(o.med) + '" y1="' + (yy - 8) + '" y2="' + (yy + 8) + '"/>';
    });
    g += '<rect id="alvo" x="' + ml + '" y="' + mt + '" width="' + pw + '" height="' + hc + '" fill="transparent"/><line id="mira" class="mira" y1="' + mt + '" y2="' + (H - 8) + '" x1="-9" x2="-9"/>';
    h += '<div class="grafbox"><svg viewBox="0 0 ' + W + ' ' + H + '" class="graf" id="grafDano" data-ml="' + ml + '" data-pw="' + pw + '" data-w="' + W + '">' + g + '</svg><div id="dica" class="dica" hidden></div></div>';
    est._curva = c;
    // tabela de picos
    h += '<h2>Maiores picos de dano</h2><table><tr><th class="n">#</th><th class="n">Momento</th><th class="n">Dano na raid</th><th class="n">vs. média</th><th>Principal fonte</th><th>CDs que costumam cair aqui (de 8s antes a 3s depois)</th></tr>' +
      pk.map((p, i) => {
        const perto = uso.linhas.filter(ln => ln.tipo === 'raid').map(ln => { const n = new Set(ln.usos.filter(u => u.t >= p.t - 8 && u.t <= p.t + 3).map(u => u.rel + ':' + u.luta)).size; return { ln, n }; })
          .filter(o => o.n >= Math.max(3, p.tries * 0.25)).sort((a, b) => b.n - a.n);
        return '<tr><td class="n">' + (i + 1) + '</td><td class="n">' + mmss(p.t) + '</td><td class="n">' + num(p.dtps) + '/s</td><td class="n">' + Math.round((p.rel - 1) * 100) + '% acima</td><td>' + hab(p.hab || '') + '</td><td>' +
          (perto.length ? perto.map(o => esc(o.ln.quem + ' ' + o.ln.nome) + ' <span class="sub">(' + Math.round(100 * o.n / p.tries) + '%)</span>').join(' · ') : '<span class="alerta">⚠ Nenhum CD de raid costuma cair aqui</span>') + '</td></tr>';
      }).join('') + '</table>';
    // tabela de uso
    h += '<h2>Regularidade de cada CD</h2><table><tr><th>Jogador</th><th>CD</th><th class="n">Usou em</th><th>Usos (momento mediano, do mais cedo ao mais tarde)</th></tr>' +
      linhas.map(ln => '<tr><td>' + selo(ln.quem, ln.classe) + '</td><td>' + hab(ln.nome, ln.id) + '</td><td class="n">' + ln.tries + ' de ' + ln.presente + ' (' + Math.round(100 * ln.tries / ln.presente) + '%)</td><td>' +
        ln.ordens.map(o => o.ordem + 'º: ' + mmss(o.med) + ' <span class="sub">(' + mmss(o.min) + '–' + mmss(o.max) + ', ' + o.n + '×)</span>').join(' · ') + '</td></tr>').join('') + '</table>';
    return h;
  }

  function hoverDano(ev) {
    const svg = $('grafDano'), c = est._curva; if (!svg || !c) return;
    const r = svg.getBoundingClientRect(), W = +svg.dataset.w, ml = +svg.dataset.ml, pw = +svg.dataset.pw;
    const px = (ev.clientX - r.left) / r.width * W;
    const t = Math.round((px - ml) / pw * (c.len - 1));
    const dica = $('dica'), mira = $('mira');
    if (t < 0 || t >= c.len) { dica.hidden = true; mira.setAttribute('x1', -9); mira.setAttribute('x2', -9); return; }
    const xx = ml + t / (c.len - 1) * pw; mira.setAttribute('x1', xx); mira.setAttribute('x2', xx);
    const top = c.habs.map(hb => ({ nome: hb.nome, v: hb.v[t] })).sort((a, b) => b.v - a.v).slice(0, 4).filter(o => o.v > 0);
    dica.innerHTML = '<b>' + mmss(t) + '</b> — ' + num(c.suave[t]) + '/s na raid <span class="sub">(' + c.n[t] + ' tries)</span><br>' + top.map(o => esc(o.nome) + ': ' + num(o.v) + '/s').join('<br>');
    dica.hidden = false;
    const bx = svg.parentNode.getBoundingClientRect();
    dica.style.left = Math.min(bx.width - 250, Math.max(0, ev.clientX - bx.left + 14)) + 'px'; dica.style.top = '8px';
  }

  // ---------- Healers e pessoais ----------
  function abaHealers(ls) {
    const hs = C.healers(ls, 120), p = C.pessoais(ls, 120);
    let h = '<h2>Healers</h2><div class="sub">Média das tries com pelo menos 2 minutos.</div><table><tr><th>Healer</th><th>Spec</th><th class="n">Tries</th><th class="n">HPS médio</th><th class="n">HPS mediano</th><th class="n">Overheal</th></tr>' +
      hs.map(x => '<tr><td>' + selo(x.nome, x.classe) + '</td><td>' + esc(x.spec) + '</td><td class="n">' + x.tries + '</td><td class="n">' + num(x.hps) + '</td><td class="n">' + num(x.hpsMed) + '</td><td class="n">' + Math.round(x.over * 100) + '%</td></tr>').join('') + '</table>';
    h += '<h2>Defensivos pessoais e consumíveis</h2><div class="sub">Média por try de pelo menos 2 minutos (' + p.tries + ' tries). "Mortes iniciais" são as vezes em que o jogador esteve entre as 3 primeiras mortes.</div>' +
      '<table><tr><th>Jogador</th><th>Função</th><th class="n">Tries</th><th class="n">Mortes iniciais por try</th><th class="n">Defensivos por try</th><th class="n">Healthstone por try</th><th class="n">Poção de vida por try</th></tr>' +
      p.linhas.map(x => '<tr><td>' + selo(x.nome, x.classe) + '</td><td>' + ({ healer: 'Healer', tank: 'Tank', dps: 'DPS' }[x.papel] || '') + '</td><td class="n">' + x.tries + '</td><td class="n">' + x.mortesM.toFixed(2).replace('.', ',') + '</td><td class="n">' + x.pessM.toFixed(1).replace('.', ',') + '</td><td class="n">' + x.pedraM.toFixed(2).replace('.', ',') + '</td><td class="n">' + x.pocaoM.toFixed(2).replace('.', ',') + '</td></tr>').join('') + '</table>';
    return h;
  }

  // ---------- kills de referencia ----------
  const iniDaLuta = l => { const r = est.porRel[l.rel]; const f = r && r.rel.lutas.find(x => x.id === l.id); return f ? f.startTime : null; };
  async function carregarRefs(ls, forcar) {
    if (est.refsCarregando) return; est.refsCarregando = true;
    const arq = 'ref_' + chaveBoss(), [enc, dif] = est.enc.split(':').map(Number);
    try {
      let r = forcar ? null : await lerArq(arq);
      if (!r || r.v !== C.VERSAO_REF) {
        const hs = C.elencoAtual(ls).filter(j => j.papel === 'healer');
        r = await C.buscarReferencias(gql, enc, dif, hs, m => { est.refsMsg = m; if (est.aba === 'plano') desenhar(); });
        if (r.refs.length) {
          est.refsMsg = 'Comparando o ritmo de dano…'; if (est.aba === 'plano') desenhar();
          const nossas = [...ls].filter(l => iniDaLuta(l) != null).sort((a, b) => C.tWipe(b) - C.tWipe(a)).slice(0, 5).map(l => ({ code: l.rel, id: l.id, ini: iniDaLuta(l), T: C.tWipe(l) }));
          try { r.ritmo = await C.ritmoDeDano(gql, nossas, r.refs); } catch (e) { r.ritmo = null; }
        }
        await gravarArq(arq, r);
      }
      est.refs = r; est.refsMsg = null;
    } catch (e) { est.refs = null; est.refsMsg = e.message === 'COTA' ? MSG_COTA : 'Não consegui buscar as kills de referência: ' + e.message; }
    est.refsCarregando = false;
    if (est.aba === 'plano' || est.aba === 'ultima') desenhar();
  }
  function estimativa() {
    const r = est.refs; if (!r || !r.ranking || !r.refs.length) return null;
    const durRef = C.mediana(r.refs.map(x => x.durS));
    if (!r.ritmo) return { durRef, fim: durRef, ranking: r.ranking };
    const fim = durRef / r.ritmo.razao;
    return { durRef, fim: Math.max(r.ranking.rapido, Math.min(fim, r.ranking.lento)), bruto: fim, passaDoLimite: fim > r.ranking.lento + 1, razao: r.ritmo.razao, T: r.ritmo.T, ranking: r.ranking };
  }
  function planoAtual(ls) {
    const e = estimativa();
    return C.plano(ls, { jogador: est.jogador, refs: est.refs ? est.refs.refs : [], fimEstimado: e ? e.fim : null });
  }

  // ---------- Plano e note ----------
  function abaPlano(ls) {
    const healersElenco = C.elencoAtual(ls).filter(j => j.papel === 'healer').map(j => j.nome);
    if (!est.jogador || !healersElenco.includes(est.jogador)) { let salvo = null; try { salvo = localStorage.getItem('jogador'); } catch (e) { } est.jogador = healersElenco.includes(salvo) ? salvo : healersElenco[0]; }
    if (est.refs === undefined && !est.refsCarregando) carregarRefs(ls);
    const p = planoAtual(ls), e = estimativa();
    if (!p.janelas.length) return '<p class="sub">Ainda não há tries suficientes para montar um plano.</p>';
    let h = '';
    if (est.refsCarregando || est.refsMsg) h += '<div class="aviso">' + esc(est.refsMsg || 'Buscando kills de referência…') + '</div>';
    if (e) {
      h += '<h2>Até onde o plano vai</h2><div class="cards"><div class="card"><div class="k">Kill típica no ranking</div><div class="v">' + mmss(e.ranking.tipico) + '</div></div>' +
        '<div class="card"><div class="k">Kills mais rápidas / mais lentas</div><div class="v">' + mmss(e.ranking.rapido) + ' / ' + mmss(e.ranking.lento) + '</div></div>' +
        '<div class="card"><div class="k">Nosso dano vs. kills de referência</div><div class="v">' + (e.razao ? Math.round(e.razao * 100) + '%' : '—') + '</div></div>' +
        '<div class="card"><div class="k">Kill estimada no nosso ritmo</div><div class="v">' + (e.razao ? (e.passaDoLimite ? '> ' + mmss(e.ranking.lento) : mmss(e.fim)) : mmss(e.fim)) + '</div></div></div>' +
        '<div class="sub" style="margin-top:8px">' + (e.razao ? 'O dano compara os primeiros ' + mmss(e.T) + ' das nossas 5 tries mais longas com o mesmo trecho de ' + est.refs.refs.length + ' kills públicas de comp de healers parecida (' + est.refs.refs.map(r => esc(r.guilda || r.rel) + ' ' + mmss(r.durS)).join(', ') + '). ' : '') +
        (e.passaDoLimite ? '<span class="alerta">⚠ No ritmo atual a luta passaria de ' + mmss(e.ranking.lento) + ', a kill mais lenta do ranking (provável enrage). O plano vai até aí; falta cerca de ' + Math.round((1 / e.razao - e.ranking.lento / e.durRef) * 100) + '% de dano.</span> ' : '') +
        'O dano de wipes tende a ficar abaixo do de kills, porque quem morre para de bater.</div>';
    }
    h += '<div class="sub" style="margin-top:14px">O plano usa a curva de dano das nossas tries até ' + mmss(p.alcance) + (p.horizonte > p.alcance ? ' e, daí até ' + mmss(p.horizonte) + ', a curva das kills de referência ajustada ao nível de dano que a nossa raid toma.' : '. Sem kills de referência, ele para aí.') +
      ' Os tempos contam desde o pull. ' + (p.timerFixo ? 'Nestes logs as fases começam sempre no mesmo segundo, então isso funciona.' : '<span class="alerta">⚠ Nestes logs o início das fases varia entre as tries, então tempos desde o pull podem sair de sincronia.</span>') +
      ' <button class="link" id="refazerRefs">Buscar referências de novo</button></div>';
    h += est.nota ? secaoNoteBase(ls, p) : secaoDoZero(p);
    h += '<h2>Note pessoal</h2><div class="linha"><span class="sub">Jogador:</span><select id="jogador">' + healersElenco.map(n => '<option' + (n === est.jogador ? ' selected' : '') + '>' + esc(n) + '</option>').join('') + '</select></div>' +
      (p.notePessoal ? '<div class="sub">CDs menores de ' + esc(est.jogador) + ' encaixados nas janelas de dano. Cole no NSRT como note pessoal.</div><textarea id="notep" class="note curta" spellcheck="false">' + esc(p.notePessoal) + '</textarea><div class="linha"><button class="copiar" data-alvo="notep">Copiar note pessoal</button><span class="sub"></span></div>'
        : '<div class="sub">Nenhum CD menor de ' + esc(est.jogador) + ' para encaixar.</div>');
    h += secaoRendimento(ls, p);
    const cds = C.cdsDoJogador(ls, est.jogador);
    h += '<h2>Aproveitamento dos CDs de ' + esc(est.jogador) + '</h2><div class="sub">Usos feitos contra o máximo que o cooldown permitiria até o wipe, somando as tries de pelo menos 1 minuto. Um defensivo ou externo guardado de propósito aparece baixo aqui, e isso pode estar certo.</div>' +
      '<table><tr><th>CD</th><th class="n">Cooldown</th><th class="n">Usos</th><th class="n">Possíveis</th><th class="n">Aproveitamento</th></tr>' +
      cds.map(c => '<tr><td>' + hab(c.nome, c.id) + '</td><td class="n">' + mmss(c.cd) + '</td><td class="n">' + c.usos + '</td><td class="n">' + c.possiveis + '</td><td class="n">' + Math.round(100 * c.usos / c.possiveis) + '%</td></tr>').join('') + '</table>';
    return h;
  }

  // Sem note de base: plano do zero, ou colar a note atual.
  function secaoDoZero(p) {
    let h = '<h2>Note de base</h2><div class="sub">Não há note de base para este boss. Cole abaixo a note que a raid usa hoje para receber sugestões pontuais em cima dela, ou comece pelo plano gerado do zero.</div>' +
      '<textarea id="noteBase" class="note curta" spellcheck="false" placeholder="Cole aqui a note do NSRT (linhas com time:…;tag:…;spellid:…;)"></textarea>' +
      '<div class="linha"><button id="usarColada">Usar a note colada</button><button id="usarPlano" class="sec">Começar pelo plano do zero</button><span class="sub" id="okBase"></span></div>';
    h += '<h2>Plano do zero: janelas de dano e CDs sugeridos</h2><div class="sub">Em cada janela os CDs entram em sequência. "Hoje" é quando o jogador costuma usar aquele CD.</div>' +
      '<table><tr><th class="n">Janela</th><th class="n">Dano na raid</th><th>Principais fontes</th><th>CDs sugeridos</th><th>Nas kills de referência</th></tr>' +
      p.janelas.map(j => '<tr><td class="n">' + mmss(j.a) + '–' + mmss(j.b) + (j.projetada ? '<div class="sub">projetada</div>' : '') + '</td><td class="n">' + num(j.dtps) + '/s</td><td>' + j.fontes.map(f => hab(f)).join(' + ') + '</td><td>' +
        (j.atrib.length ? j.atrib.map(a => '<div>' + mmss(a.t) + ' · ' + selo(a.quem, a.classe) + ' — ' + hab(a.nome, a.id) + (a.habito != null && !j.projetada ? ' <span class="sub">(hoje ' + mmss(a.habito) + ')</span>' : '') + '</div>').join('') : '<span class="alerta">⚠ Nenhum CD livre para esta janela</span>') + '</td><td class="sub">' +
        ((j.refCds || []).map(r => esc(r.spec + ': ' + r.nome) + ' (' + r.n + ' de ' + p.nRefs + ')').join('<br>') || '') + '</td></tr>').join('') + '</table>' +
      '<textarea id="notePlano" class="note" spellcheck="false" readonly>' + esc(p.note) + '</textarea>';
    return h;
  }
  // Com note de base: cobertura por janela e sugestoes pontuais.
  function secaoNoteBase(ls, p) {
    const r = C.sugestoes(ls, est.nota, p, est.recusas); est._sugs = r.sugs;
    const SIT = { sobra: '<span class="atencao">▲ CD sobrando</span>', falta: '<span class="alerta">⚠ Pouca cobertura</span>', ok: '✓ Coberta' };
    let h = '<h2>Note de base</h2><div class="sub">Esta é a note em que as sugestões se apoiam. Aceitar uma sugestão altera só a linha dela aqui. Você também pode editar o texto à mão; a análise refaz quando você sai da caixa. A aba Última try confere a execução contra esta note.</div>' +
      '<textarea id="note" class="note" spellcheck="false">' + esc(est.nota) + '</textarea>' +
      '<div class="linha"><button class="copiar" data-alvo="note">Copiar note</button><button id="trocarNota" class="sec">Trocar a note de base</button><span class="sub" id="okNote">' + r.nEntradas + ' CDs de raid reconhecidos na note.</span></div>';
    h += '<h2>Cobertura da note em cada janela de dano</h2><table><tr><th class="n">Janela</th><th class="n">Dano na raid</th><th>Principais fontes</th><th>CDs na note</th><th>Situação</th><th>Nas kills de referência</th></tr>' +
      r.cobertura.map(c => { const j = c.j; return '<tr><td class="n">' + mmss(j.a) + '–' + mmss(j.b) + (j.projetada ? '<div class="sub">projetada</div>' : '') + '</td><td class="n">' + num(j.dtps) + '/s</td><td>' + j.fontes.map(f => hab(f)).join(' + ') + '</td><td>' +
        (c.es.map(e => '<div>' + mmss(e.t) + ' · ' + (e.jog ? selo(e.jog.nome, e.jog.classe) : esc(e.tag)) + ' — ' + hab(e.def.nome, e.id) + '</div>').join('') || '<span class="sub">nenhum</span>') + '</td><td>' + SIT[c.estado] + '</td><td class="sub">' +
        ((j.refCds || []).map(x => esc(x.spec + ': ' + x.nome) + ' (' + x.n + ' de ' + p.nRefs + ')').join('<br>') || '') + '</td></tr>'; }).join('') + '</table>';
    h += '<h2>Sugestões</h2><div class="sub">Cada sugestão é independente: aceite, recuse com um motivo (ela não volta a aparecer) ou deixe para conversar com a pessoa. Aceitar pode mudar as outras sugestões, porque a cobertura muda.</div>';
    if (!r.sugs.length) h += '<p class="sub">Nenhuma sugestão em aberto para esta note.</p>';
    const grupos = {}; r.sugs.forEach((s, i) => (grupos[s.para] = grupos[s.para] || []).push({ s, i }));
    const ordem = Object.keys(grupos).sort((a, b) => (a === 'RL' ? -1 : b === 'RL' ? 1 : a.localeCompare(b)));
    for (const g of ordem) {
      const jog = p.elenco.find(x => x.nome === g);
      h += '<div class="grupo"><div class="linha"><b>' + (g === 'RL' ? 'Para o RL' : selo(g, jog && jog.classe)) + '</b><span class="sub">' + grupos[g].length + ' sugest' + (grupos[g].length > 1 ? 'ões' : 'ão') + '</span><button class="link copiarSug" data-para="' + esc(g) + '">Copiar texto para enviar</button><span class="sub"></span></div>' +
        grupos[g].map(({ s, i }) => '<div class="sug"><div><span class="tipo">' + esc(s.tipo) + '</span> ' + linkTexto(s.texto) + '</div><div class="linha">' +
          (s.acao ? '<button class="aceitar" data-i="' + i + '">Aceitar e alterar a note</button>' : '') +
          '<input class="motivo" id="mot' + i + '" placeholder="Motivo da recusa (opcional)"><button class="sec recusar" data-i="' + i + '">Recusar</button></div></div>').join('') + '</div>';
    }
    const rec = Object.entries(est.recusas);
    if (rec.length) h += '<details class="grupo"><summary>Sugestões recusadas (' + rec.length + ')</summary>' + rec.map(([k, v]) => '<div class="sug"><div><b>' + esc(v.para) + ':</b> ' + esc(v.texto) + '</div><div class="linha"><span class="sub">' + (v.motivo ? 'Motivo: ' + esc(v.motivo) : 'Sem motivo registrado') + '</span><button class="link reconsiderar" data-k="' + esc(k) + '">Reconsiderar</button></div></div>').join('') + '</details>';
    return h;
  }

  // ---------- rendimento dos CDs (cura efetiva na janela) ----------
  const idDoAtor = (code, nome) => { const r = est.porRel[code]; if (!r) return null; const e = Object.entries(r.rel.atores).find(x => x[1].nome === nome); return e ? +e[0] : null; };
  const curaDe = nome => (est.cura[nome] = est.cura[nome] || { series: {}, lidos: new Set() });
  async function lerCuraSalva(ls, nome) {
    const c = curaDe(nome); let mudou = false;
    for (const code of new Set(ls.map(l => l.rel))) {
      if (c.lidos.has(code)) continue; c.lidos.add(code);
      const id = idDoAtor(code, nome); if (id == null) continue;
      const j = await lerArq('cura_' + code + '_' + id);
      if (j && j.lutas) for (const fid in j.lutas) { c.series[code + ':' + fid] = j.lutas[fid]; mudou = true; }
    }
    if (mudou && est.aba === 'plano') desenhar();
  }
  const faltamCura = (ls, nome) => ls.filter(l => l.durS >= 60 && l.jogadores.some(j => j.nome === nome) && !curaDe(nome).series[l.rel + ':' + l.id] && iniDaLuta(l) != null);
  async function baixarCura(ls, nome) {
    if (est.curaBaixando) return; est.curaBaixando = true;
    const c = curaDe(nome), falt = faltamCura(ls, nome);
    try {
      for (const code of new Set(falt.map(l => l.rel))) {
        const id = idDoAtor(code, nome); if (id == null) continue;
        const arq = 'cura_' + code + '_' + id, salvo = (await lerArq(arq)) || { v: 1, lutas: {} };
        const fs = falt.filter(l => l.rel === code).map(l => est.porRel[code].rel.lutas.find(f => f.id === l.id));
        for (let i = 0; i < fs.length; i += 10) {
          est.curaMsg = 'Baixando a cura de ' + nome + ': ' + Object.keys(c.series).length + ' tries prontas…'; desenhar();
          const r = await C.buscarCuraJogador(gql, code, id, fs.slice(i, i + 10));
          for (const fid in r) { salvo.lutas[fid] = r[fid]; c.series[code + ':' + fid] = r[fid]; }
          await gravarArq(arq, salvo);
        }
      }
      est.curaMsg = null;
    } catch (e) { est.curaMsg = e.message === 'COTA' ? MSG_COTA : 'Erro: ' + e.message; }
    est.curaBaixando = false; desenhar();
  }
  function secaoRendimento(ls, p) {
    const nome = est.jogador, c = curaDe(nome);
    lerCuraSalva(ls, nome);
    const falt = faltamCura(ls, nome).length, tem = Object.keys(c.series).length;
    let h = '<h2>Quanto rende cada CD de ' + esc(nome) + '</h2><div class="sub">Cura efetiva (sem overheal) de ' + esc(nome) + ' durante a janela de cada CD, comparada com a média dele na mesma try. Ao lado, quanto a raid está apanhando naquele momento. Um CD com rendimento baixo num momento de pouco dano é candidato a mudar de lugar.</div>';
    if (est.curaMsg) h += '<div class="aviso">' + esc(est.curaMsg) + '</div>';
    if (falt && !est.curaBaixando) h += '<div class="linha"><button id="baixarCura">' + (tem ? 'Baixar as ' + falt + ' tries que faltam' : 'Calcular') + '</button><span class="sub">Gasta cerca de ' + falt + ' pontos da cota da API (1 por try). Fica salvo depois.</span></div>';
    if (!tem) return h;
    const rs = C.rendimentoCds(ls, nome, c.series, C.curva(ls, 0));
    if (!rs.length) return h + '<div class="sub">Ainda não há usos suficientes para comparar.</div>';
    return h + '<table><tr><th>CD</th><th class="n">Uso</th><th class="n">Momento típico</th><th class="n">Vezes</th><th class="n">HPS na janela</th><th class="n">vs. média dele na try</th><th class="n">Dano na raid no momento</th></tr>' +
      rs.map(r => '<tr><td>' + hab(r.nome, r.id) + '</td><td class="n">' + r.ordem + 'º</td><td class="n">' + mmss(r.t) + '</td><td class="n">' + r.n + '</td><td class="n">' + num(r.hps) + '</td><td class="n">' + (r.rel >= 1 ? '+' : '−') + Math.abs(Math.round((r.rel - 1) * 100)) + '%</td><td class="n">' +
        (r.dano == null ? '—' : num(r.dano) + '/s <span class="sub">(' + (r.danoRel >= 1 ? '+' : '−') + Math.abs(Math.round((r.danoRel - 1) * 100)) + '% vs. média)</span>') + '</td></tr>').join('') + '</table>';
  }

  // ---------- Ultima try ----------
  function abaUltima(ls) {
    const l = ls[ls.length - 1], fim = C.tWipe(l);
    if (est.refs === undefined && !est.refsCarregando) carregarRefs(ls);
    const melhor = Math.min(...ls.map(x => x.pct));
    let h = '<div class="linha" style="margin-top:14px"><label class="chip"><input type="checkbox" id="aoVivo"' + (est.aoVivo ? ' checked' : '') + '> Acompanhar ao vivo (confere tries novas a cada minuto)</label><span class="sub">Use com o log ao vivo ligado no uploader do Warcraft Logs.</span></div>';
    h += '<div class="cards"><div class="card"><div class="k">Try ' + l.id + ' · ' + dataBR(l.data) + '</div><div class="v">' + (l.kill ? 'Kill' : pct(l.pct)) + '</div></div>' +
      '<div class="card"><div class="k">Duração</div><div class="v">' + mmss(l.durS) + '</div></div>' +
      '<div class="card"><div class="k">Terminou na</div><div class="v" style="font-size:16px;line-height:36px">' + esc(nomeFase(l, l.ultFase).split(':')[0]) + '</div></div>' +
      '<div class="card"><div class="k">Melhor try até agora</div><div class="v">' + pct(melhor) + '</div></div></div>';
    h += '<h2>Primeiras mortes</h2>' + (l.mortes.length ? '<table><tr><th class="n">Momento</th><th>Jogador</th><th>Golpe final</th><th>Maior dano antes de morrer</th></tr>' +
      l.mortes.slice(0, 5).map((m, i) => { const j = l.jogadores.find(x => x.nome === m.nome) || {}; return '<tr' + (i >= 3 ? ' class="apagado"' : '') + '><td class="n">' + mmss(m.t) + '</td><td>' + selo(m.nome, j.classe) + '</td><td>' + hab(m.golpe, m.golpeId) + '</td><td>' + m.dano.map(d => hab(d.nome, d.id) + ' ' + num(d.total)).join(' · ') + '</td></tr>'; }).join('') + '</table>' : '<div class="sub">Ninguém morreu.</div>');
    const nota = est.nota || planoAtual(ls).note, ad = C.aderencia(l, C.lerNote(nota));
    h += '<h2>CDs da note nesta try</h2><div class="sub">' + (est.nota ? 'Comparado com a note marcada como "em uso".' : 'Nenhuma note marcada como "em uso"; comparando com o plano sugerido atual.') + ' Só entram os CDs previstos até a 4ª morte (' + mmss(fim) + ').</div>';
    if (!ad.length) h += '<div class="sub">Nenhum CD da note cabia nesta try.</div>';
    else h += '<table><tr><th class="n">Previsto</th><th>Jogador</th><th>CD</th><th class="n">Usou em</th><th>Resultado</th></tr>' +
      ad.map(a => { const j = l.jogadores.find(x => x.nome.toLowerCase() === a.quem.toLowerCase()) || {};
        const res = !a.presente ? '<span class="sub">Jogador fora da try</span>' : a.real == null ? '<span class="alerta">✕ Não usou</span>' : Math.abs(a.delta) <= 5 ? '✓ Na hora' : '<span class="atencao">▲ ' + Math.abs(Math.round(a.delta)) + 's ' + (a.delta > 0 ? 'atrasado' : 'adiantado') + '</span>';
        return '<tr><td class="n">' + mmss(a.plano) + '</td><td>' + selo(j.nome || a.quem, j.classe) + '</td><td>' + hab(a.nome, a.id) + '</td><td class="n">' + (a.real == null ? '—' : mmss(a.real)) + '</td><td>' + res + '</td></tr>'; }).join('') + '</table>';
    const pes = l.cds.filter(c => (C.CD_POR_ID[c.id] || {}).tipo === 'pess' && c.t <= fim), quemUsou = new Set(pes.map(c => c.quem));
    const semDef = l.jogadores.filter(j => !quemUsou.has(j.nome)).map(j => selo(j.nome, j.classe));
    if (l.durS >= 60) h += '<h2>Defensivos pessoais</h2><div class="sub">' + quemUsou.size + ' de ' + l.jogadores.length + ' jogadores usaram pelo menos um defensivo pessoal.' + (semDef.length ? ' Não usaram: ' : '') + '</div>' + (semDef.length ? '<div style="margin-top:6px;line-height:1.9">' + semDef.join(' &nbsp; ') + '</div>' : '');
    return h;
  }

  // ---------- eventos ----------
  document.addEventListener('click', e => {
    const t = e.target;
    if (t.classList.contains('aba')) { est.aba = t.dataset.aba; desenhar(); }
    else if (t.id === 'refazerRefs') { est.refs = undefined; carregarRefs(lutasAtuais(), true); desenhar(); }
    else if (t.id === 'baixarCura') { baixarCura(lutasAtuais(), est.jogador); }
    else if (t.id === 'usarPlano') { est.nota = $('notePlano').value; salvarNota(); desenhar(); }
    else if (t.id === 'usarColada') { const v = $('noteBase').value.trim(); if (!C.lerNote(v).length) { $('okBase').textContent = 'Não reconheci nenhuma linha de note do NSRT nesse texto.'; } else { est.nota = v + '\n'; salvarNota(); desenhar(); } }
    else if (t.id === 'trocarNota') { if (t.dataset.certo) { est.nota = null; salvarNota(); desenhar(); } else { t.dataset.certo = '1'; t.textContent = 'Confirmar: descartar esta note de base'; } }
    else if (t.classList.contains('aceitar')) { const s = est._sugs[+t.dataset.i]; est.nota = C.aplicarSugestao(est.nota, s.acao); salvarNota(); desenhar(); }
    else if (t.classList.contains('recusar')) { const s = est._sugs[+t.dataset.i]; est.recusas[s.key] = { para: s.para, texto: s.texto, motivo: $('mot' + t.dataset.i).value.trim() }; salvarRecusas(); desenhar(); }
    else if (t.classList.contains('reconsiderar')) { delete est.recusas[t.dataset.k]; salvarRecusas(); desenhar(); }
    else if (t.classList.contains('copiarSug')) {
      const txt = est._sugs.filter(s => s.para === t.dataset.para).map(s => '• ' + s.texto).join('\n'), ok = t.nextElementSibling;
      navigator.clipboard.writeText(txt).then(() => { ok.textContent = 'Copiado.'; }, () => { ok.textContent = 'Não consegui copiar.'; });
    }
    else if (t.classList.contains('copiar')) {
      const ta = $(t.dataset.alvo), ok = t.parentNode.querySelector('.sub');
      navigator.clipboard.writeText(ta.value).then(() => { ok.textContent = 'Copiado.'; }, () => { ta.select(); ok.textContent = 'Não consegui copiar sozinho: o texto está selecionado, aperte Ctrl+C.'; });
    }
    else if (t.classList.contains('fase')) { est.fase = +t.dataset.fase; desenhar(); }
  });
  document.addEventListener('change', e => {
    const t = e.target;
    if (t.dataset.n) marcarNoites([t.dataset.n], t.checked);
    else if (t.dataset.sem) marcarNoites(est.catalogo.filter(r => C.semanaDe(r.inicio) === +t.dataset.sem).map(r => r.code), t.checked);
    else if (t.id === 'golpe') { est.golpe = t.value; desenhar(); }
    else if (t.id === 'boss') { est.enc = t.value; est.fase = null; est.refs = undefined; est.refsMsg = null; carregarNota().then(desenhar); }
    else if (t.id === 'aoVivo') { if (t.checked) est.aoVivo = setInterval(() => carregar({ aoVivo: true }), 60000); else { clearInterval(est.aoVivo); est.aoVivo = null; } }
    else if (t.id === 'comDps') { est.comDps = t.checked; desenhar(); }
    else if (t.id === 'note') { est.nota = t.value; salvarNota(); desenhar(); }
    else if (t.id === 'jogador') { est.jogador = t.value; try { localStorage.setItem('jogador', t.value); } catch (e) { } desenhar(); }
  });
  document.addEventListener('mousemove', e => { if (e.target.id === 'alvo') hoverDano(e); else if ($('dica') && !$('dica').hidden && !(e.target.closest && e.target.closest('#grafDano'))) $('dica').hidden = true; });

  (async () => {
    const s = await (await fetch('api.php?a=status')).json();
    if (!s.logado) { $('login').hidden = false; $(s.admin ? 'loginAdmin' : 'loginOutros').hidden = false; return; }
    if (s.admin) $('adminBar').hidden = false;
    $('app').hidden = false;
    $('carregar').onclick = () => {
      const novos = [...new Set($('links').value.split(/[\s,]+/).map(C.codigoDoLink).filter(Boolean))];
      if (novos.length) { $('links').value = ''; carregar({ codes: novos }); }
      else if (est.noites.size) carregar({ codes: [...est.noites] });
      else status('Cole o link de um relatório do Warcraft Logs.', true);
    };
    PT = (await lerArq('nomes_pt')) || {};
    const cat = await lerArq('relatorios'); est.catalogo = (cat && cat.lista) || [];
    if (est.catalogo.length) {
      const ult = Math.max(...est.catalogo.map(r => C.semanaDe(r.inicio)));
      await carregar({ codes: est.catalogo.filter(r => C.semanaDe(r.inicio) === ult).map(r => r.code) });
    } else {
      try { const v = localStorage.getItem('links'); if (v) $('links').value = v.split(/\s+/).join(' '); } catch (e) { }
      status('Nenhum relatório guardado ainda. Cole um ou mais links e clique em Adicionar.');
    }
  })();
})();
