<?php
/* =====================================================================
   painel-corretores/index.php — Painel de Presença dos Corretores.

   Nível 1: exige a ferramenta 'painel-corretores'.
   Nível 2: mostra SÓ os corretores que o usuário pode ver
            (equipes liberadas + o próprio corretor vinculado; admin vê todos).
   Lê o agregado do MÊS escolhido (um arquivo por mês) + o painel_live.json
   (não lidas do momento). Filtra pelo escopo do usuário e injeta.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';

$u = require_tool('painel-corretores');

portal_load_config();
$dir  = rtrim(defined('PAINEL_DATA_DIR') ? PAINEL_DATA_DIR : (dirname(__DIR__, 2) . '/painel-dados'), '/');

/* Meses disponíveis (um arquivo presenca_AAAA-MM.json por mês). */
$months = [];
foreach (glob($dir . '/presenca_20??-??.json') ?: [] as $f) {
    if (preg_match('#presenca_(\d{4}-\d{2})\.json$#', $f, $mm)) $months[$mm[1]] = $f;
}
krsort($months);
$curMonthKey = (new DateTime('now', new DateTimeZone('-03:00')))->format('Y-m');
$selMonth = (string)($_GET['mes'] ?? '');
if (!isset($months[$selMonth])) $selMonth = $months ? array_key_first($months) : $curMonthKey;

/* Arquivo do mês (fallback para o legado presenca_agg.json). */
$file = $months[$selMonth] ?? ($dir . '/presenca_agg.json');
$agg  = is_readable($file) ? json_decode((string)file_get_contents($file), true) : null;
$ehMesCorrente = ($selMonth === $curMonthKey);

/* Filtra corretores pelo escopo do usuário. */
$semDados = false;
if (is_array($agg) && isset($agg['brokers'])) {
    if (!is_admin($u)) {
        $permit = array_flip(allowed_broker_ids($u));
        $agg['brokers'] = array_values(array_filter($agg['brokers'], fn($b) => isset($permit[$b['id']])));
        // filtra também o live (não lidas) pelo escopo
        if (!empty($agg['live'])) {
            foreach (['unread_client','unread_followup','wait24'] as $k) {
                if (!empty($agg['live'][$k])) $agg['live'][$k] = array_intersect_key($agg['live'][$k], $permit);
            }
        }
    }
    // Status ativo/desligado AO VIVO: reflete o painel de admin na hora
    // (desligar/religar), em vez do que ficou congelado na coleta.
    $liveAtivo = broker_ativo_map();
    foreach ($agg['brokers'] as &$b) {
        if (isset($liveAtivo[$b['id']])) $b['ativo'] = $liveAtivo[$b['id']];
    }
    unset($b);
    if (!$agg['brokers']) $semDados = true;
} else {
    $semDados = true;
}
$isAdmin = is_admin($u);

$stFile = $dir . '/status.json';
$st = is_readable($stFile) ? json_decode((string)file_get_contents($stFile), true) : null;
$rodando = is_array($st) && ($st['state'] ?? '') === 'running';

/* Fila de atendimento (clientes aguardando resposta) — só no mês corrente. */
$aguardando = null;
if ($ehMesCorrente) {
    $af = $dir . '/aguardando.json';
    $aguardando = is_readable($af) ? json_decode((string)file_get_contents($af), true) : null;
    if (is_array($aguardando) && !$isAdmin) {
        $permit2 = array_flip(allowed_broker_ids($u));
        $aguardando['items'] = array_values(array_filter($aguardando['items'] ?? [],
            fn($it) => isset($permit2[$it['broker_id'] ?? ''])));
    }
}

