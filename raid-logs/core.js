// Nucleo: busca dados na API do Warcraft Logs e calcula as analises.
// Funciona no navegador (window.WCLCore) e no Node (module.exports) para testes.
(function (root, factory) {
  if (typeof module === 'object' && module.exports) module.exports = factory();
  else root.WCLCore = factory();
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  const VERSAO_CACHE = 4;

  // tipo: raid = CD de cura/mitigacao de raid | ext = externo em um alvo | pess = defensivo pessoal | cons = consumivel
  const CDS = [
    // Paladin
    [31821, 'Aura Mastery', 'raid', 180, 8], [31884, 'Avenging Wrath', 'raid', 120, 20], [216331, 'Avenging Crusader', 'raid', 60, 12],
    [375576, 'Divine Toll', 'menor', 60, 0], [633, 'Lay on Hands', 'ext', 420, 0], [6940, 'Blessing of Sacrifice', 'ext', 120, 12],
    [642, 'Divine Shield', 'pess', 300, 8], [498, 'Divine Protection', 'pess', 60, 8], [403876, 'Divine Protection', 'pess', 90, 8], [184662, 'Shield of Vengeance', 'pess', 90, 10],
    // Shaman
    [98008, 'Spirit Link Totem', 'raid', 180, 6], [108280, 'Healing Tide Totem', 'raid', 180, 10], [114052, 'Ascendance', 'raid', 180, 15],
    [108281, 'Ancestral Guidance', 'raid', 120, 10], [108271, 'Astral Shift', 'pess', 120, 12],
    // Priest
    [64843, 'Divine Hymn', 'raid', 180, 5], [200183, 'Apotheosis', 'raid', 120, 20], [265202, 'Holy Word: Salvation', 'raid', 720, 0],
    [64901, 'Symbol of Hope', 'menor', 180, 4], [47788, 'Guardian Spirit', 'ext', 180, 10], [62618, 'Power Word: Barrier', 'raid', 180, 10],
    [271466, 'Luminous Barrier', 'raid', 180, 10], [472433, 'Evangelism', 'raid', 90, 0], [421453, 'Ultimate Penitence', 'raid', 240, 6],
    [33206, 'Pain Suppression', 'ext', 180, 8], [15286, 'Vampiric Embrace', 'raid', 120, 12], [19236, 'Desperate Prayer', 'pess', 90, 10], [47585, 'Dispersion', 'pess', 120, 6],
    // Monk
    [115310, 'Revival', 'raid', 180, 0], [388615, 'Restoral', 'raid', 180, 0], [322118, "Invoke Yu'lon", 'raid', 120, 25], [325197, 'Invoke Chi-Ji', 'raid', 120, 25],
    [443028, 'Celestial Conduit', 'raid', 90, 4], [116849, 'Life Cocoon', 'ext', 120, 12], [115203, 'Fortifying Brew', 'pess', 120, 15],
    [122783, 'Diffuse Magic', 'pess', 90, 6], [122278, 'Dampen Harm', 'pess', 120, 10], [122470, 'Touch of Karma', 'pess', 90, 10],
    // Druid
    [740, 'Tranquility', 'raid', 180, 8], [391528, 'Convoke the Spirits', 'raid', 120, 4], [33891, 'Incarnation: Tree of Life', 'raid', 180, 30],
    [197721, 'Flourish', 'raid', 90, 8], [102342, 'Ironbark', 'ext', 90, 12], [22812, 'Barkskin', 'pess', 60, 8], [61336, 'Survival Instincts', 'pess', 180, 6], [108238, 'Renewal', 'pess', 90, 0],
    // Evoker
    [363534, 'Rewind', 'raid', 240, 0], [359816, 'Dream Flight', 'raid', 120, 0], [370960, 'Emerald Communion', 'raid', 180, 5], [374227, 'Zephyr', 'raid', 120, 8],
    [357170, 'Time Dilation', 'ext', 60, 8], [363916, 'Obsidian Scales', 'pess', 90, 12], [374348, 'Renewing Blaze', 'pess', 90, 8],
    // Raid CDs de DPS/tank
    [97462, 'Rallying Cry', 'raid', 180, 10], [51052, 'Anti-Magic Zone', 'raid', 240, 6], [196718, 'Darkness', 'raid', 300, 8], [414660, 'Mass Barrier', 'raid', 180, 60],
    // Pessoais de DPS/tank
    [48707, 'Anti-Magic Shell', 'pess', 60, 5], [48792, 'Icebound Fortitude', 'pess', 120, 8], [49039, 'Lichborne', 'pess', 120, 10],
    [45438, 'Ice Block', 'pess', 240, 10], [414658, 'Ice Cold', 'pess', 240, 6], [55342, 'Mirror Image', 'pess', 120, 40], [342245, 'Alter Time', 'pess', 50, 10], [110959, 'Greater Invisibility', 'pess', 120, 3],
    [186265, 'Aspect of the Turtle', 'pess', 180, 8], [109304, 'Exhilaration', 'pess', 120, 0], [264735, 'Survival of the Fittest', 'pess', 90, 6],
    [104773, 'Unending Resolve', 'pess', 180, 8], [108416, 'Dark Pact', 'pess', 60, 20],
    [184364, 'Enraged Regeneration', 'pess', 120, 8], [118038, 'Die by the Sword', 'pess', 120, 8], [871, 'Shield Wall', 'pess', 180, 8],
    [198589, 'Blur', 'pess', 60, 10], [196555, 'Netherwalk', 'pess', 180, 6],
    [31224, 'Cloak of Shadows', 'pess', 120, 5], [5277, 'Evasion', 'pess', 120, 10], [185311, 'Crimson Vial', 'pess', 30, 4], [1966, 'Feint', 'pess', 15, 6],
    // Consumiveis
    [6262, 'Healthstone', 'cons', 0, 0], [1295247, 'Health Potion', 'cons', 300, 0],
  ].map(c => ({ id: c[0], nome: c[1], tipo: c[2], cd: c[3], dur: c[4] }));
  // Spells que so contam como CD de cura quando quem usa e healer (em DPS sao CD de dano).
  const SO_HEALER = new Set([31884, 216331, 375576, 443028, 391528]);
  const CD_POR_ID = Object.fromEntries(CDS.map(c => [c.id, c]));

  const codigoDoLink = s => { const m = /reports\/([A-Za-z0-9]{16})/.exec(s) || /^([A-Za-z0-9]{16})$/.exec(s.trim()); return m ? m[1] : null; };

  async function listarRelatorio(gql, code) {
    const d = await gql(`query($c:String!){ rateLimitData{limitPerHour pointsSpentThisHour pointsResetIn}
      reportData{report(code:$c){ title startTime endTime
        fights{id name encounterID difficulty kill bossPercentage fightPercentage lastPhase startTime endTime phaseTransitions{id startTime}}
        phases{encounterID phases{id name isIntermission}}
        masterData{actors(type:"Player"){id name subType}}
      }}}`, { c: code });
    const r = d.reportData.report;
    return {
      code, titulo: r.title, inicio: r.startTime, fim: r.endTime, cota: d.rateLimitData,
      fases: Object.fromEntries((r.phases || []).map(p => [p.encounterID, p.phases])),
      atores: Object.fromEntries(r.masterData.actors.map(a => [a.id, { nome: a.name, classe: a.subType }])),
      lutas: r.fights.filter(f => f.encounterID > 0 && f.endTime > f.startTime),
    };
  }

  function reamostrar(serie, inicio, durS) {
    const out = new Array(durS).fill(0);
    if (!serie || !serie.data || !serie.pointInterval) return out;
    const off = (serie.pointStart - inicio) / serie.pointInterval;
    for (let s = 0; s < durS; s++) {
      const i = Math.round(s * 1000 / serie.pointInterval - off);
      if (i >= 0 && i < serie.data.length) out[s] = Math.round(serie.data[i] || 0);
    }
    return out;
  }

  // Busca os dados detalhados de um lote de lutas do mesmo relatorio e devolve registros compactos.
  async function buscarLutas(gql, rel, lutas) {
    const partes = lutas.map(f => {
      const a = `fightIDs:[${f.id}],startTime:${f.startTime},endTime:${f.endTime}`;
      return `g${f.id}: graph(${a},dataType:DamageTaken,hostilityType:Friendlies,viewBy:Ability)
        d${f.id}: table(${a},dataType:Deaths)
        h${f.id}: table(${a},dataType:Healing)
        p${f.id}: playerDetails(fightIDs:[${f.id}])`;
    }).join('\n');
    const ids = CDS.map(c => c.id).join(',');
    const d = await gql(`query($c:String!,$f:[Int],$fx:String){ reportData{report(code:$c){
        ${partes}
        ev: events(fightIDs:$f,dataType:Casts,hostilityType:Friendlies,filterExpression:$fx,limit:10000){data nextPageTimestamp}
      }}}`, { c: rel.code, f: lutas.map(f => f.id), fx: `type="cast" and ability.id in (${ids})` });
    const r = d.reportData.report;
    const casts = (r.ev && r.ev.data) || [];
    return lutas.map(f => {
      const durS = Math.max(1, Math.round((f.endTime - f.startTime) / 1000));
      const pd = (((r['p' + f.id] || {}).data || {}).playerDetails) || {};
      const jogadores = [];
      for (const papel of ['tanks', 'healers', 'dps']) for (const p of (pd[papel] || []))
        jogadores.push({ nome: p.name, classe: p.type, spec: (p.specs && p.specs[0] && p.specs[0].spec) || '', papel: papel === 'healers' ? 'healer' : papel === 'tanks' ? 'tank' : 'dps' });
      const series = (((r['g' + f.id] || {}).data || {}).series) || [];
      const total = new Array(durS).fill(0);
      const habs = series.filter(s => s.name !== 'Total' && s.guid != null).map(s => {
        const v = reamostrar(s, f.startTime, durS);
        for (let i = 0; i < durS; i++) total[i] += v[i];
        return { id: s.guid, nome: s.name, total: s.total || v.reduce((a, b) => a + b, 0), v };
      }).sort((a, b) => b.total - a.total);
      const mortes = ((((r['d' + f.id] || {}).data || {}).entries) || []).map(e => ({
        nome: e.name, t: (e.timestamp - f.startTime) / 1000,
        golpe: (e.killingBlow && e.killingBlow.name) || '?',
        dano: (((e.damage || {}).abilities) || []).slice(0, 3).map(a => ({ nome: a.name, total: a.total })),
      })).sort((a, b) => a.t - b.t);
      const cura = ((((r['h' + f.id] || {}).data || {}).entries) || []).map(e => ({ nome: e.name, total: e.total, over: e.overheal || 0, ativo: e.activeTime || 0 }));
      const cds = casts.filter(e => e.fight === f.id).map(e => ({
        t: (e.timestamp - f.startTime) / 1000, id: e.abilityGameID,
        quem: (rel.atores[e.sourceID] || {}).nome || ('#' + e.sourceID),
        alvo: e.targetID > 0 ? ((rel.atores[e.targetID] || {}).nome || null) : null,
      }));
      return {
        v: VERSAO_CACHE, rel: rel.code, data: rel.inicio, id: f.id, enc: f.encounterID, boss: f.name, dif: f.difficulty,
        kill: !!f.kill, pct: f.kill ? 0 : f.bossPercentage, pctLuta: f.kill ? 0 : f.fightPercentage, ultFase: f.lastPhase || 1, durS,
        fases: (f.phaseTransitions || []).map(p => ({ id: p.id, t: (p.startTime - f.startTime) / 1000 })),
        jogadores, total, habs: habs.slice(0, 14).map(h => ({ id: h.id, nome: h.nome, total: h.total, v: h.v })),
        mortes, cura, cds,
      };
    });
  }

  // ---------- analises ----------
  const mediana = a => { if (!a.length) return null; const s = [...a].sort((x, y) => x - y); const m = s.length >> 1; return s.length % 2 ? s[m] : (s[m - 1] + s[m]) / 2; };
  const media = a => a.length ? a.reduce((x, y) => x + y, 0) / a.length : 0;

  // Momento em que a try "acabou": a 4a morte (dali em diante e cascata de wipe). Sem 4 mortes, o fim da luta.
  const N_MORTES_WIPE = 4;
  function tWipe(l) { return l.kill ? l.durS : (l.mortes.length >= N_MORTES_WIPE ? l.mortes[N_MORTES_WIPE - 1].t : l.durS); }
  function faseEm(l, t) { let id = l.fases.length ? l.fases[0].id : 1; for (const p of l.fases) if (p.t <= t + 0.001) id = p.id; return id; }
  function janelaFase(l, faseId) {
    if (faseId === 0) { const f = tWipe(l); return f > 0 ? { ini: 0, fim: f } : null; } // 0 = luta inteira, tempo desde o pull
    const i = l.fases.findIndex(p => p.id === faseId);
    if (i < 0) return null;
    const ini = l.fases[i].t, fim = Math.min(i + 1 < l.fases.length ? l.fases[i + 1].t : l.durS, tWipe(l));
    return fim > ini ? { ini, fim } : null;
  }

  function resumo(lutas) {
    const porNoite = {};
    for (const l of lutas) (porNoite[l.rel] = porNoite[l.rel] || []).push(l);
    const noites = Object.values(porNoite).map(ls => {
      const w = ls.filter(l => !l.kill);
      const fases = {}; for (const l of ls) fases[l.ultFase] = (fases[l.ultFase] || 0) + 1;
      return { rel: ls[0].rel, data: ls[0].data, tries: ls.length, kills: ls.length - w.length,
        melhor: ls.some(l => l.kill) ? 0 : Math.min(...w.map(l => l.pct)), mediana: mediana(w.map(l => l.pct)),
        durMed: mediana(ls.map(l => l.durS)), fases };
    }).sort((a, b) => a.data - b.data);
    return { noites, tries: lutas.length, melhor: lutas.length ? Math.min(...lutas.map(l => l.pct)) : null };
  }

  function mortes(lutas) {
    const causas = {}, quem = {}, porTry = [];
    const wipes = lutas.filter(l => !l.kill);
    for (const l of wipes) {
      const prim = l.mortes.slice(0, N_MORTES_WIPE - 1);
      porTry.push({ l, prim });
      const vistos = new Set();
      prim.forEach((m, i) => {
        const c = causas[m.golpe] = causas[m.golpe] || { nome: m.golpe, mortes: 0, tries: 0, primeira: 0 };
        c.mortes++; if (!vistos.has(m.golpe)) { c.tries++; vistos.add(m.golpe); } if (i === 0) c.primeira++;
        const q = quem[m.nome] = quem[m.nome] || { nome: m.nome, mortes: 0, primeira: 0, presente: 0 };
        q.mortes++; if (i === 0) q.primeira++;
      });
    }
    for (const q of Object.values(quem)) q.presente = wipes.filter(l => l.jogadores.some(j => j.nome === q.nome)).length;
    return { wipes: wipes.length,
      causas: Object.values(causas).sort((a, b) => b.mortes - a.mortes),
      quem: Object.values(quem).sort((a, b) => b.mortes - a.mortes), porTry };
  }

  // Curva media de dano recebido pela raid, alinhada no inicio da fase.
  function curva(lutas, faseId) {
    const soma = [], n = [], habs = {};
    let alcancaram = 0;
    for (const l of lutas) {
      const j = janelaFase(l, faseId); if (!j) continue; alcancaram++;
      const a = Math.ceil(j.ini), b = Math.floor(j.fim);
      for (let s = a; s < b && s < l.total.length; s++) {
        const r = s - a; soma[r] = (soma[r] || 0) + l.total[s]; n[r] = (n[r] || 0) + 1;
        for (const h of l.habs) { const x = habs[h.id] = habs[h.id] || { id: h.id, nome: h.nome, soma: [], total: 0 }; x.soma[r] = (x.soma[r] || 0) + h.v[s]; x.total += h.v[s]; }
      }
    }
    const minN = Math.max(3, Math.ceil(alcancaram * 0.2));
    let len = 0; while (len < soma.length && (n[len] || 0) >= minN) len++;
    const med = []; for (let i = 0; i < len; i++) med.push(soma[i] / n[i]);
    const suave = med.map((_, i) => media(med.slice(Math.max(0, i - 2), i + 3)));
    const habList = Object.values(habs).sort((a, b) => b.total - a.total).slice(0, 8)
      .map(h => ({ id: h.id, nome: h.nome, total: h.total, v: Array.from({ length: len }, (_, i) => (h.soma[i] || 0) / n[i]) }));
    return { faseId, alcancaram, len, med, suave, n: n.slice(0, len), habs: habList };
  }

  // Picos de dano: maximos locais da curva suavizada bem acima da media.
  function picos(c, maxPicos) {
    if (c.len < 10) return [];
    const m = media(c.suave), dp = Math.sqrt(media(c.suave.map(v => (v - m) * (v - m))));
    const cand = [];
    for (let i = 2; i < c.len - 2; i++) {
      const v = c.suave[i];
      if (v < m + 0.4 * dp) continue;
      let ok = true; for (let k = Math.max(0, i - 6); k <= Math.min(c.len - 1, i + 6); k++) if (c.suave[k] > v) { ok = false; break; }
      if (!ok) continue;
      let dom = null, domV = 0; for (const h of c.habs) { const x = media(h.v.slice(Math.max(0, i - 3), i + 4)); if (x > domV) { domV = x; dom = h.nome; } }
      cand.push({ t: i, dtps: v, rel: v / m, hab: dom, tries: c.n[i] });
    }
    return cand.sort((a, b) => b.dtps - a.dtps).slice(0, maxPicos || 10).sort((a, b) => a.t - b.t);
  }

  // Uso de CDs por fase: cada (jogador, spell) com os tempos relativos ao inicio da fase em cada try.
  function usoCds(lutas, faseId, tipos) {
    const linhas = {}; let alcancaram = 0;
    for (const l of lutas) {
      const j = janelaFase(l, faseId); if (!j) continue; alcancaram++;
      const usados = {};
      for (const c of l.cds) {
        const def = CD_POR_ID[c.id]; if (!def || !tipos.includes(def.tipo)) continue;
        if (SO_HEALER.has(c.id) && !l.jogadores.some(x => x.nome === c.quem && x.papel === 'healer')) continue;
        if (c.t < j.ini - 0.5 || c.t > j.fim) continue;
        const k = c.quem + '|' + def.nome;
        const jog = l.jogadores.find(x => x.nome === c.quem) || {};
        const ln = linhas[k] = linhas[k] || { quem: c.quem, classe: jog.classe || '', papel: jog.papel || '', id: c.id, nome: def.nome, tipo: def.tipo, cd: def.cd, usos: [], tries: 0, presente: 0 };
        const ordem = usados[k] = (usados[k] || 0) + 1;
        ln.usos.push({ t: c.t - j.ini, ordem, luta: l.id, rel: l.rel, alvo: c.alvo });
      }
      for (const k in usados) linhas[k].tries++;
    }
    for (const ln of Object.values(linhas)) {
      ln.presente = lutas.filter(l => janelaFase(l, faseId) && l.jogadores.some(j => j.nome === ln.quem)).length;
      const porOrdem = {}; for (const u of ln.usos) (porOrdem[u.ordem] = porOrdem[u.ordem] || []).push(u.t);
      ln.ordens = Object.entries(porOrdem).map(([o, ts]) => ({ ordem: +o, n: ts.length, med: mediana(ts), min: Math.min(...ts), max: Math.max(...ts) }));
    }
    const peso = { healer: 0, tank: 1, dps: 2 };
    return { alcancaram, linhas: Object.values(linhas).sort((a, b) => (peso[a.papel] ?? 3) - (peso[b.papel] ?? 3) || a.quem.localeCompare(b.quem) || a.nome.localeCompare(b.nome)) };
  }

  function healers(lutas, minDur) {
    const h = {};
    for (const l of lutas) {
      if (l.durS < (minDur || 60)) continue;
      for (const j of l.jogadores) {
        if (j.papel !== 'healer') continue;
        const c = l.cura.find(x => x.nome === j.nome); if (!c) continue;
        const x = h[j.nome] = h[j.nome] || { nome: j.nome, classe: j.classe, spec: j.spec, tries: 0, hps: [], over: [] };
        x.tries++; x.hps.push(c.total / l.durS); x.over.push(c.total + c.over > 0 ? c.over / (c.total + c.over) : 0);
      }
    }
    return Object.values(h).map(x => ({ nome: x.nome, classe: x.classe, spec: x.spec, tries: x.tries, hps: media(x.hps), hpsMed: mediana(x.hps), over: media(x.over) }))
      .sort((a, b) => b.hps - a.hps);
  }

  // Defensivos pessoais e consumiveis por jogador (media por try longa o bastante).
  function pessoais(lutas, minDur) {
    const p = {}; let n = 0;
    for (const l of lutas) {
      if (l.durS < (minDur || 120)) continue; n++;
      for (const j of l.jogadores) { const x = p[j.nome] = p[j.nome] || { nome: j.nome, classe: j.classe, papel: j.papel, tries: 0, pess: 0, pedra: 0, pocao: 0, mortesCedo: 0 }; x.tries++; }
      for (const c of l.cds) {
        const def = CD_POR_ID[c.id], x = p[c.quem]; if (!def || !x) continue;
        if (def.tipo === 'pess') x.pess++; else if (c.id === 6262) x.pedra++; else if (def.tipo === 'cons') x.pocao++;
      }
      for (const m of l.mortes.slice(0, N_MORTES_WIPE - 1)) if (p[m.nome]) p[m.nome].mortesCedo++;
    }
    return { tries: n, linhas: Object.values(p).filter(x => x.tries >= 3).map(x => ({ ...x, pessM: x.pess / x.tries, pedraM: x.pedra / x.tries, pocaoM: x.pocao / x.tries, mortesM: x.mortesCedo / x.tries }))
      .sort((a, b) => b.mortesM - a.mortesM) };
  }

  // ---------- kills publicos de referencia ----------
  const VERSAO_REF = 2;
  async function buscarReferencias(gql, enc, dif, specsHealers, prog) {
    prog = prog || (() => { });
    prog('Buscando o ranking público de kills…');
    const d = await gql(`query($e:Int!,$d:Int!){worldData{encounter(id:$e){ r1: fightRankings(difficulty:$d,page:1) r10: fightRankings(difficulty:$d,page:10) r16: fightRankings(difficulty:$d,page:16) r20: fightRankings(difficulty:$d,page:20) r4: fightRankings(difficulty:$d,page:4) }}}`, { e: enc, d: dif });
    const e = d.worldData.encounter, lista = k => (e[k] && e[k].rankings) || [];
    const tip = lista('r10').length ? lista('r10') : lista('r4').length ? lista('r4') : lista('r1');
    if (!tip.length) return { v: VERSAO_REF, quando: Date.now(), refs: [], ranking: null };
    const todos = [...lista('r1'), ...lista('r4'), ...lista('r10'), ...lista('r16'), ...lista('r20')].map(x => x.duration / 1000);
    const ranking = { rapido: Math.min(...todos), tipico: mediana(tip.map(x => x.duration / 1000)), lento: Math.max(...todos) };
    // candidatos espalhados pela pagina tipica; escolhe os de comp de healers mais parecida
    const cands = []; const passo = Math.max(1, Math.floor(tip.length / 12));
    for (let i = 0; i < tip.length && cands.length < 12; i += passo) if (tip[i].report && tip[i].report.code) cands.push(tip[i]);
    prog('Comparando a comp de healers de ' + cands.length + ' kills…');
    const q = cands.map((c, i) => `c${i}: reportData{report(code:"${c.report.code}"){playerDetails(fightIDs:[${c.report.fightID}])}}`).join('\n');
    const pd = await gql('{' + q + '}');
    const nossos = specsHealers.map(x => x.classe + ':' + x.spec);
    cands.forEach((c, i) => {
      const hs = ((((pd['c' + i] || {}).report || {}).playerDetails || {}).data || {}).playerDetails;
      const deles = ((hs && hs.healers) || []).map(p => p.type + ':' + ((p.specs && p.specs[0] && p.specs[0].spec) || ''));
      const resto = [...deles]; let iguais = 0;
      for (const n of nossos) { const k = resto.indexOf(n); if (k >= 0) { iguais++; resto.splice(k, 1); } }
      c.iguais = iguais; c.healers = deles;
    });
    const escolhidos = cands.filter(c => c.healers.length).sort((a, b) => b.iguais - a.iguais).slice(0, 4);
    const refs = [];
    for (const c of escolhidos) {
      prog('Baixando kill de referência ' + (refs.length + 1) + ' de ' + escolhidos.length + '…');
      try {
        const rel = await listarRelatorio(gql, c.report.code);
        const f = rel.lutas.find(x => x.id === c.report.fightID); if (!f) continue;
        const [rec] = await buscarLutas(gql, rel, [f]);
        rec.guilda = (c.guild && c.guild.name) || ''; rec.iguais = c.iguais; rec.ini = f.startTime;
        refs.push(rec);
      } catch (err) { if (err.message === 'COTA') throw err; }
    }
    return { v: VERSAO_REF, quando: Date.now(), refs, ranking };
  }

  // Dano total da raid nos primeiros T segundos: nossas tries mais longas contra as kills de referencia.
  async function ritmoDeDano(gql, nossas, refs) {
    if (!nossas.length || !refs.length) return null;
    const T = Math.floor(Math.min(...nossas.map(x => x.T)));
    const itens = [...nossas.map(x => ({ code: x.code, id: x.id, ini: x.ini, nosso: true })), ...refs.map(r => ({ code: r.rel, id: r.id, ini: r.ini, nosso: false }))];
    const q = itens.map((x, i) => `t${i}: reportData{report(code:"${x.code}"){table(fightIDs:[${x.id}],startTime:${x.ini},endTime:${x.ini + T * 1000},dataType:DamageDone)}}`).join('\n');
    const d = await gql('{' + q + '}');
    const dps = itens.map((x, i) => { const es = ((((d['t' + i] || {}).report || {}).table || {}).data || {}).entries || []; return es.reduce((a, e) => a + (e.total || 0), 0) / T; });
    const nosso = mediana(dps.filter((v, i) => itens[i].nosso && v > 0)), deles = mediana(dps.filter((v, i) => !itens[i].nosso && v > 0));
    return nosso && deles ? { T, nosso, deles, razao: nosso / deles } : null;
  }

  // Cura efetiva por segundo de um jogador em um lote de lutas (1 ponto de cota por luta).
  async function buscarCuraJogador(gql, code, actorId, lutasApi) {
    const q = lutasApi.map(f => `h${f.id}: graph(fightIDs:[${f.id}],startTime:${f.startTime},endTime:${f.endTime},dataType:Healing,sourceID:${actorId},viewBy:Ability)`).join('\n');
    const d = await gql(`query($c:String!){reportData{report(code:$c){${q}}}}`, { c: code });
    const out = {};
    for (const f of lutasApi) {
      const durS = Math.max(1, Math.round((f.endTime - f.startTime) / 1000)), tot = new Array(durS).fill(0);
      for (const s of ((((d.reportData.report['h' + f.id] || {}).data || {}).series) || [])) { if (s.name === 'Total' || s.guid == null) continue; const v = reamostrar(s, f.startTime, durS); for (let i = 0; i < durS; i++) tot[i] += v[i]; }
      out[f.id] = tot;
    }
    return out;
  }

  // Quanto o jogador curou na janela de cada CD, contra a media dele na mesma try.
  function rendimentoCds(lutas, nome, curaPorLuta, curvaAbs) {
    const g = {};
    for (const l of lutas) {
      const serie = curaPorLuta[l.rel + ':' + l.id]; if (!serie || l.durS < 60) continue;
      const fim = Math.min(Math.floor(tWipe(l)), serie.length); if (fim < 30) continue;
      const mediaTry = media(serie.slice(0, fim)); if (mediaTry <= 0) continue;
      const ordem = {};
      for (const c of l.cds) {
        const d = CD_POR_ID[c.id]; if (c.quem !== nome || !d || !['raid', 'menor'].includes(d.tipo)) continue;
        const o = ordem[c.id] = (ordem[c.id] || 0) + 1, W = Math.max(8, Math.min(d.dur || 0, 20)), a = Math.floor(c.t);
        if (a + W > fim) continue; // janela cortada pelo wipe nao conta
        const hps = media(serie.slice(a, a + W));
        const k = c.id + ':' + o, x = g[k] = g[k] || { id: c.id, nome: d.nome, ordem: o, janela: W, ts: [], hps: [], rel: [] };
        x.ts.push(c.t); x.hps.push(hps); x.rel.push(hps / mediaTry);
      }
    }
    return Object.values(g).filter(x => x.ts.length >= 3).map(x => {
      const t = mediana(x.ts), a = Math.floor(t);
      const dano = curvaAbs && a < curvaAbs.len ? media(curvaAbs.suave.slice(a, Math.min(curvaAbs.len, a + x.janela))) : null;
      return { id: x.id, nome: x.nome, ordem: x.ordem, janela: x.janela, n: x.ts.length, t, hps: mediana(x.hps), rel: mediana(x.rel), dano, danoRel: dano && curvaAbs ? dano / media(curvaAbs.suave) : null };
    }).sort((a, b) => a.nome.localeCompare(b.nome) || a.ordem - b.ordem);
  }

  // Le uma note do NSRT (linhas com time/tag/spellid ou text).
  function lerNote(txt) {
    const out = [];
    String(txt || '').split('\n').forEach((linha, i) => {
      const t = /time:(-?\d*\.?\d+)/.exec(linha), tag = /tag:([^;]+)/.exec(linha), sp = /spellid:(\d+)/.exec(linha), tx = /text:([^;]+)/.exec(linha);
      if (t && tag && (sp || tx)) out.push({ t: +t[1], tag: tag[1].trim(), id: sp ? +sp[1] : null, texto: tx ? tx[1] : null, linha: i });
    });
    return out;
  }
  // Confere, em uma try, cada CD da note: usado na hora, fora de hora ou nao usado.
  function aderencia(l, note) {
    const fim = tWipe(l), usados = new Set();
    return note.filter(n => n.id && n.tag.toLowerCase() !== 'everyone' && n.t <= fim - 3).map(n => {
      const cs = l.cds.map((c, i) => ({ c, i })).filter(x => x.c.id === n.id && x.c.quem.toLowerCase() === n.tag.toLowerCase() && !usados.has(x.i))
        .sort((x, y) => Math.abs(x.c.t - n.t) - Math.abs(y.c.t - n.t));
      const m = cs[0] && Math.abs(cs[0].c.t - n.t) <= 25 ? cs[0] : null;
      if (m) usados.add(m.i);
      const presente = l.jogadores.some(j => j.nome.toLowerCase() === n.tag.toLowerCase());
      return { quem: n.tag, id: n.id, nome: (CD_POR_ID[n.id] || {}).nome || ('spell ' + n.id), plano: n.t, real: m ? m.c.t : null, delta: m ? m.c.t - n.t : null, presente };
    });
  }

  // ---------- plano de CDs e note do NSRT ----------
  const DIF_NSRT = { 3: 'Normal', 4: 'Heroic', 5: 'Mythic' };
  const durEfetiva = c => Math.max(4, Math.min(c.dur || 0, 12));

  // Trechos da curva em que o dano fica bem acima da media.
  function janelasDeDano(c) {
    const m = media(c.suave), dp = Math.sqrt(media(c.suave.map(v => (v - m) * (v - m)))), lim = m + 0.4 * dp;
    const js = []; let cur = null;
    for (let i = 0; i < c.len; i++) {
      if (c.suave[i] >= lim) { if (cur && i - cur.b <= 4) cur.b = i; else { cur = { a: i, b: i }; js.push(cur); } }
    }
    return js.filter(j => j.b - j.a >= 2).map(j => {
      let pico = j.a, sev = 0; const fontes = {};
      for (let i = j.a; i <= j.b; i++) { if (c.suave[i] > c.suave[pico]) pico = i; sev += c.suave[i] - m; for (const h of c.habs) fontes[h.nome] = (fontes[h.nome] || 0) + h.v[i]; }
      const f = Object.entries(fontes).sort((x, y) => y[1] - x[1]).slice(0, 2).map(x => x[0]);
      return { a: j.a, b: j.b, pico, dtps: c.suave[pico], rel: c.suave[pico] / m, sev, fontes: f, tries: c.n[pico] };
    });
  }

  // Quem esta na comp atual: presente em pelo menos metade das tries da noite mais recente.
  function elencoAtual(lutas) {
    const ult = Math.max(...lutas.map(l => l.data)), ls = lutas.filter(l => l.data === ult), cont = {};
    for (const l of ls) for (const j of l.jogadores) { const x = cont[j.nome] = cont[j.nome] || { ...j, n: 0 }; x.n++; }
    return Object.values(cont).filter(x => x.n >= ls.length / 2);
  }

  function plano(lutas, opts) {
    opts = opts || {};
    const nossa = curva(lutas, 0), refs = (opts.refs || []).filter(r => r.kill);
    let c = nossa, escala = null, horizonte = nossa.len;
    if (refs.length >= 2) {
      const cr = curva(refs, 0);
      // corta o horizonte na estimativa de kill, se houver
      const fimRef = Math.min(cr.len, opts.fimEstimado ? Math.round(opts.fimEstimado) : cr.len);
      if (fimRef > nossa.len + 5) {
        const sob = Math.min(nossa.len, cr.len);
        escala = sob >= 30 ? media(nossa.suave.slice(0, sob)) / media(cr.suave.slice(0, sob)) : 1;
        const nomes = [...new Set([...nossa.habs, ...cr.habs].map(h => h.nome))];
        const pega = (cv, nome, i, k) => { const h = cv.habs.find(x => x.nome === nome); return h && i < cv.len ? (h.v[i] || 0) * k : 0; };
        c = { faseId: 0, alcancaram: nossa.alcancaram, len: fimRef,
          suave: Array.from({ length: fimRef }, (_, i) => i < nossa.len ? nossa.suave[i] : cr.suave[i] * escala),
          med: Array.from({ length: fimRef }, (_, i) => i < nossa.len ? nossa.med[i] : cr.med[i] * escala),
          n: Array.from({ length: fimRef }, (_, i) => i < nossa.len ? nossa.n[i] : 0),
          habs: nomes.map(nome => ({ nome, v: Array.from({ length: fimRef }, (_, i) => i < nossa.len ? pega(nossa, nome, i, 1) : pega(cr, nome, i, escala)) })) };
        horizonte = fimRef;
      }
    }
    const js = janelasDeDano(c);
    for (const j of js) j.projetada = j.a >= nossa.len;
    // o que as kills de referencia usam em cada janela (por spec)
    if (refs.length) for (const j of js) {
      const cont = {};
      for (const r of refs) { const vistos = new Set(); for (const cd of r.cds) { const d = CD_POR_ID[cd.id]; if (!d || d.tipo !== 'raid' || cd.t < j.a - 8 || cd.t > j.b) continue; const jog = r.jogadores.find(x => x.nome === cd.quem) || {}; if (SO_HEALER.has(cd.id) && jog.papel !== 'healer') continue; const k = (jog.spec || '') + ' ' + (jog.classe || '') + '|' + d.nome; if (!vistos.has(k)) { vistos.add(k); cont[k] = (cont[k] || 0) + 1; } } }
      j.refCds = Object.entries(cont).filter(x => x[1] >= Math.max(2, refs.length / 2)).sort((x, y) => y[1] - x[1]).map(x => ({ spec: x[0].split('|')[0].trim(), nome: x[0].split('|')[1], n: x[1] }));
    }
    const elenco = elencoAtual(lutas), noElenco = new Set(elenco.map(j => j.nome));
    const uso = usoCds(lutas, 0, ['raid', 'menor']);
    // CDs disponiveis = os que cada jogador do elenco atual ja usou nestes logs (prova de que tem o talento).
    const disp = uso.linhas.filter(ln => noElenco.has(ln.quem) && ln.tries >= Math.max(2, ln.presente * 0.1));
    const raid = disp.filter(ln => ln.tipo === 'raid');
    const marc = {}; // quem|id -> [tempos ja marcados]
    const livre = (ln, t) => (marc[ln.quem + '|' + ln.id] || []).every(x => Math.abs(x - t) >= ln.cd);
    const habito = (ln, a, b) => ln.ordens.some(o => o.n >= 3 && o.med >= a - 8 && o.med <= b + 3);
    for (const j of js) { j.atrib = []; j.coberto = 0; }
    // Tres rodadas (1, 2 e ate o maximo de CDs por janela), para nenhuma janela ficar sem nada enquanto outra acumula.
    for (const rodada of [1, 2, 9]) for (const j of [...js].sort((x, y) => y.sev - x.sev)) {
      const comprimento = j.b - j.a + 4, maxCds = Math.min(rodada, j.rel >= 1.45 ? 4 : j.rel >= 1.3 ? 3 : 2);
      const cand = [...raid].sort((x, y) => (habito(y, j.a, j.b) - habito(x, j.a, j.b)) || ((x.papel === 'healer' ? 0 : 1) - (y.papel === 'healer' ? 0 : 1)) || (y.cd - x.cd));
      for (const ln of cand) {
        if (j.coberto >= comprimento || j.atrib.length >= maxCds) break;
        if (j.atrib.some(a => a.quem === ln.quem)) continue; // um CD por jogador por janela
        let t = Math.max(0, j.a - 2 + j.coberto), ok = livre(ln, t);
        for (let k = 1; !ok && t + k <= j.b - 2; k++) if (livre(ln, t + k)) { t += k; ok = true; }
        if (!ok) continue;
        if (CANALIZADOS.has(ln.id) && (((BOSS[lutas[0].enc] || {})[lutas[0].dif] || {}).movimento || []).some(mv => t >= mv.t - 4 && t <= mv.t + mv.dur)) continue;
        (marc[ln.quem + '|' + ln.id] = marc[ln.quem + '|' + ln.id] || []).push(t);
        const hab = ln.ordens.filter(o => o.n >= 3).map(o => o.med).sort((x, y) => Math.abs(x - t) - Math.abs(y - t))[0];
        j.atrib.push({ quem: ln.quem, classe: ln.classe, papel: ln.papel, id: ln.id, nome: ln.nome, t, habito: hab == null ? null : hab });
        j.coberto += durEfetiva(ln);
      }
    }
    for (const j of js) j.atrib.sort((x, y) => x.t - y.t);
    // Defensivo pessoal para a raid toda: janelas mais fortes, com pelo menos 90s entre os avisos.
    const avisos = [];
    for (const j of [...js].sort((x, y) => y.rel - x.rel)) if (j.rel >= 1.3 && avisos.every(t => Math.abs(t - j.a) >= 90)) avisos.push(Math.max(0, j.a - 1));
    avisos.sort((x, y) => x - y);
    // CDs que sobraram sem uso no plano.
    const semUso = raid.filter(ln => !(marc[ln.quem + '|' + ln.id] || []).length).map(ln => ({ quem: ln.quem, classe: ln.classe, nome: ln.nome }));
    // Note pessoal do jogador escolhido: CDs menores dele nas janelas, respeitando o cooldown.
    const pess = [];
    if (opts.jogador) for (const ln of disp.filter(x => x.tipo === 'menor' && x.quem === opts.jogador)) {
      const ts = [];
      for (const j of js) { const t = Math.max(0, j.a - 1); if (ts.every(x => Math.abs(x - t) >= ln.cd)) { ts.push(t); pess.push({ t, id: ln.id, nome: ln.nome }); } }
    }
    pess.sort((x, y) => x.t - y.t);
    const l0 = lutas[lutas.length - 1];
    const cab = 'EncounterID:' + l0.enc + ';Difficulty:' + (DIF_NSRT[l0.dif] || 'Mythic') + ';Name:' + l0.boss + ';';
    const linhas = [];
    for (const j of js) for (const a of j.atrib) linhas.push({ t: a.t, s: 'time:' + a.t + ';ph:1;tag:' + a.quem + ';spellid:' + a.id + ';' });
    for (const t of avisos) linhas.push({ t, s: 'time:' + t + ';ph:1;tag:everyone;text:Defensivo pessoal;TTS:Defensivo pessoal;TTSTimer:3;' });
    linhas.sort((x, y) => x.t - y.t);
    const note = [cab, ...linhas.map(x => x.s)].join('\n') + '\n';
    const notePessoal = pess.length ? [cab, ...pess.map(x => 'time:' + x.t + ';ph:1;tag:' + opts.jogador + ';spellid:' + x.id + ';')].join('\n') + '\n' : '';
    // As fases comecam sempre no mesmo segundo? (luta com timer fixo -> tempo desde o pull funciona)
    const inicios = {}; for (const l of lutas) for (const p of l.fases.slice(1)) (inicios[p.id] = inicios[p.id] || []).push(p.t);
    const timerFixo = Object.values(inicios).every(a => Math.max(...a) - Math.min(...a) <= 3);
    return { disponiveis: raid, curva: c, janelas: js, avisos, semUso, note, notePessoal, pess, timerFixo, elenco, alcance: nossa.len, horizonte, nRefs: refs.length, escala };
  }

  // ---------- sugestoes em cima de uma note existente ----------
  // Particularidades por boss (tempos desde o pull, da timeline do NSRT).
  const BOSS = { 3492: { 5: {
    movimento: [56, 113, 427, 482, 532, 576].map(t => ({ nome: 'Caustic Waves', t, dur: 10 })),
    amp: [145.5, 294.6, 583.7].map(t => ({ nome: 'Venomous Heart', t, dur: 20 })),
  } } };
  const CANALIZADOS = new Set([64843, 740, 443028, 391528, 421453]);
  const mmss = t => { t = Math.round(t); return Math.floor(t / 60) + ':' + String(t % 60).padStart(2, '0'); };
  const pc = r => (r >= 1 ? '+' : '−') + Math.abs(Math.round((r - 1) * 100)) + '%';

  function ordenarNote(linhas) {
    const idx = [], ts = [];
    linhas.forEach((l, i) => { const m = /time:(-?\d*\.?\d+)/.exec(l); if (m && /tag:/.test(l)) { idx.push(i); ts.push({ l, t: +m[1] }); } });
    ts.sort((x, y) => x.t - y.t); idx.forEach((i, k) => { linhas[i] = ts[k].l; });
    return linhas;
  }
  function aplicarSugestao(txt, acao) {
    let linhas = String(txt).replace(/\n+$/, '').split('\n');
    if (acao.tipo === 'mover') linhas[acao.linha] = linhas[acao.linha].replace(/time:-?\d*\.?\d+/, 'time:' + acao.novoT);
    else if (acao.tipo === 'adicionar') linhas.push(acao.texto);
    return ordenarNote(linhas).join('\n') + '\n';
  }

  function sugestoes(lutas, txt, p, recusas) {
    recusas = recusas || {};
    const l0 = lutas[lutas.length - 1], boss = (BOSS[l0.enc] || {})[l0.dif] || { movimento: [], amp: [] };
    const note = lerNote(txt), cv = p.curva, m = media(cv.suave);
    const elenco = {}; for (const j of p.elenco) elenco[j.nome.toLowerCase()] = j;
    const raidE = note.filter(e => e.id && (CD_POR_ID[e.id] || {}).tipo === 'raid').map(e => ({ ...e, def: CD_POR_ID[e.id], jog: elenco[e.tag.toLowerCase()] || null, tEf: e.t }))
      .filter(e => !(SO_HEALER.has(e.id) && e.jog && e.jog.papel !== 'healer'));
    const quem = e => e.jog ? e.jog.nome : e.tag;
    const cob = p.janelas.map(j => { const es = raidE.filter(e => e.t >= j.a - 8 && e.t <= j.b); return { j, es, cover: es.reduce((a, e) => a + durEfetiva(e.def), 0), need: j.b - j.a + 4 }; });
    const falta = c => c.cover < c.need * 0.5, sobra = c => c.cover >= c.need * 1.25 && c.es.length >= 3;
    for (const c of cob) { c.estado = sobra(c) ? 'sobra' : falta(c) ? 'falta' : 'ok'; c.cover0 = c.cover; c.n0 = c.es.length; c.lista0 = c.es.map(e => quem(e) + ' ' + e.def.nome); }
    const livre = (tag, id, cd, t, menos) => raidE.every(o => o === menos || o.id !== id || o.tag.toLowerCase() !== tag.toLowerCase() || Math.abs(o.tEf - t) >= cd);
    const emMovimento = (id, t) => CANALIZADOS.has(id) ? boss.movimento.find(mv => t >= mv.t - 4 && t <= mv.t + mv.dur) : null;
    const danoEm = t => { const i = Math.floor(t); return i >= 0 && i < cv.len ? cv.suave[i] : null; };
    const sugs = [], add = s => { if (s.key in recusas) return false; sugs.push(s); return true; };
    const fraseCd = (e, t) => {
      const outros = raidE.filter(o => o !== e && o.id === e.id && o.tag.toLowerCase() === e.tag.toLowerCase()).sort((x, y) => x.tEf - y.tEf);
      const prox = outros.find(o => o.tEf > t), ant = [...outros].reverse().find(o => o.tEf < t);
      if (!prox && !ant) return 'Não há outro uso desse CD na note, então nada mais muda.';
      return (ant ? 'O uso anterior, aos ' + mmss(ant.tEf) + ', já terá voltado. ' : '') + (prox ? 'O uso seguinte, aos ' + mmss(prox.tEf) + ', não é afetado: o CD volta aos ' + mmss(t + e.def.cd) + '.' : '');
    };

    // 1) conflitos de cooldown dentro da propria note
    const vistos = new Set();
    for (const e of raidE) for (const o of raidE) {
      if (o === e || o.id !== e.id || o.tag.toLowerCase() !== e.tag.toLowerCase() || o.t <= e.t || o.t - e.t >= e.def.cd) continue;
      const k = 'conflito|' + e.tag + '|' + e.id + '|' + e.t + '|' + o.t; if (vistos.has(k)) continue; vistos.add(k);
      add({ key: k, para: quem(e), tipo: 'Cooldown', texto: 'O ' + e.def.nome + ' aparece aos ' + mmss(e.t) + ' e de novo aos ' + mmss(o.t) + ', mas o cooldown é de ' + mmss(e.def.cd) + ': o segundo uso só estaria pronto aos ' + mmss(e.t + e.def.cd) + '.', acao: null });
    }
    // 2) canalizado em cima de movimento
    for (const e of raidE) { const mv = emMovimento(e.id, e.t); if (mv) add({ key: 'mov|' + e.tag + '|' + e.id + '|' + e.t, para: quem(e), tipo: 'Movimento', texto: 'O ' + e.def.nome + ' está aos ' + mmss(e.t) + ' e é canalizado, mas aos ' + mmss(mv.t) + ' tem ' + mv.nome + ', momento de movimento. Risco de cortar o cast.', acao: null }); }

    // 3) mover CDs: primeiro os que estao fora de qualquer janela, depois os de janelas com sobra
    const cands = [];
    for (const e of raidE) if (!cob.some(c => c.es.includes(e))) { const d = danoEm(e.t); if (d != null && d < m) cands.push({ e, de: null, d }); }
    for (const c of [...cob].sort((x, y) => (y.cover - y.need) - (x.cover - x.need))) if (c.estado === 'sobra') for (const e of [...c.es].reverse()) cands.push({ e, de: c });
    for (const cd of cands) {
      const e = cd.e;
      if (cd.de && cd.de.cover - durEfetiva(e.def) < cd.de.need) continue; // tirar este deixaria a janela descoberta
      for (const alvo of cob.filter(falta).sort((x, y) => (x.j.projetada ? 1 : 0) - (y.j.projetada ? 1 : 0) || y.j.rel - x.j.rel)) {
        if (alvo === cd.de) continue;
        const novoT = Math.max(0, alvo.j.a - 2 + alvo.cover);
        if (!livre(e.tag, e.id, e.def.cd, novoT, e) || emMovimento(e.id, novoT)) continue;
        const key = 'mover|' + e.tag + '|' + e.id + '|' + e.t + '|' + novoT;
        const origem = cd.de ? 'numa janela que já tem ' + cd.de.n0 + ' CDs (' + cd.de.lista0.join(', ') + ')' : 'num momento de dano baixo (' + pc(cd.d / m) + ' em relação à média da luta)';
        const ok = add({ key, para: quem(e), tipo: 'Mover', acao: { tipo: 'mover', linha: e.linha, novoT },
          texto: 'O ' + e.def.nome + ' está marcado para ' + mmss(e.t) + ', ' + origem + '. Que tal mover para ' + mmss(novoT) + '? Ali a raid toma ' + (alvo.j.dtps / 1e6).toFixed(1).replace('.', ',') + ' M/s (' + pc(alvo.j.rel) + ' vs. média, ' + alvo.j.fontes.join(' + ') + ') e a note ' + (alvo.n0 ? 'só tem ' + alvo.n0 + ' CD' + (alvo.n0 > 1 ? 's' : '') : 'não tem nenhum CD') + (alvo.j.projetada ? '. Essa janela é projetada pelas kills de referência, a raid ainda não chegou lá' : '') + '. ' + fraseCd(e, novoT) });
        if (!ok) continue;
        if (cd.de) cd.de.cover -= durEfetiva(e.def);
        alvo.cover += durEfetiva(e.def); e.tEf = novoT; break;
      }
    }
    // 4) adicionar CD livre nas janelas que continuam com pouca cobertura
    const extras = [];
    for (const alvo of cob.filter(falta).sort((x, y) => y.j.rel - x.j.rel)) {
      const novoT = Math.max(0, alvo.j.a - 2 + alvo.cover);
      for (const ln of [...p.disponiveis].sort((x, y) => (x.papel === 'healer' ? 0 : 1) - (y.papel === 'healer' ? 0 : 1) || y.cd - x.cd)) {
        if (!livre(ln.quem, ln.id, ln.cd, novoT, null) || extras.some(x => x.quem === ln.quem && x.id === ln.id && Math.abs(x.t - novoT) < ln.cd) || emMovimento(ln.id, novoT)) continue;
        const usos = raidE.filter(o => o.id === ln.id && o.tag.toLowerCase() === ln.quem.toLowerCase()).map(o => mmss(o.tEf));
        const key = 'add|' + ln.quem + '|' + ln.id + '|' + novoT;
        const ok = add({ key, para: ln.quem, tipo: 'Adicionar', acao: { tipo: 'adicionar', texto: 'time:' + novoT + ';ph:1;tag:' + ln.quem + ';spellid:' + ln.id + ';' },
          texto: 'A janela ' + mmss(alvo.j.a) + '–' + mmss(alvo.j.b) + ' (' + (alvo.j.dtps / 1e6).toFixed(1).replace('.', ',') + ' M/s, ' + pc(alvo.j.rel) + ' vs. média, ' + alvo.j.fontes.join(' + ') + ') ' + (alvo.n0 ? 'só tem ' + alvo.n0 + ' CD na note' : 'não tem nenhum CD na note') + (alvo.j.projetada ? ' (janela projetada pelas kills de referência)' : '') + '. O ' + ln.nome + ' está livre nesse momento' + (usos.length ? ' (na note ele aparece aos ' + usos.join(', ') + ')' : ' (não aparece na note)') + '. Sugestão: adicionar aos ' + mmss(novoT) + '.' });
        if (!ok) continue;
        extras.push({ quem: ln.quem, id: ln.id, t: novoT }); alvo.cover += durEfetiva(ln); break;
      }
    }
    // 5) habito: a note diz uma coisa e o jogador costuma fazer outra
    for (const e of raidE) {
      if (!e.jog || e.t >= p.alcance - 10) continue;
      const ln = p.disponiveis.find(x => x.quem === e.jog.nome && x.id === e.id); if (!ln) continue;
      const o = ln.ordens.filter(x => x.n >= 5).sort((x, y) => Math.abs(x.med - e.t) - Math.abs(y.med - e.t))[0];
      if (!o || Math.abs(o.med - e.t) <= 10 || Math.abs(o.med - e.t) > 45) continue;
      const novoT = Math.round(o.med);
      add({ key: 'habito|' + e.tag + '|' + e.id + '|' + e.t, para: quem(e), tipo: 'Execução', acao: livre(e.tag, e.id, e.def.cd, novoT, e) ? { tipo: 'mover', linha: e.linha, novoT } : null,
        texto: 'Na note o ' + e.def.nome + ' está aos ' + mmss(e.t) + ', mas nas tries ele costuma sair aos ' + mmss(o.med) + ' (' + o.n + ' usos, entre ' + mmss(o.min) + ' e ' + mmss(o.max) + '). Vale conversar: ou ele segue a note, ou a note passa a refletir o que ele faz (aceitar muda a note para ' + mmss(novoT) + ').' });
    }
    // 6) para o RL: call de defensivo e aviso de burst
    const textos = note.filter(e => e.texto && !e.id), maior = Math.max(...p.janelas.map(j => j.rel)), postos = textos.filter(e => /defens/i.test(e.texto)).map(e => e.t);
    for (const c of [...cob].sort((x, y) => y.j.rel - x.j.rel)) {
      const j = c.j; if (j.rel < 1.3) continue;
      const t = Math.max(0, j.a - 1);
      if (textos.some(e => /defens/i.test(e.texto) && e.t >= j.a - 8 && e.t <= j.b) || postos.some(x => Math.abs(x - t) < 60)) continue;
      const motivo = j.rel === maior ? 'é o maior pico de dano da luta' : c.estado === 'falta' ? 'é um momento de muito dano e pouca cobertura de CD de cura (' + (c.n0 || 'nenhum') + ' na note)' : 'o dano fica ' + pc(j.rel).replace('+', '') + ' acima da média da luta';
      if (add({ key: 'def|' + t, para: 'RL', tipo: 'Call de defensivo', acao: { tipo: 'adicionar', texto: 'time:' + t + ';ph:1;tag:everyone;text:Defensivo pessoal;TTS:Defensivo pessoal;TTSTimer:3;' },
        texto: 'Incluir call de defensivo pessoal aos ' + mmss(t) + ': ' + motivo + ' (' + (j.dtps / 1e6).toFixed(1).replace('.', ',') + ' M/s, ' + j.fontes.join(' + ') + ')' + (j.projetada ? '. Janela projetada pelas kills de referência' : '') + '.' })) postos.push(t);
    }
    for (const a of boss.amp) {
      if (a.t > p.horizonte || textos.some(e => Math.abs(e.t - a.t) <= 10 && /burst|cora|heart|cd/i.test(e.texto))) continue;
      const t = Math.round(a.t);
      add({ key: 'amp|' + t, para: 'RL', tipo: 'Burst de DPS', acao: { tipo: 'adicionar', texto: 'time:' + t + ';ph:1;tag:damager;text:Burst no coração;TTS:Burst no coração;TTSTimer:5;' },
        texto: 'Incluir aviso de burst para os DPS aos ' + mmss(t) + ': é quando o ' + a.nome + ' fica exposto e o boss toma dano aumentado por ' + a.dur + 's. Quem gastar os CDs de dano antes disso perde a janela.' });
    }
    return { cobertura: cob, sugs, nEntradas: raidE.length };
  }

  // Resumo dos CDs de um jogador: usos por try contra o maximo que o cooldown permitiria.
  function cdsDoJogador(lutas, nome) {
    const out = {};
    for (const l of lutas) {
      if (l.durS < 60 || !l.jogadores.some(j => j.nome === nome)) continue;
      const fim = tWipe(l), vistos = {};
      for (const c of l.cds) { if (c.quem !== nome || c.t > fim) continue; const d = CD_POR_ID[c.id]; if (!d || d.tipo === 'cons' || d.cd < 45) continue; vistos[c.id] = (vistos[c.id] || 0) + 1; out[c.id] = out[c.id] || { id: c.id, nome: d.nome, tipo: d.tipo, cd: d.cd, usos: 0, possiveis: 0, tries: 0 }; }
      for (const id in out) { out[id].tries++; out[id].usos += vistos[id] || 0; out[id].possiveis += Math.floor(fim / out[id].cd) + 1; }
    }
    return Object.values(out).sort((a, b) => a.cd - b.cd);
  }

  return { sugestoes, aplicarSugestao, VERSAO_REF, buscarReferencias, ritmoDeDano, buscarCuraJogador, rendimentoCds, lerNote, aderencia, plano, cdsDoJogador, janelasDeDano, elencoAtual, VERSAO_CACHE, CDS, CD_POR_ID, codigoDoLink, listarRelatorio, buscarLutas, resumo, mortes, curva, picos, usoCds, healers, pessoais, tWipe, faseEm, janelaFase, mediana, media };
});
