<?php
/* =====================================================================
   painel-corretores/index.php — Painel de Presença dos Corretores.

   Nível 1: exige a ferramenta 'painel-corretores'.
   Nível 2: mostra SÓ os corretores que o usuário pode ver
            (equipes liberadas + o próprio corretor vinculado; admin vê todos).
   Lê o agregado gerado pelo coletor (cron) e injeta já filtrado.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';

$u = require_tool('painel-corretores');

/* Carrega o agregado (fora da web). */
portal_load_config();
$dir  = defined('PAINEL_DATA_DIR') ? PAINEL_DATA_DIR : (dirname(__DIR__, 2) . '/painel-dados');
$file = rtrim($dir, '/') . '/presenca_agg.json';
$agg  = is_readable($file) ? json_decode((string)file_get_contents($file), true) : null;

/* Filtra corretores pelo escopo do usuário. */
$semDados = false;
if (is_array($agg) && isset($agg['brokers'])) {
    if (is_admin($u)) {
        // admin: todos os que estão no agregado
    } else {
        $permit = array_flip(allowed_broker_ids($u)); // ids de corretores visíveis
        $agg['brokers'] = array_values(array_filter($agg['brokers'], function ($b) use ($permit) {
            return isset($permit[$b['id']]);
        }));
    }
    if (!$agg['brokers']) $semDados = true;
} else {
    $semDados = true;
}
$isAdmin = is_admin($u);
?><!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Presença dos Corretores — Imobiliária Camargo</title>
<style>
:root{--bg:#0f1115;--card:#181b22;--card2:#1e222b;--line:#262b36;--tx:#e7eaf0;--mut:#9aa4b2;--acc:#4f8cff;--auto:#5b6472;--warn:#f0a020;--bad:#e06a5b;--good:#2fbf71}
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--tx);line-height:1.5}
.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 18px;background:#0b0d11;border-bottom:1px solid var(--line);font-size:13px}
.topbar a{color:var(--acc);text-decoration:none}.topbar .who{color:var(--mut)}
.wrap{max-width:1120px;margin:0 auto;padding:24px 18px 70px}
h1{font-size:21px;margin:0 0 3px}.sub{color:var(--mut);font-size:13.5px}
.disc{border-left:3px solid var(--acc);background:#141824;padding:10px 14px;border-radius:8px;font-size:12.6px;color:#cbd3df;margin-top:12px}
.ctrl{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:18px 0 6px}
select,button{background:var(--card2);color:var(--tx);border:1px solid var(--line);border-radius:8px;padding:8px 11px;font-size:14px;cursor:pointer}
select:focus,button:focus{outline:1px solid var(--acc)}
.presets button{padding:6px 10px;font-size:12.5px}
.presets button.on{background:#26406e;border-color:var(--acc);color:#dbe6ff}
.tag{color:var(--mut);font-size:12.5px}
.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:16px 0}
.kpi{background:var(--card);border:1px solid var(--line);border-radius:11px;padding:14px}
.kpi .v{font-size:23px;font-weight:700}.kpi .l{color:var(--mut);font-size:12px;margin-top:2px}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px 18px;margin-top:14px}
h2{font-size:13px;letter-spacing:.02em;text-transform:uppercase;color:var(--mut);margin:24px 0 10px;font-weight:600}
table{width:100%;border-collapse:collapse;font-size:13.5px}
th,td{text-align:left;padding:8px 8px;border-bottom:1px solid var(--line);white-space:nowrap}
th{color:var(--mut);font-weight:600;font-size:12px;text-transform:uppercase}
td.n,th.n{text-align:right;font-variant-numeric:tabular-nums}
tr.clk{cursor:pointer}tr.clk:hover{background:#1d222c}
.we{color:var(--mut)}.zero{color:var(--bad);font-weight:600}
.spark{display:inline-flex;align-items:flex-end;gap:1.5px;height:22px}
.spark i{width:5px;background:#3f6fd0;border-radius:1px;display:inline-block;min-height:1px}
.spark i.we{background:#333b47}.spark i.z{background:#3a2020}.spark i.note{background:#f0a020}
.pill{display:inline-block;padding:1px 7px;border-radius:20px;font-size:11px;font-weight:600}
.pill.ok{background:#173a28;color:#57d38c}.pill.no{background:#3a1c1c;color:#ef8a7d}.pill.wk{background:#2a2f3a;color:#c9a253}
.barwrap{position:relative;height:15px;background:#11141a;border-radius:4px;min-width:150px;display:inline-block;vertical-align:middle}
.bar{position:absolute;top:0;height:100%;background:linear-gradient(90deg,#3f6fd0,#4f8cff);border-radius:4px}
.bar.only{background:linear-gradient(90deg,#a5751a,#f0a020)}
.chart{display:flex;align-items:flex-end;gap:3px;height:150px;padding-top:8px}
.hcol{flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:2px;height:100%}
.hbar{width:100%;display:flex;flex-direction:column;justify-content:flex-end;gap:1px;height:100%}
.hb-m{background:#4f8cff;border-radius:2px 2px 0 0}.hb-a{background:#5b6472;border-radius:2px 2px 0 0}
.hlab{font-size:9px;color:var(--mut)}
.legend{display:flex;gap:16px;font-size:12.5px;color:var(--mut);margin:2px 0 8px;flex-wrap:wrap}
.legend i{width:11px;height:11px;border-radius:3px;display:inline-block;margin-right:5px;vertical-align:-1px}
.foot{color:var(--mut);font-size:12px;margin-top:8px}
.back{color:var(--acc);cursor:pointer;font-size:13px}
.mut{color:var(--mut)}
.aviso{background:#211a12;border:1px solid #6a5320;color:#ffcf8f;padding:16px 18px;border-radius:12px;margin-top:20px;font-size:14px}
</style></head><body>
<div class="topbar">
  <a href="/">← Portal</a>
  <span class="who"><?= h($u['nome']) ?><?php if($isAdmin): ?> · <a href="/painel-corretores/coletor.php">atualizar agora</a><?php endif; ?> · <a href="/logout.php">sair</a></span>
</div>
<div class="wrap">
<h1>Presença dos Corretores <span class="tag" style="font-weight:400">· aproximada</span></h1>
<div class="sub" id="periodlbl"></div>
<div class="disc"><b>⚠️ Aproximação, não ponto.</b> A API do GHL não expõe login/tempo online. Presença aqui = rastro de trabalho no CRM: <b>mensagens manuais</b> (digitadas no GHL + WhatsApp externo), mais <b>notas internas e ligações</b> registradas por ele. Automação (workflow) é excluída. A "janela" vai da 1ª à última ação do dia — subestima o dia real. Escopo: conversas atribuídas a cada corretor (o que ele faz em conversas de outro dono não entra).</div>

<?php if ($semDados): ?>
  <div class="aviso">
    <?php if (!is_array($agg)): ?>
      O painel ainda não foi gerado. <?php if($isAdmin): ?>Rode o coletor: <a href="/painel-corretores/coletor.php">atualizar agora</a> (ou aguarde o cron diário).<?php else: ?>Fale com o administrador para gerar os dados.<?php endif; ?>
    <?php else: ?>
      Você ainda não tem nenhum corretor liberado para visualizar. Fale com o administrador.
    <?php endif; ?>
  </div>
</div></body></html>
<?php return; endif; ?>

<div class="ctrl">
  <label class="tag">Corretor</label>
  <select id="broker"><option value="__all__">▦ Todos (visão geral)</option></select>
  <span class="presets" id="presets">
    <button data-p="all">Mês todo</button>
    <button data-p="7">Últimos 7 dias</button>
    <button data-p="today">Hoje</button>
    <button data-p="yest">Ontem</button>
  </span>
  <label class="tag">De</label><select id="from"></select>
  <label class="tag">até</label><select id="to"></select>
</div>

<div id="view"></div>
<div class="foot" id="genlbl"></div>
</div>
<script>
const D=<?= json_encode($agg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const AD=D.alldays, B=D.brokers, byId={};
B.forEach(b=>byId[b.id]=b);
document.getElementById('periodlbl').textContent="Período coletado: "+D.period.start_str+" – "+D.period.end_str+" · fuso America/São_Paulo (UTC-3)";
document.getElementById('genlbl').textContent="Dados gerados em "+D.period.generated+" · "+B.length+" corretores · fonte: GHL API v2.";
const clientTot=b=>Object.values(b.days).reduce((s,d)=>s+d.n,0);
const totals=B.map(b=>({id:b.id,name:b.name,t:clientTot(b)})).sort((a,b)=>b.t-a.t);
const bsel=document.getElementById('broker');
totals.forEach(x=>{const o=document.createElement('option');o.value=x.id;o.textContent=x.name+"  ("+x.t+")";bsel.appendChild(o);});
const fsel=document.getElementById('from'), tsel=document.getElementById('to');
AD.forEach(d=>{const t=d.label+" "+d.wd;
  let o1=document.createElement('option');o1.value=d.key;o1.textContent=t;fsel.appendChild(o1);
  let o2=document.createElement('option');o2.value=d.key;o2.textContent=t;tsel.appendChild(o2);});
let state={broker:"__all__",from:AD[0].key,to:AD[AD.length-1].key};

function rangeDays(){const i0=AD.findIndex(d=>d.key===state.from),i1=AD.findIndex(d=>d.key===state.to);
  return AD.slice(Math.min(i0,i1),Math.max(i0,i1)+1);}
function setPreset(p){const last=AD[AD.length-1].key;
  if(p==='all'){state.from=AD[0].key;state.to=last;}
  else if(p==='today'){state.from=last;state.to=last;}
  else if(p==='yest'){const i=Math.max(0,AD.length-2);state.from=AD[i].key;state.to=AD[i].key;}
  else if(p==='7'){const i=Math.max(0,AD.length-7);state.from=AD[i].key;state.to=last;}
  fsel.value=state.from;tsel.value=state.to;
  [...document.querySelectorAll('#presets button')].forEach(x=>x.classList.toggle('on',x.dataset.p===p));
  render();}
function toMin(h){if(!h)return null;const[a,b]=h.split(':').map(Number);return a*60+b;}
function fmtHM(m){return String(Math.floor(m/60)).padStart(2,'0')+":"+String(m%60).padStart(2,'0');}
function fmtSpan(m){return Math.floor(m/60)+"h"+String(m%60).padStart(2,'0');}

function stats(b,days){
  let n=0,ic=0,cl=0,cfirst=null,clast=null,pfirst=null,plast=null,activeP=0,gaps=[];
  const mh=new Array(24).fill(0),ah=new Array(24).fill(0),series=[];
  days.forEach(d=>{const r=b.days[d.key];
    const cnt=r?r.n:0, ici=r?r.ic:0, cli=r?r.cl:0;
    const present=r&&(r.n>0||r.ic>0||r.cl>0);
    series.push({...d,n:cnt,ic:ici,cl:cli,first:r?r.first:null,last:r?r.last:null,
                 pfirst:r?r.pfirst:null,plast:r?r.plast:null,present:!!present});
    if(r){n+=r.n;ic+=r.ic;cl+=r.cl;
      for(let h=0;h<24;h++){mh[h]+=r.mh[h];ah[h]+=r.ah[h];}
      if(present){activeP++;
        const pf=toMin(r.pfirst),pl=toMin(r.plast);
        if(pf!=null&&(pfirst==null||pf<pfirst))pfirst=pf;
        if(pl!=null&&(plast==null||pl>plast))plast=pl;
        const cf=toMin(r.first),clm=toMin(r.last);
        if(cf!=null&&(cfirst==null||cf<cfirst))cfirst=cf;
        if(clm!=null&&(clast==null||clm>clast))clast=clm;
      } else if(!d.weekend) gaps.push(d.label);
    } else if(!d.weekend) gaps.push(d.label);
  });
  return {n,ic,cl,cfirst,clast,pfirst,plast,activeP,gaps,mh,ah,series};
}
function spark(series){const mx=Math.max(1,...series.map(s=>s.n));
  return '<span class="spark">'+series.map(s=>{
    let cls='',h;
    if(s.weekend){cls='we';h=3;}
    else if(s.n>0){h=Math.max(2,Math.round(s.n/mx*22));}
    else if(s.present){cls='note';h=6;}
    else {cls='z';h=2;}
    return `<i class="${cls}" style="height:${h}px" title="${s.label} ${s.wd}: ${s.n} msg${s.ic?', '+s.ic+' nota(s)':''}${s.cl?', '+s.cl+' lig':''}"></i>`;
  }).join('')+'</span>';}

function renderOverview(days){
  const rows=B.map(b=>({b,s:stats(b,days)})).sort((a,b)=>b.s.n-a.s.n);
  const totMsg=rows.reduce((s,r)=>s+r.s.n,0);
  const wdays=days.filter(d=>!d.weekend).length;
  const withGap=rows.filter(r=>(r.s.n+r.s.ic+r.s.cl)>0 && r.s.gaps.length>0).length;
  const silent=rows.filter(r=>(r.s.n+r.s.ic+r.s.cl)===0).length;
  let h=`<div class="kpis">
    <div class="kpi"><div class="v">${totMsg}</div><div class="l">mensagens manuais no período</div></div>
    <div class="kpi"><div class="v">${rows.filter(r=>(r.s.n+r.s.ic+r.s.cl)>0).length}/${B.length}</div><div class="l">corretores com atividade</div></div>
    <div class="kpi"><div class="v" style="color:${withGap?'#f0a020':'inherit'}">${withGap}</div><div class="l">com falhas em dia útil</div></div>
    <div class="kpi"><div class="v" style="color:${silent?'#e06a5b':'inherit'}">${silent}</div><div class="l">sem nenhuma atividade</div></div>
  </div>
  <h2>Visão geral — clique num corretor para o detalhe</h2>
  <div class="card"><table><thead><tr>
  <th>Corretor</th><th class="n">Msgs</th><th class="n">Notas/Lig</th><th class="n">Dias ativos</th><th>1ª típ.</th><th>Últ. típ.</th><th>Atividade diária</th><th>Falhas (dia útil)</th>
  </tr></thead><tbody>`;
  rows.forEach(r=>{const s=r.s;const any=s.n+s.ic+s.cl;
    const stat=any===0?'<span class="pill no">sem atividade</span>'
      :s.gaps.length?`<span class="pill wk">${s.gaps.length} dia(s): ${s.gaps.slice(0,4).join(', ')}${s.gaps.length>4?'…':''}</span>`
      :'<span class="pill ok">ok</span>';
    h+=`<tr class="clk" data-id="${r.b.id}"><td>${r.b.name}</td>
      <td class="n">${s.n||'<span class=zero>0</span>'}</td>
      <td class="n mut">${(s.ic+s.cl)||'—'}</td>
      <td class="n">${s.activeP}/${wdays}</td>
      <td>${s.pfirst!=null?fmtHM(s.pfirst):'—'}</td>
      <td>${s.plast!=null?fmtHM(s.plast):'—'}</td>
      <td>${spark(s.series)}</td><td>${stat}</td></tr>`;});
  h+='</tbody></table><div class="foot">"Msgs" = mensagens manuais ao cliente. "Notas/Lig" = comentários internos + ligações (sinal de presença sem mensagem). "Dias ativos" = dias úteis com qualquer atividade. "Falhas" = dias úteis sem <b>nenhuma</b> atividade (barra <span style="color:#f0a020">laranja</span> = só nota/ligação; <span style="color:#e06a5b">vermelha fina</span> = ausência). "1ª/Últ. típ." considera toda ação (msg, nota, ligação).</div></div>';
  document.getElementById('view').innerHTML=h;
  document.querySelectorAll('tr.clk').forEach(tr=>tr.onclick=()=>{state.broker=tr.dataset.id;bsel.value=tr.dataset.id;render();});
}
function renderBroker(b,days){
  const s=stats(b,days);const wdays=days.filter(d=>!d.weekend).length;
  const lastAct=[...s.series].reverse().find(x=>x.present);
  let h=`<div class="back" onclick="state.broker='__all__';document.getElementById('broker').value='__all__';render()">← voltar à visão geral</div>
  <h2 style="margin-top:12px">${b.name} <span class="tag" style="text-transform:none">· ${b.email}</span></h2>
  <div class="kpis">
    <div class="kpi"><div class="v">${s.n}</div><div class="l">mensagens manuais</div></div>
    <div class="kpi"><div class="v">${s.activeP}/${wdays}</div><div class="l">dias úteis ativos</div></div>
    <div class="kpi"><div class="v">${lastAct?lastAct.label:'—'}</div><div class="l">último dia com atividade</div></div>
    <div class="kpi"><div class="v" style="color:${s.gaps.length?'#f0a020':'inherit'}">${s.gaps.length}</div><div class="l">dias úteis sem atividade</div></div>
  </div>`;
  if(s.gaps.length)h+=`<div class="disc" style="border-color:#f0a020;background:#211a12;color:#ffcf8f;margin-top:0">Sem <b>nenhuma</b> atividade em dia útil: <b>${s.gaps.join(', ')}</b>.</div>`;
  h+='<h2>Presença diária</h2><div class="card"><table><thead><tr><th>Dia</th><th class="n">Msgs</th><th class="n">Notas/Lig</th><th>1ª ação</th><th>Última</th><th>Janela ativa</th></tr></thead><tbody>';
  s.series.forEach(d=>{
    let bar='',span='—';const pf=toMin(d.pfirst),pl=toMin(d.plast);
    if(d.present&&pf!=null){const base=7*60;const L=Math.max(0,(pf-base)/720*100),W=Math.max(1.5,(pl-pf)/720*100);
      const only=d.n===0?' only':'';bar=`<div class="barwrap"><div class="bar${only}" style="left:${L}%;width:${W}%"></div></div>`;span=fmtSpan(pl-pf);}
    else bar='<div class="barwrap"></div>';
    const cls=d.weekend?'we':(!d.present?'zero':'');
    const nf=d.n>0?d.n:(d.weekend?'—':(d.present?'<span style="color:#f0a020">0</span>':'<span class=zero>0</span>'));
    h+=`<tr><td class="${cls}">${d.label} <span class="we">${d.wd}</span></td><td class="n">${nf}</td><td class="n mut">${(d.ic+d.cl)||'—'}</td><td>${d.pfirst||'—'}</td><td>${d.plast||'—'}</td><td>${span} ${bar}</td></tr>`;});
  h+='</tbody></table><div class="foot">Barra <span style="color:#4f8cff">azul</span> = teve mensagem ao cliente · <span style="color:#f0a020">laranja</span> = só nota/ligação nesse dia.</div></div>';
  const mx=Math.max(1,...s.mh,...s.ah);let ch='';
  for(let hh=0;hh<24;hh++)ch+=`<div class="hcol"><div class="hbar"><div class="hb-a" style="height:${s.ah[hh]/mx*100}%" title="auto ${s.ah[hh]} às ${hh}h"></div><div class="hb-m" style="height:${s.mh[hh]/mx*100}%" title="manual ${s.mh[hh]} às ${hh}h"></div></div><div class="hlab">${hh}</div></div>`;
  const app=s.series.reduce((a,d)=>a+(b.days[d.key]?b.days[d.key].app:0),0);
  const api=s.series.reduce((a,d)=>a+(b.days[d.key]?b.days[d.key].api:0),0);
  h+=`<h2>Manual vs automação por hora</h2><div class="card">
    <div class="legend"><span><i style="background:#4f8cff"></i>Manuais ao cliente (${s.n}: ${app} GHL + ${api} WhatsApp ext.)</span><span><i style="background:#5b6472"></i>Automação</span></div>
    <div class="chart">${ch}</div>
    <div class="foot">Manuais em horário comercial = presença humana; automação espalhada/madrugada = robô.</div></div>`;
  document.getElementById('view').innerHTML=h;
}
function render(){const days=rangeDays();
  if(state.broker==='__all__')renderOverview(days);else renderBroker(byId[state.broker],days);}
bsel.onchange=()=>{state.broker=bsel.value;render();};
fsel.onchange=()=>{state.from=fsel.value;render();};
tsel.onchange=()=>{state.to=tsel.value;render();};
document.querySelectorAll('#presets button').forEach(bt=>bt.onclick=()=>setPreset(bt.dataset.p));
setPreset('all');
</script></body></html>