/* nome amigável do mês */
function mes_label(string $ym): string {
    $meses = [1=>'jan',2=>'fev',3=>'mar',4=>'abr',5=>'mai',6=>'jun',7=>'jul',8=>'ago',9=>'set',10=>'out',11=>'nov',12=>'dez'];
    [$y,$m] = array_map('intval', explode('-', $ym));
    return ($meses[$m] ?? $ym) . '/' . $y;
}
?><!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<?php if ($rodando): ?><meta http-equiv="refresh" content="20"><?php endif; ?>
<title>Presença dos Corretores — Imobiliária Camargo</title>
<style>
:root{--bg:#0f1115;--card:#181b22;--card2:#1e222b;--line:#262b36;--tx:#e7eaf0;--mut:#9aa4b2;--acc:#4f8cff;--auto:#5b6472;--warn:#f0a020;--bad:#e06a5b;--good:#2fbf71}
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--tx);line-height:1.5}
.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 18px;background:#0b0d11;border-bottom:1px solid var(--line);font-size:13px}
.topbar a{color:var(--acc);text-decoration:none}.topbar .who{color:var(--mut)}
.wrap{max-width:1180px;margin:0 auto;padding:24px 18px 70px}
h1{font-size:21px;margin:0 0 3px}.sub{color:var(--mut);font-size:13.5px}
.disc{border-left:3px solid var(--acc);background:#141824;padding:10px 14px;border-radius:8px;font-size:12.6px;color:#cbd3df;margin-top:12px}
.updbar{margin-top:12px;padding:9px 14px;border-radius:8px;font-size:12.8px;color:var(--mut);background:#131820;border:1px solid var(--line)}
.updbar a{color:var(--acc);text-decoration:none}
.updbar.run{color:#ffcf8f;background:#211a12;border-color:#6a5320}
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
.uspark{display:inline-flex;align-items:flex-end;gap:1px;height:20px}
.uspark i{width:3px;background:#c9a253;border-radius:1px;display:inline-block;min-height:1px}
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
.alertcard{border-left:3px solid var(--bad)}
.al-row{display:flex;gap:10px;align-items:center;padding:8px 0;border-bottom:1px solid var(--line);flex-wrap:wrap}
.al-row:last-child{border-bottom:0}
.al-nm{font-weight:600;min-width:190px;cursor:pointer}.al-nm:hover{color:var(--acc)}
.chip{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11.5px;font-weight:600;border:1px solid var(--line)}
.chip.red{background:#3a1c1c;color:#ef8a7d;border-color:#5a2a2a}
.chip.orange{background:#211a12;color:#f0b552;border-color:#5a4520}
.chip.yellow{background:#23231a;color:#c9a253;border-color:#44401f}
.chip.mini{padding:1px 7px;font-size:10.5px;margin:1px 3px 1px 0;border-radius:5px;cursor:default;white-space:nowrap}
td.aten{white-space:normal;max-width:340px;line-height:1.9}
.sortbar{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:14px 0 2px}
.sortb{padding:4px 10px;font-size:12px;border-radius:16px}
.sortb.on{background:#26406e;border-color:var(--acc);color:#dbe6ff}
th.sortable{cursor:pointer;user-select:none}th.sortable:hover{color:var(--acc)}
.tabs{display:flex;gap:4px;margin:16px 0 2px;border-bottom:1px solid var(--line)}
.tabb{background:none;border:0;border-bottom:2px solid transparent;border-radius:0;color:var(--mut);padding:8px 14px;font-size:14px;cursor:pointer}
.tabb.on{color:var(--tx);border-bottom-color:var(--acc);font-weight:600}
td.msg{white-space:normal;max-width:440px;color:#cbd3df;font-size:12.8px;line-height:1.45}
td a{color:var(--acc);text-decoration:none}
.rt-hi{color:#f0a020;font-weight:600}
details.desl summary{cursor:pointer;color:var(--mut);font-size:13px;padding:8px 0;list-style:none}
details.desl summary::-webkit-details-marker{display:none}
details.desl summary:before{content:"▸ ";color:var(--mut)}
details.desl[open] summary:before{content:"▾ "}
tr.off td{color:#6f7684}
</style></head><body>
<div class="topbar">
  <a href="/">← Portal</a>
  <span class="who"><?= h($u['nome']) ?><?php if($isAdmin): ?> · <a href="/painel-corretores/coletor.php">atualizar agora</a><?php endif; ?> · <a href="/logout.php">sair</a></span>
</div>
<div class="wrap">
<h1>Presença dos Corretores <span class="tag" style="font-weight:400">· aproximada</span></h1>
<div class="sub" id="periodlbl"></div>
<div class="disc"><b>⚠️ Aproximação, não ponto.</b> A API do GHL não expõe login/tempo online. Presença = rastro no CRM: <b>mensagens manuais</b> + <b>notas/ligações</b>, por autor, excluindo automação. <b>Tempo de resposta</b> = do 1º recado do cliente até a 1ª resposta manual, só em horário comercial (8h–20h). <b>Aguardando</b> = retrato do momento das conversas em que a <b>última mensagem é do cliente</b> (ele realmente espera resposta); separado do <b>follow-up</b> (não lidas que a automação/pós-ligação criou, sem recado novo do cliente).</div>

<?php if ($rodando): ?>
  <div class="updbar run">🔄 <b>Atualizando os dados agora…</b> começou às <?= h($st['started'] ?? '') ?>. Esta página se atualiza sozinha quando terminar.</div>
<?php elseif (is_array($st) && ($st['state'] ?? '') === 'done'): ?>
  <div class="updbar"><span>Dados atualizados <?= isset($st['finished']) ? 'às ' . h($st['finished']) : '' ?>.<?php if($isAdmin): ?> <a href="/painel-corretores/coletor.php">Atualizar agora</a>.<?php endif; ?></span></div>
<?php endif; ?>

<?php if ($semDados): ?>
  <div class="aviso">
    <?php if ($rodando): ?>
      A coleta está sendo gerada agora (iniciada às <?= h($st['started'] ?? '') ?>). Esta página se atualiza sozinha em instantes.
    <?php elseif (!is_array($agg)): ?>
      O painel ainda não foi gerado. <?php if($isAdmin): ?>Clique em <a href="/painel-corretores/coletor.php">atualizar agora</a> (roda em segundo plano) ou aguarde a atualização automática.<?php else: ?>Fale com o administrador.<?php endif; ?>
    <?php else: ?>
      Você ainda não tem nenhum corretor liberado para visualizar. Fale com o administrador.
    <?php endif; ?>
  </div>
</div></body></html>
<?php return; endif; ?>

<div class="tabs" id="tabs"></div>
<div class="ctrl" id="ctrl">
  <label class="tag">Mês</label>
  <select id="mes" onchange="location.search='?mes='+this.value">
    <?php foreach ($months as $mk => $_): ?>
      <option value="<?= h($mk) ?>" <?= $mk===$selMonth?'selected':'' ?>><?= h(mes_label($mk)) ?></option>
    <?php endforeach; ?>
    <?php if (!$months): ?><option selected><?= h(mes_label($selMonth)) ?></option><?php endif; ?>
  </select>
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
const EH_MES_CORRENTE=<?= $ehMesCorrente ? 'true':'false' ?>;
const AGUARDANDO=<?= json_encode($aguardando ?: null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const AD=D.alldays, B=D.brokers, LIVE=D.live||null, byId={};
B.forEach(b=>byId[b.id]=b);
const ACT=B.filter(b=>b.ativo!==0), OFF=B.filter(b=>b.ativo===0);
document.getElementById('periodlbl').textContent="Período: "+D.period.start_str+" – "+D.period.end_str+" · fuso America/São_Paulo (UTC-3)";
document.getElementById('genlbl').textContent="Dados gerados em "+D.period.generated+" · "+ACT.length+" corretores ativos"+(OFF.length?(" · "+OFF.length+" desligados"):"")+" · fonte: GHL API v2.";
const clientTot=b=>Object.values(b.days).reduce((s,d)=>s+d.n,0);
const bsel=document.getElementById('broker');
ACT.map(b=>({id:b.id,name:b.name,t:clientTot(b)})).sort((a,b)=>b.t-a.t)
  .forEach(x=>{const o=document.createElement('option');o.value=x.id;o.textContent=x.name+"  ("+x.t+")";bsel.appendChild(o);});
const fsel=document.getElementById('from'), tsel=document.getElementById('to');
AD.forEach(d=>{const t=d.label+" "+d.wd;
  let o1=document.createElement('option');o1.value=d.key;o1.textContent=t;fsel.appendChild(o1);
  let o2=document.createElement('option');o2.value=d.key;o2.textContent=t;tsel.appendChild(o2);});
let state={broker:"__all__",from:AD[0].key,to:AD[AD.length-1].key,sortKey:'aten',sortDir:-1,
           tab:'geral',filaSort:'wait',filaDir:-1};

const RT_BOUNDS=[0,60,180,300,600,1200,1800,3600,7200,21600,43200];
function rtMedian(hist){const n=hist.reduce((a,b)=>a+b,0);if(!n)return null;const half=n/2;let cum=0;
  for(let i=0;i<10;i++){if(cum+hist[i]>=half){const lo=RT_BOUNDS[i],hi=RT_BOUNDS[i+1];const frac=(half-cum)/Math.max(1,hist[i]);return Math.round(lo+(hi-lo)*frac);}cum+=hist[i];}
  return RT_BOUNDS[10];}
function fmtDur(sec){if(sec==null)return '—';if(sec<60)return sec+'s';const m=Math.round(sec/60);if(m<60)return m+' min';const h=Math.floor(m/60),mm=m%60;return h+'h'+(mm?String(mm).padStart(2,'0'):'');}

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
  let rt_n=0,rt_sum=0; const rt_hist=new Array(10).fill(0);
  const mh=new Array(24).fill(0),ah=new Array(24).fill(0),series=[];
  days.forEach(d=>{const r=b.days[d.key];
    const cnt=r?r.n:0, ici=r?r.ic:0, cli=r?r.cl:0;
    const present=r&&(r.n>0||r.ic>0||r.cl>0);
    series.push({...d,n:cnt,ic:ici,cl:cli,first:r?r.first:null,last:r?r.last:null,
                 pfirst:r?r.pfirst:null,plast:r?r.plast:null,present:!!present,
                 rt_hist:r&&r.rt_hist?r.rt_hist:null,rt_n:r?r.rt_n:0,rt_sum:r?r.rt_sum:0});
    if(r){n+=r.n;ic+=r.ic;cl+=r.cl;
      rt_n+=r.rt_n||0; rt_sum+=r.rt_sum||0; if(r.rt_hist)for(let i=0;i<10;i++)rt_hist[i]+=r.rt_hist[i];
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
  const rtMed=rtMedian(rt_hist), rtMean=rt_n?Math.round(rt_sum/rt_n):null;
  return {n,ic,cl,cfirst,clast,pfirst,plast,activeP,gaps,mh,ah,series,rt_n,rt_sum,rt_hist,rtMed,rtMean};
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
const unreadClient=b=>(LIVE&&LIVE.unread_client)?(LIVE.unread_client[b.id]||0):null;   // clientes aguardando (última msg do cliente)
const unreadFup=b=>(LIVE&&LIVE.unread_followup)?(LIVE.unread_followup[b.id]||0):0;      // follow-up (automação/pós-ligação)
const wait24=b=>(LIVE&&LIVE.wait24)?(LIVE.wait24[b.id]||0):0;

/* -------- Alertas de atenção -------- */
function lastBizDay(days){for(let i=days.length-1;i>=0;i--)if(!days[i].weekend)return days[i];return null;}
function alertsFor(b,days){
  const s=stats(b,days); const out=[];
  const lb=lastBizDay(days);
  if(lb){const r=b.days[lb.key];const act=r&&(r.n>0||r.ic>0||r.cl>0);
    if(!act)out.push({w:3,c:'red',s:`sem ${lb.label}`,t:`sem atividade em ${lb.label} (último dia útil)`});}
  if(s.gaps.length)out.push({w:2,c:'orange',s:`${s.gaps.length}d zerados`,t:`${s.gaps.length} dia(s) útil sem nenhuma atividade: ${s.gaps.join(', ')}`});
  if(s.rtMed!=null&&s.rtMed>1800)out.push({w:2,c:'orange',s:`resp ${fmtDur(s.rtMed)}`,t:`tempo de resposta mediano ${fmtDur(s.rtMed)} (acima de 30 min)`});
  if(LIVE){const un=unreadClient(b);if(un>5)out.push({w:3,c:'red',s:`${un} aguardando`,t:`${un} clientes aguardando resposta agora (última mensagem é do cliente)`});}
  if(LIVE){const wq=wait24(b);if(wq>0)out.push({w:2,c:'orange',s:`${wq}×+24h`,t:`${wq} cliente(s) aguardando resposta há mais de 24h`});}
  const autoDays=[];days.forEach(d=>{if(d.weekend)return;const r=b.days[d.key];
    if(r){const ah=r.ah.reduce((a,b)=>a+b,0);const man=r.n+r.ic+r.cl;if(ah>0&&man===0)autoDays.push(d.label);}});
  if(autoDays.length)out.push({w:1,c:'yellow',s:`só auto ${autoDays.length}d`,t:`dias só com automação (nenhuma ação manual): ${autoDays.join(', ')}`});
  return out;
}

/* ordenação da visão geral */
const SORTS=[
  {k:'aten', l:'Atenção'},
  {k:'msgs', l:'Mensagens'},
  {k:'resp', l:'Tempo de resposta'},
  {k:'unread', l:'Aguardando'},
  {k:'dias', l:'Dias ativos'},
  {k:'nome', l:'Nome'},
];
function sortVal(r,k){
  if(k==='msgs')return r.s.n;
  if(k==='resp')return r.s.rtMed==null?-1:r.s.rtMed;
  if(k==='unread')return unreadClient(r.b)||0;
  if(k==='dias')return r.s.activeP;
  if(k==='aten')return r.al.reduce((a,x)=>a+x.w,0)*1000 + (r.s.n>0?0:0);
  if(k==='nome')return r.b.name.toLowerCase();
  return r.s.n;
}
function setSort(k){
  if(state.sortKey===k) state.sortDir=-state.sortDir;
  else { state.sortKey=k; state.sortDir=(k==='nome')?1:-1; }
  render();
}

function renderOverview(days){
  const rows=ACT.map(b=>{const s=stats(b,days);
    // alertas só para quem tem atividade no período (não polui com dormentes)
    const al=(s.n+s.ic+s.cl)>0?alertsFor(b,days):[];
    return {b,s,al};});
  const k=state.sortKey||'aten', dir=state.sortDir||-1;
  rows.sort((a,b)=>{const va=sortVal(a,k),vb=sortVal(b,k);
    if(va<vb)return -dir; if(va>vb)return dir; return a.s.n<b.s.n?1:-1;});
  const totMsg=rows.reduce((s,r)=>s+r.s.n,0);
  const wdays=days.filter(d=>!d.weekend).length;
  const comAtiv=rows.filter(r=>(r.s.n+r.s.ic+r.s.cl)>0).length;
  const comAlerta=rows.filter(r=>r.al.length>0).length;
  const cliTot=LIVE?ACT.reduce((s,b)=>s+(unreadClient(b)||0),0):null;
  const fupTot=LIVE?ACT.reduce((s,b)=>s+unreadFup(b),0):null;
  let h=`<div class="kpis">
    <div class="kpi"><div class="v">${totMsg}</div><div class="l">mensagens manuais no período</div></div>
    <div class="kpi"><div class="v">${comAtiv}/${ACT.length}</div><div class="l">corretores com atividade</div></div>
    <div class="kpi"><div class="v" style="color:${comAlerta?'#e06a5b':'inherit'}">${comAlerta}</div><div class="l">precisam de atenção</div></div>
    <div class="kpi"><div class="v" style="color:${cliTot?'#e06a5b':'inherit'}">${cliTot==null?'—':cliTot}</div><div class="l">${EH_MES_CORRENTE?'clientes aguardando agora':'aguardando (só mês corrente)'}${fupTot!=null?` <span class="mut" style="font-size:11px">· ${fupTot} follow-up</span>`:''}</div></div>
  </div>
  <div class="sortbar"><span class="tag">Ordenar por:</span>`+
    SORTS.map(o=>`<button class="sortb ${k===o.k?'on':''}" data-k="${o.k}">${o.l}${k===o.k?(dir<0?' ▼':' ▲'):''}</button>`).join('')+
  `</div>
  <div class="card"><table><thead><tr>
  <th data-k="nome" class="sortable">Corretor</th><th class="n sortable" data-k="msgs">Msgs</th><th class="n sortable" data-k="resp">Resp. med.</th><th class="n sortable" data-k="unread">Aguardando</th><th class="n sortable" data-k="dias">Dias ativos</th><th>Atividade</th><th class="sortable" data-k="aten">Atenção</th>
  </tr></thead><tbody>`;
  rows.forEach(r=>{const s=r.s;
    const cli=unreadClient(r.b), fup=unreadFup(r.b);
    const unCell=cli==null?'—':((cli>5?`<span class=zero>${cli}</span>`:(cli||'0'))+(fup?` <span class="mut" style="font-size:11px" title="não lidas de follow-up (automação/pós-ligação), sem recado novo do cliente">+${fup}</span>`:''));
    const rtCell=s.rtMed==null?'—':`<span class="${s.rtMed>1800?'rt-hi':''}">${fmtDur(s.rtMed)}</span>`;
    const aten=r.al.length?r.al.map(a=>`<span class="chip mini ${a.c}" title="${a.t}">${a.s}</span>`).join('')
      :((s.n+s.ic+s.cl)>0?'<span class="pill ok">ok</span>':'<span class="pill no">sem atividade</span>');
    h+=`<tr class="clk" data-id="${r.b.id}"><td>${r.b.name}</td>
      <td class="n">${s.n||'<span class=zero>0</span>'}</td>
      <td class="n">${rtCell}</td>
      <td class="n">${unCell}</td>
      <td class="n">${s.activeP}/${wdays}</td>
      <td>${spark(s.series)}</td>
      <td class="aten">${aten}</td></tr>`;});
  h+=`</tbody></table><div class="foot">Passe o mouse nos selos de <b>Atenção</b> para o detalhe. "Resp. med." = tempo típico até a 1ª resposta manual (horário comercial); <span class="rt-hi">laranja</span> acima de 30 min. "<b>Aguardando</b>" = clientes com a última mensagem sem resposta (cliente de fato esperando); o "<span class="mut">+N</span>" ao lado são não lidas de <b>follow-up</b> (viraram não lida por automação/pós-ligação, sem recado novo do cliente)${EH_MES_CORRENTE?'':' — só no mês corrente'}. Clique num corretor para abrir o detalhe.</div></div>`;
  /* Desligados (recolhível) — status vem do painel de admin, ao vivo */
  if(OFF.length){
    const orows=OFF.map(b=>({b,s:stats(b,days)}));
    h+=`<details class="desl"><summary>Desligados (${OFF.length}) — dados congelados, sem coleta nova</summary><div class="card"><table><thead><tr><th>Corretor</th><th class="n">Msgs no período</th><th class="n">Último dia ativo</th></tr></thead><tbody>`;
    orows.forEach(r=>{const la=[...r.s.series].reverse().find(x=>x.present);
      h+=`<tr class="off clk" data-id="${r.b.id}"><td>${r.b.name}</td><td class="n">${r.s.n||'—'}</td><td class="n">${la?la.label:'—'}</td></tr>`;});
    h+=`</tbody></table><div class="foot">Marcados como desligados no <b>controle de equipes do admin</b> (reflete na hora). O coletor congela os dados na data do desligamento e não busca atividade nova deles.</div></div></details>`;
  }
  document.getElementById('view').innerHTML=h;
  document.querySelectorAll('tr.clk').forEach(el=>el.onclick=()=>{state.broker=el.dataset.id;bsel.value=el.dataset.id;render();});
  document.querySelectorAll('.sortb,th.sortable').forEach(el=>el.onclick=()=>setSort(el.dataset.k));
}

function renderBroker(b,days){
  const s=stats(b,days);const wdays=days.filter(d=>!d.weekend).length;
  const lastAct=[...s.series].reverse().find(x=>x.present);
  const off=b.ativo===0;
  let h=`<div class="back" onclick="state.broker='__all__';document.getElementById('broker').value='__all__';render()">← voltar à visão geral</div>
  <h2 style="margin-top:12px">${b.name} <span class="tag" style="text-transform:none">· ${b.email}${off?' · <span style="color:#f0a020">desligado</span>':''}</span></h2>
  <div class="kpis">
    <div class="kpi"><div class="v">${s.n}</div><div class="l">mensagens manuais</div></div>
    <div class="kpi"><div class="v">${fmtDur(s.rtMed)}</div><div class="l">resposta mediana${s.rtMean!=null?` · média ${fmtDur(s.rtMean)}`:''}</div></div>
    <div class="kpi"><div class="v">${s.activeP}/${wdays}</div><div class="l">dias úteis ativos</div></div>
    <div class="kpi"><div class="v" style="color:${(LIVE&&unreadClient(b)>5)?'#e06a5b':'inherit'}">${LIVE?unreadClient(b):'—'}</div><div class="l">clientes aguardando${LIVE&&wait24(b)?` · ${wait24(b)} há +24h`:''}${LIVE&&unreadFup(b)?` · ${unreadFup(b)} follow-up`:''}</div></div>
  </div>`;
  if(s.gaps.length)h+=`<div class="disc" style="border-color:#f0a020;background:#211a12;color:#ffcf8f;margin-top:0">Sem <b>nenhuma</b> atividade em dia útil: <b>${s.gaps.join(', ')}</b>.</div>`;
  /* série horária de não lidas (mês corrente) */
  if(LIVE&&LIVE.series&&LIVE.series.length){
    const pts=LIVE.series.map(p=>({t:p.t,v:(p.u&&p.u[b.id])||0}));
    const mx=Math.max(1,...pts.map(p=>p.v));
    const bars=pts.slice(-72).map(p=>`<i style="height:${Math.max(1,Math.round(p.v/mx*20))}px" title="${p.t}: ${p.v} não lidas"></i>`).join('');
    h+=`<h2>Clientes aguardando ao longo do tempo</h2><div class="card"><div class="uspark">${bars}</div><div class="foot">Um retrato por hora (últimos dias). Cada barra = nº de clientes com a última mensagem sem resposta na caixa dele naquele momento (não conta os de follow-up).</div></div>`;
  }
  h+='<h2>Presença diária</h2><div class="card"><table><thead><tr><th>Dia</th><th class="n">Msgs</th><th class="n">Notas/Lig</th><th class="n">Resp. med · n</th><th>1ª ação</th><th>Última</th><th>Janela ativa</th></tr></thead><tbody>';
  s.series.forEach(d=>{
    let bar='',span='—';const pf=toMin(d.pfirst),pl=toMin(d.plast);
    if(d.present&&pf!=null){const base=7*60;const L=Math.max(0,(pf-base)/720*100),W=Math.max(1.5,(pl-pf)/720*100);
      const only=d.n===0?' only':'';bar=`<div class="barwrap"><div class="bar${only}" style="left:${L}%;width:${W}%"></div></div>`;span=fmtSpan(pl-pf);}
    else bar='<div class="barwrap"></div>';
    const cls=d.weekend?'we':(!d.present?'zero':'');
    const nf=d.n>0?d.n:(d.weekend?'—':(d.present?'<span style="color:#f0a020">0</span>':'<span class=zero>0</span>'));
    const dMed=d.rt_hist?rtMedian(d.rt_hist):null;
    const rtd=dMed==null?'—':`${fmtDur(dMed)} · ${d.rt_n}`;
    h+=`<tr><td class="${cls}">${d.label} <span class="we">${d.wd}</span></td><td class="n">${nf}</td><td class="n mut">${(d.ic+d.cl)||'—'}</td><td class="n mut">${rtd}</td><td>${d.pfirst||'—'}</td><td>${d.plast||'—'}</td><td>${span} ${bar}</td></tr>`;});
  h+='</tbody></table><div class="foot">Barra <span style="color:#4f8cff">azul</span> = teve mensagem ao cliente · <span style="color:#f0a020">laranja</span> = só nota/ligação. "Resp. med · n" = mediana de resposta no dia e nº de respostas medidas.</div></div>';
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
/* -------- Fila de atendimento (clientes aguardando resposta) -------- */
function fmtWait(ms){const m=Math.floor(ms/60000);if(m<1)return 'agora';if(m<60)return m+' min';
  const h=Math.floor(m/60);if(h<24)return h+'h'+(m%60?String(m%60).padStart(2,'0'):'');
  const d=Math.floor(h/24);return d+' dia'+(d>1?'s':'')+(h%24?' '+(h%24)+'h':'');}
const CANAL={TYPE_WHATSAPP:'WhatsApp',TYPE_CUSTOM_SMS:'WhatsApp',TYPE_SMS:'SMS',TYPE_INSTAGRAM:'Instagram',TYPE_FACEBOOK:'Facebook',TYPE_EMAIL:'E-mail',TYPE_GMB:'Google',TYPE_LIVE_CHAT:'Chat'};
const waPhone=p=>{const d=(p||'').replace(/\D/g,'');return d?('https://wa.me/'+d):null;};
function renderFila(){
  const A=(AGUARDANDO&&AGUARDANDO.items)?AGUARDANDO.items.slice():[];
  const gen=(AGUARDANDO&&AGUARDANDO.gen_ms)||0;
  A.forEach(it=>it._wait=(gen&&it.since)?Math.max(0,gen-it.since):0);
  const k=state.filaSort,dir=state.filaDir;
  const val=it=>k==='broker'?(it.broker||'').toLowerCase():k==='cliente'?(it.name||'').toLowerCase():it._wait;
  A.sort((a,b)=>{const va=val(a),vb=val(b);if(va<vb)return -dir;if(va>vb)return dir;return b._wait-a._wait;});
  const SF=[{k:'wait',l:'Tempo de espera'},{k:'broker',l:'Responsável'},{k:'cliente',l:'Cliente'}];
  let h=`<div class="sortbar"><span class="tag">Ordenar por:</span>`+
    SF.map(o=>`<button class="sortb ${k===o.k?'on':''}" data-fk="${o.k}">${o.l}${k===o.k?(dir<0?' ▼':' ▲'):''}</button>`).join('')+
    `<span class="tag" style="margin-left:auto">${A.length} aguardando · retrato de ${AGUARDANDO?AGUARDANDO.generated:'—'}</span></div>`;
  if(!A.length){h+=`<div class="card">Nenhum cliente aguardando resposta agora. 👍</div>`;document.getElementById('view').innerHTML=h;bindFila();return;}
  h+=`<div class="card"><table><thead><tr><th class="sortable" data-fk="cliente">Cliente</th><th>Telefone</th><th class="sortable" data-fk="broker">Responsável</th><th class="n sortable" data-fk="wait">Espera</th><th>Canal</th><th>Última mensagem do cliente</th></tr></thead><tbody>`;
  A.forEach(it=>{const wcls=it._wait>=864e5?'zero':(it._wait>=144e5?'rt-hi':'');const wa=waPhone(it.phone);
    const fone=it.phone?(wa?`<a href="${wa}" target="_blank" rel="noopener">${it.phone}</a>`:it.phone):'—';
    const txt=(it.text||'').replace(/\s+/g,' ').trim();const short=txt.length>90?txt.slice(0,90)+'…':(txt||'—');
    h+=`<tr><td>${it.name||'—'}</td><td class="mut">${fone}</td><td>${it.broker||'—'}</td><td class="n"><span class="${wcls}">${fmtWait(it._wait)}</span></td><td class="mut">${CANAL[it.type]||it.type||'—'}</td><td class="msg" title="${(txt||'').replace(/"/g,'&quot;')}">${short}</td></tr>`;});
  h+=`</tbody></table><div class="foot">Só clientes com a <b>última mensagem sem resposta</b> (aguardando de fato — não conta follow-up). <span class="rt-hi">Laranja</span> = +4h; <span class="zero">vermelho</span> = +24h. O telefone abre o WhatsApp. Atualiza a cada coleta.</div></div>`;
  document.getElementById('view').innerHTML=h;bindFila();
}
function bindFila(){document.querySelectorAll('[data-fk]').forEach(el=>el.onclick=()=>{
  const nk=el.dataset.fk;if(state.filaSort===nk)state.filaDir=-state.filaDir;else{state.filaSort=nk;state.filaDir=(nk==='wait')?-1:1;}render();});}

function buildTabs(){
  const t=document.getElementById('tabs');if(!t)return;
  const n=(AGUARDANDO&&AGUARDANDO.items)?AGUARDANDO.items.length:0;
  let h=`<button class="tabb ${state.tab==='geral'?'on':''}" data-tab="geral">Visão geral</button>`;
  if(EH_MES_CORRENTE&&AGUARDANDO)h+=`<button class="tabb ${state.tab==='fila'?'on':''}" data-tab="fila">Fila de atendimento${n?` (${n})`:''}</button>`;
  t.innerHTML=h;
  t.querySelectorAll('.tabb').forEach(b=>b.onclick=()=>{state.tab=b.dataset.tab;buildTabs();render();});
}

function render(){
  const ctrl=document.getElementById('ctrl');
  if(state.tab==='fila'){if(ctrl)ctrl.style.display='none';renderFila();return;}
  if(ctrl)ctrl.style.display='';
  const days=rangeDays();
  if(state.broker==='__all__')renderOverview(days);else renderBroker(byId[state.broker],days);
}
bsel.onchange=()=>{state.broker=bsel.value;render();};
fsel.onchange=()=>{state.from=fsel.value;render();};
tsel.onchange=()=>{state.to=tsel.value;render();};
document.querySelectorAll('#presets button').forEach(bt=>bt.onclick=()=>setPreset(bt.dataset.p));
buildTabs();
setPreset('all');
</script></body></html>
