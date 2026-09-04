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

/* No mês corrente, o retrato de "aguardando" vem do painel_live.json, que o
   delta leve da fila atualiza a cada poucos minutos (bem mais fresco que a
   coleta pesada horária embutida no agregado). Cai no agg['live'] se faltar. */
if ($ehMesCorrente && is_array($agg)) {
    $lf = $dir . '/painel_live.json';
    if (is_readable($lf)) {
        $lv = json_decode((string)file_get_contents($lf), true);
        if (is_array($lv) && isset($lv['unread_client'])) {
            $agg['live'] = [
                'generated'       => $lv['generated'] ?? ($agg['live']['generated'] ?? ''),
                'unread_client'   => $lv['unread_client'] ?? [],
                'unread_followup' => $lv['unread_followup'] ?? [],
                'wait24'          => $lv['wait24'] ?? [],
                'series'          => $lv['series'] ?? ($agg['live']['series'] ?? []),
            ];
        }
    }
}

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
.score{display:inline-block;min-width:22px;text-align:center;padding:2px 8px;border-radius:9px;font-weight:800;font-size:13px;cursor:default;vertical-align:middle}
.score.ok{background:#173a28;color:#57d38c}
.score.y{background:#39340f;color:#e9c65a}
.score.o{background:#3d2a12;color:#f0a848}
.score.r{background:#3d1414;color:#ff6a5a}
td.aten{white-space:normal;max-width:340px;line-height:1.9}
.sortbar{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:14px 0 2px}
.sortb{padding:4px 10px;font-size:12px;border-radius:16px}
.sortb.on{background:#26406e;border-color:var(--acc);color:#dbe6ff}
.filterbar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:14px 0 2px}
.filterbar #filaSearch{flex:1 1 320px;min-width:220px;padding:7px 12px;font-size:13px;border-radius:18px;
  background:#0f1626;border:1px solid #2a3446;color:#e7edf6}
.filterbar #filaSearch:focus{outline:none;border-color:var(--acc);box-shadow:0 0 0 2px rgba(90,160,255,.15)}
.filterbar #filaSearch::placeholder{color:#6b7688}
.filterbar #filaResp{padding:7px 10px;font-size:13px;border-radius:18px;max-width:260px;
  background:#0f1626;border:1px solid #2a3446;color:#e7edf6}
.filterbar #filaResp:focus{outline:none;border-color:var(--acc)}
th.sortable{cursor:pointer;user-select:none}th.sortable:hover{color:var(--acc)}
.tabs{display:flex;gap:4px;margin:16px 0 2px;border-bottom:1px solid var(--line)}
.tabb{background:none;border:0;border-bottom:2px solid transparent;border-radius:0;color:var(--mut);padding:8px 14px;font-size:14px;cursor:pointer}
.tabb.on{color:var(--tx);border-bottom-color:var(--acc);font-weight:600}
td.msg{white-space:normal;max-width:440px;color:#cbd3df;font-size:12.8px;line-height:1.45}
td a{color:var(--acc);text-decoration:none}
/* Aguardando = ponto de atenção nº1: número grande e vermelho vivo */
.wait-big{color:#ff2d2d;font-weight:800;font-size:21px;line-height:1}
.wait-zero{color:#5b6472}
.fup-num{color:#e0a13a;font-weight:600}
th.wait-col,td.wait-col{border-left:2px solid rgba(255,45,45,.22);border-right:1px solid rgba(255,45,45,.12)}
a.crmlink{display:inline-block;padding:3px 9px;border:1px solid var(--acc);border-radius:14px;font-size:12px;white-space:nowrap}
a.crmlink:hover{background:var(--acc);color:#0b1220}
table.fila tr.hasthread{cursor:pointer}
table.fila tr.filarow.on{background:rgba(90,160,255,.06)}
table.fila td.cx{width:20px;text-align:center}
.caret{color:var(--mut);font-size:10px;user-select:none}
tr.filarow.hasthread:hover td{background:rgba(255,255,255,.02)}
.thread{display:flex;flex-direction:column;gap:6px;padding:8px 4px 10px;max-height:360px;overflow:auto}
.tmsg{max-width:78%;padding:6px 10px;border-radius:12px;font-size:12.8px;line-height:1.4}
.tmsg .thead{font-size:10.5px;color:var(--mut);margin-bottom:2px}
.tmsg.tin{align-self:flex-start;background:#1d2a1c;border:1px solid #2c4327}
.tmsg.tout{align-self:flex-end;background:#182233;border:1px solid #24344c}
.tmsg.tsys{align-self:center;max-width:92%;background:transparent;border:1px dashed #333c4a;color:var(--mut);font-size:12px}
.tmsg .tbody{color:#dbe2ee;white-space:normal;word-break:break-word}
.tmsg.tsys .tbody{color:var(--mut)}
.rt-hi{color:#f0a020;font-weight:600}
.sla{font-size:11px;color:var(--mut);margin-top:1px;white-space:nowrap}
.sla .sl-ok{color:#57d38c;font-weight:600}
.sla .sl-y{color:#e9c65a;font-weight:600}
.sla .sl-o{color:#f0a848;font-weight:600}
.sla .sl-r{color:#ff6a5a;font-weight:600}
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
<div class="disc"><b>⚠️ Aproximação, não ponto.</b> A API do GHL não expõe login/tempo online. Presença = rastro no CRM: <b>mensagens manuais</b> + <b>notas/ligações</b>, por autor, excluindo automação. <b>Tempo de resposta</b> = do 1º recado do cliente até a 1ª resposta manual, só em horário comercial (8h–20h, horário de Brasília). <b>Aguardando</b> = retrato do momento das conversas em que a <b>última mensagem é do cliente</b> (ele realmente espera resposta); separado do <b>follow-up</b> (não lidas que a automação/pós-ligação criou, sem recado novo do cliente).</div>

<?php if ($rodando): ?>
  <div class="updbar run">🔄 <b>Atualizando os dados agora…</b> começou às <?= h($st['started'] ?? '') ?>. Esta página se atualiza sozinha quando terminar.</div>
<?php elseif (is_array($st) && ($st['state'] ?? '') === 'done'): ?>
  <div class="updbar"><span>Dados gerais atualizados <?= isset($st['finished']) ? 'às ' . h($st['finished']) : '' ?> <span class="mut">(a cada hora)</span><?php
      $lg = ($ehMesCorrente && !empty($agg['live']['generated'])) ? (string)$agg['live']['generated'] : '';
      if ($lg) echo ' · <b>fila e aguardando</b> às ' . h(substr($lg, -5)) . ' <span class="mut">(a cada 5 min)</span>';
  ?>.<?php if($isAdmin): ?> <a href="/painel-corretores/coletor.php">Atualizar agora</a>.<?php endif; ?></span></div>
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
/* Watchdog: se a inicialização não terminar (resposta cortada durante uma
   coleta, erro de JS, etc.), a tela ficaria só com os controles e sem dados.
   Este script — separado e entregue ANTES dos dados — recarrega sozinho se em
   5s a página não sinalizar que renderizou. Limite de 3 tentativas evita loop
   (uma carga boa zera o contador). */
(function(){try{
  var K='painelInitRetry';
  window.__painelReady=function(){window.__painelOK=1;try{sessionStorage.removeItem(K);}catch(e){}};
  setTimeout(function(){
    if(window.__painelOK)return;
    var n=0;try{n=parseInt(sessionStorage.getItem(K)||'0',10)||0;}catch(e){}
    if(n>=3)return;
    try{sessionStorage.setItem(K,String(n+1));}catch(e){}
    location.reload();
  },5000);
}catch(e){}})();
</script>
<script>
const D=<?= json_encode($agg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const EH_MES_CORRENTE=<?= $ehMesCorrente ? 'true':'false' ?>;
const AGUARDANDO=<?= json_encode($aguardando ?: null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const CRM_URL=<?= json_encode(rtrim(defined('GHL_APP_URL')?GHL_APP_URL:'https://app.wesalescrm.com','/')) ?>;
const CRM_LOC=<?= json_encode(defined('GHL_LOCATION')?GHL_LOCATION:'') ?>;
// Link para o CRM. O deep-link direto de conversa (/conversations/conversations/ID)
// NÃO é confiável no WeSales/GHL: o app ignora a conversa da URL e mantém o
// filtro de caixa que o usuário deixou salvo, caindo na 1ª conversa desse filtro.
// Já a ficha do contato (/contacts/detail/ID) abre SEMPRE o cliente certo, com a
// conversa/atividade dele ao lado. Por isso preferimos o contactId; a conversa
// fica como reserva caso um item antigo não tenha o contato.
const contactUrl=cid=>(cid&&CRM_LOC)?`${CRM_URL}/v2/location/${CRM_LOC}/contacts/detail/${cid}`:null;
const convUrl=id=>(id&&CRM_LOC)?`${CRM_URL}/v2/location/${CRM_LOC}/conversations/conversations/${id}?view=contact`:null;
const crmUrl=it=>contactUrl(it.contact)||convUrl(it.conv);
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

// Metas de tempo de resposta (nível de serviço), em segundos comerciais.
const SLA1=600, SLA2=1800;   // metas: 10 min e 30 min (em segundos comerciais)
const M1=Math.round(SLA1/60), M2=Math.round(SLA2/60);   // rótulos (min)
const RT_MINN=8;             // amostra mínima p/ julgar o nível de serviço
// Legado (meses coletados antes das listas de resposta): mediana por histograma.
const RT_BOUNDS=[0,60,180,300,600,1200,1800,3600,7200,21600,43200];
function rtMedian(hist){const n=hist.reduce((a,b)=>a+b,0);if(!n)return null;const half=n/2;let cum=0;
  for(let i=0;i<10;i++){if(cum+hist[i]>=half){const lo=RT_BOUNDS[i],hi=RT_BOUNDS[i+1];const frac=(half-cum)/Math.max(1,hist[i]);return Math.round(lo+(hi-lo)*frac);}cum+=hist[i];}
  return RT_BOUNDS[10];}
// Percentil exato (interpolado) de uma lista JÁ ORDENADA. p em [0,1].
function pct(sorted,p){if(!sorted.length)return null;const idx=(sorted.length-1)*p;const lo=Math.floor(idx),hi=Math.ceil(idx);
  if(lo===hi)return sorted[lo];return Math.round(sorted[lo]+(sorted[hi]-sorted[lo])*(idx-lo));}
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
  let rt_n=0,rt_sum=0; const rt_hist=new Array(10).fill(0); let rts=[]; let hasList=false;
  const convSet={};   // contactId -> 0|1 (1 = houve interação do cliente)
  const mh=new Array(24).fill(0),ah=new Array(24).fill(0),series=[];
  days.forEach(d=>{const r=b.days[d.key];
    const cnt=r?r.n:0, ici=r?r.ic:0, cli=r?r.cl:0;
    const present=r&&(r.n>0||r.ic>0||r.cl>0);
    series.push({...d,n:cnt,ic:ici,cl:cli,inb:r?(r.in||0):0,first:r?r.first:null,last:r?r.last:null,
                 pfirst:r?r.pfirst:null,plast:r?r.plast:null,present:!!present,
                 rt:r&&Array.isArray(r.rt)?r.rt:null,rt_hist:r&&r.rt_hist?r.rt_hist:null,rt_n:r?r.rt_n:0,rt_sum:r?r.rt_sum:0});
    if(r){n+=r.n;ic+=r.ic;cl+=r.cl;
      rt_n+=r.rt_n||0; rt_sum+=r.rt_sum||0;
      if(r.cts)for(const c in r.cts){convSet[c]=Math.max(convSet[c]||0, r.cts[c]||0);}
      if(Array.isArray(r.rt)){hasList=true; if(r.rt.length)rts=rts.concat(r.rt);}
      else if(r.rt_hist)for(let i=0;i<10;i++)rt_hist[i]+=r.rt_hist[i];
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
  let rtMed=null,rtMean=null,sl1=null,sl2=null,rtN=0;
  if(hasList){ rtN=rts.length;
    if(rtN){rts.sort((a,b)=>a-b); rtMed=pct(rts,0.5); rtMean=Math.round(rts.reduce((a,b)=>a+b,0)/rtN);
      sl1=Math.round(rts.filter(x=>x<=SLA1).length/rtN*100); sl2=Math.round(rts.filter(x=>x<=SLA2).length/rtN*100);} }
  else { rtMed=rtMedian(rt_hist); rtN=rt_n; rtMean=rt_n?Math.round(rt_sum/rt_n):null; }  // legado: sem nível de serviço
  const conversas=Object.keys(convSet).length;
  const conversasI=Object.values(convSet).filter(v=>v>0).length;
  return {n,ic,cl,cfirst,clast,pfirst,plast,activeP,gaps,mh,ah,series,rt_n,rt_sum,rtMed,rtMean,sl1,sl2,rtN,conversas,conversasI};
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

/* -------- Ranking de atenção: 3 blocos de urgência que competem --------
   Bloco 1 — FILA AGORA (dominante): 2 pts por cliente aguardando + 3 extra por
             cliente esperando há +24h. Só no mês corrente (dado ao vivo).
   Bloco 2 — RESPONSIVIDADE (leve): nível de serviço — % de clientes respondidos
             dentro da 1ª meta (ver respScore); poucos dados não pontua.
   Bloco 3 — ABANDONO COM DEMANDA: dias úteis em que o CLIENTE falou (inbound>0)
             e o corretor não fez nada manual. 1 pt/dia (teto 5) + 2 se for o
             último dia útil. Dia sem cliente falando não pontua. */
function lastBizDay(days){for(let i=days.length-1;i>=0;i--)if(!days[i].weekend)return days[i];return null;}
// Bloco 2: NÍVEL DE SERVIÇO — % de clientes respondidos dentro da 1ª meta.
// Quanto menos gente na meta, mais atenção. Calibrado pela realidade do time
// (média ~48% em 10min): >=50% ok, 35–50 leve, 22–35 abaixo, <22 ruim.
// Só julga com amostra suficiente (RT_MINN); poucos dados = não pontua.
function respScore(sl1,n){
  if(n<RT_MINN || sl1==null) return 0;
  if(sl1>=50) return 0;
  if(sl1>=35) return 1;
  if(sl1>=22) return 2;
  return 3;
}
function abandono(b,days){
  let cnt=0,lastAb=false;const labels=[];const lb=lastBizDay(days);
  days.forEach(d=>{if(d.weekend)return;const r=b.days[d.key];
    const inb=r?(r.in||0):0;const man=r?(r.n+r.ic+r.cl):0;
    if(inb>0&&man===0){cnt++;labels.push(d.label);if(lb&&d.key===lb.key)lastAb=true;}});
  return {cnt,lastAb,labels};
}
function scoreBand(sc){if(sc<=0)return 'ok';if(sc<=4)return 'y';if(sc<=11)return 'o';return 'r';}
function attention(b,days,s){
  s=s||stats(b,days);
  const ag=unreadClient(b)||0, w24=wait24(b)||0;   // 0 se mês passado (sem LIVE)
  const b1=LIVE?(2*ag+3*w24):0;
  const b2=respScore(s.sl1,s.rtN);
  const ab=abandono(b,days);
  const b3=Math.min(ab.cnt,5)+(ab.lastAb?2:0);
  const score=b1+b2+b3;
  const chips=[];
  if(LIVE&&ag>0)chips.push({c:ag>5?'red':(ag>=3?'orange':'yellow'),s:`${ag} aguardando`,t:`${ag} cliente(s) aguardando resposta agora (bloco Fila = ${b1} pts)`});
  if(LIVE&&w24>0)chips.push({c:'red',s:`${w24}×+24h`,t:`${w24} cliente(s) esperando há mais de 24h — peso extra no bloco Fila`});
  if(b2>0)chips.push({c:b2>=3?'red':(b2>=2?'orange':'yellow'),s:`${s.sl1}% em ${M1}m`,t:`só ${s.sl1}% dos clientes respondidos em até ${M1} min (${s.sl2}% em até ${M2} min · ${s.rtN} respostas) — bloco Resposta = ${b2} pts`});
  if(ab.cnt>0)chips.push({c:ab.lastAb?'orange':'yellow',s:`${ab.cnt}d sem retorno`,t:`${ab.cnt} dia(s) útil com o cliente falando e nenhuma ação manual: ${ab.labels.join(', ')}${ab.lastAb?' — inclui o último dia útil':''} (bloco Abandono = ${b3} pts)`});
  const rtTxt=(s.rtN>=RT_MINN&&s.sl1!=null)?`${s.sl1}% em ≤${M1}min (${s.sl2}% em ≤${M2}min)`:(s.rtN?`poucos dados (${s.rtN} resp.)`:'sem respostas');
  const tip=`Score de atenção: ${score} pts (blocos competem — quanto maior, mais urgente)`
    +`\n• Fila agora: ${LIVE?`${ag} aguardando`+(w24?` (${w24} há +24h)`:''):'sem dado ao vivo'} = ${b1} pts`
    +`\n• Responsividade: ${rtTxt} = ${b2} pts`
    +`\n• Abandono (cliente falou e ninguém agiu): ${ab.cnt} dia(s) = ${b3} pts`;
  return {score,b1,b2,b3,ag,w24,rtMed:s.rtMed,ab,chips,tip};
}

/* ordenação da visão geral */
const SORTS=[
  {k:'unread', l:'Aguardando'},
  {k:'fup', l:'Follow-ups pend.'},
  {k:'aten', l:'Atenção'},
  {k:'msgs', l:'Mensagens'},
  {k:'conversas', l:'Conversas'},
  {k:'resp', l:'Tempo de resposta'},
  {k:'dias', l:'Dias ativos'},
  {k:'nome', l:'Nome'},
];
function sortVal(r,k){
  if(k==='msgs')return r.s.n;
  if(k==='conversas')return r.s.conversas;
  if(k==='resp')return r.s.rtMed==null?-1:r.s.rtMed;
  if(k==='unread')return unreadClient(r.b)||0;
  if(k==='fup')return unreadFup(r.b)||0;
  if(k==='dias')return r.s.activeP;
  if(k==='aten')return r.at?r.at.score:-1;
  if(k==='nome')return r.b.name.toLowerCase();
  return r.s.n;
}
function setSort(k){
  if(state.sortKey===k) state.sortDir=-state.sortDir;
  else { state.sortKey=k; state.sortDir=(k==='nome')?1:-1; }
  render();
}

/* Tooltips (title) — explicam o que cada número significa ao passar o mouse. */
const TT={
  nome:'Corretor. Clique na linha para abrir o detalhe diário dele.',
  unread:'CLIENTES AGUARDANDO: conversas cuja última mensagem é do cliente — ou seja, ele está de fato esperando resposta agora. É um retrato do momento (não do período) e o principal ponto de atenção.',
  fup:'FOLLOW-UPS PENDENTES: conversas não lidas que a automação ou o pós-ligação deixou na caixa, SEM um recado novo do cliente esperando. Precisam de ação (ex.: retornar a ligação), mas ninguém está parado esperando resposta.',
  msgs:'MENSAGENS MANUAIS enviadas ao cliente no período selecionado (WhatsApp, SMS, Instagram etc., feitas por pessoa) — inclui as que o corretor manda do próprio celular (sincronizadas pela integração), atribuídas ao dono do contato. Exclui automação; notas internas e ligações não contam aqui.',
  conversas:'CONVERSAS: clientes distintos que o corretor atendeu no período (mandou ao menos 1 mensagem). O "· N c/ resposta" ao lado são as conversas em que o cliente também respondeu (interação nos dois sentidos).',
  resp:`RESPOSTA: em cima, o tempo TÍPICO (mediana) até a 1ª resposta manual. Embaixo, o NÍVEL DE SERVIÇO — % dos clientes respondidos dentro da meta de ${M1} min (e de ${M2} min). Tudo em horas comerciais (seg–sex 8h–20h, sáb 8h30–11h30, domingo não conta; Brasília — noites/domingos não são cobrados). Conta só recados que chegaram no expediente; não inclui quem ainda não foi respondido (esses estão em Aguardando). "poucos dados" = respostas de menos pra medir bem.`,
  dias:'DIAS ATIVOS: dias úteis do período em que o corretor deixou algum rastro no CRM (mensagem manual, nota ou ligação), sobre o total de dias úteis do período.',
  ativ:'ATIVIDADE: mensagens manuais por dia ao longo do período (mini-gráfico da tendência).',
  aten:'ATENÇÃO: score de urgência do corretor (quanto maior, mais precisa de atenção). Soma três blocos que competem — fila de clientes esperando agora (dominante), tempo de resposta e dias em que o cliente falou e ninguém agiu. Passe o mouse no número para ver a conta por bloco.',
};

function renderOverview(days){
  const rows=ACT.map(b=>{const s=stats(b,days);
    // score de atenção para quem tem atividade no período OU clientes aguardando
    // agora (o sumido-com-fila também precisa aparecer no topo)
    const temFila=LIVE&&(unreadClient(b)||0)>0;
    const at=((s.n+s.ic+s.cl)>0||temFila)?attention(b,days,s):null;
    return {b,s,at};});
  const k=state.sortKey||'aten', dir=state.sortDir||-1;
  rows.sort((a,b)=>{const va=sortVal(a,k),vb=sortVal(b,k);
    if(va<vb)return -dir; if(va>vb)return dir; return a.s.n<b.s.n?1:-1;});
  const totMsg=rows.reduce((s,r)=>s+r.s.n,0);
  const wdays=days.filter(d=>!d.weekend).length;
  const comAtiv=rows.filter(r=>(r.s.n+r.s.ic+r.s.cl)>0).length;
  const comAlerta=rows.filter(r=>r.at&&r.at.score>0).length;
  const cliTot=LIVE?ACT.reduce((s,b)=>s+(unreadClient(b)||0),0):null;
  const fupTot=LIVE?ACT.reduce((s,b)=>s+unreadFup(b),0):null;
  let h=`<div class="kpis">
    <div class="kpi" title="Total de mensagens manuais enviadas aos clientes no período (exclui automação; notas e ligações não contam)."><div class="v">${totMsg}</div><div class="l">mensagens manuais no período</div></div>
    <div class="kpi" title="Quantos corretores ativos deixaram algum rastro no CRM no período, sobre o total de corretores ativos."><div class="v">${comAtiv}/${ACT.length}</div><div class="l">corretores com atividade</div></div>
    <div class="kpi" title="Quantos corretores têm ao menos um sinal de alerta no período (veja a coluna Atenção)."><div class="v" style="color:${comAlerta?'#e06a5b':'inherit'}">${comAlerta}</div><div class="l">precisam de atenção</div></div>
    <div class="kpi" title="${TT.unread}"><div class="v" style="color:${cliTot?'#ff2d2d':'inherit'};${cliTot?'font-weight:800':''}">${cliTot==null?'—':cliTot}</div><div class="l">${EH_MES_CORRENTE?'clientes aguardando agora':'aguardando (só mês corrente)'}</div></div>
    <div class="kpi" title="${TT.fup}"><div class="v" style="color:${fupTot?'#e0a13a':'inherit'}">${fupTot==null?'—':fupTot}</div><div class="l">follow-ups pendentes</div></div>
  </div>
  <div class="sortbar"><span class="tag">Ordenar por:</span>`+
    SORTS.map(o=>`<button class="sortb ${k===o.k?'on':''}" data-k="${o.k}">${o.l}${k===o.k?(dir<0?' ▼':' ▲'):''}</button>`).join('')+
  `</div>
  <div class="card"><table><thead><tr>
  <th data-k="nome" class="sortable" title="${TT.nome}">Corretor</th><th class="n sortable wait-col" data-k="unread" title="${TT.unread}">Aguardando</th><th class="n sortable" data-k="fup" title="${TT.fup}">Follow-ups pend.</th><th class="n sortable" data-k="msgs" title="${TT.msgs}">Msgs</th><th class="n sortable" data-k="conversas" title="${TT.conversas}">Conversas</th><th class="n sortable" data-k="resp" title="${TT.resp}">Resposta</th><th class="n sortable" data-k="dias" title="${TT.dias}">Dias ativos</th><th title="${TT.ativ}">Atividade</th><th class="sortable" data-k="aten" title="${TT.aten}">Atenção</th>
  </tr></thead><tbody>`;
  rows.forEach(r=>{const s=r.s;
    const cli=unreadClient(r.b), fup=unreadFup(r.b);
    const waitCell=cli==null?'<span class="mut">—</span>':(cli>0?`<span class="wait-big">${cli}</span>`:'<span class="wait-zero">0</span>');
    const fupCell=cli==null?'<span class="mut">—</span>':(fup>0?`<span class="fup-num" title="não lidas de follow-up: automação/pós-ligação, sem recado novo do cliente">${fup}</span>`:'<span class="mut">0</span>');
    let rtCell;
    if(s.rtMed==null) rtCell='—';
    else{
      let sla;
      if(s.rtN>=RT_MINN&&s.sl1!=null){const bd=s.sl1>=50?'sl-ok':(s.sl1>=35?'sl-y':(s.sl1>=22?'sl-o':'sl-r'));
        sla=`<div class="sla"><span class="${bd}" title="${s.sl1}% dos clientes respondidos em até ${M1} min (${s.rtN} respostas)">${s.sl1}% ≤${M1}m</span> · <span title="${s.sl2}% respondidos em até ${M2} min">${s.sl2}% ≤${M2}m</span></div>`;}
      else sla=`<div class="sla mut">poucos dados (${s.rtN} resp.)</div>`;
      rtCell=`<span class="${s.rtMed>1800?'rt-hi':''}">${fmtDur(s.rtMed)}</span>${sla}`;
    }
    let aten;
    if(r.at){
      const chips=r.at.chips.map(a=>`<span class="chip mini ${a.c}" title="${escAttr(a.t)}">${a.s}</span>`).join('');
      aten=`<span class="score ${scoreBand(r.at.score)}" title="${escAttr(r.at.tip)}">${r.at.score}</span>${chips?' '+chips:(r.at.score<=0?' <span class="pill ok">ok</span>':'')}`;
    } else aten=(s.n+s.ic+s.cl)>0?'<span class="pill ok">ok</span>':'<span class="pill no">sem atividade</span>';
    h+=`<tr class="clk" data-id="${r.b.id}"><td title="${TT.nome}">${r.b.name}</td>
      <td class="n wait-col" title="${TT.unread}">${waitCell}</td>
      <td class="n" title="${TT.fup}">${fupCell}</td>
      <td class="n" title="${TT.msgs}">${s.n||'<span class=zero>0</span>'}</td>
      <td class="n" title="${TT.conversas}">${s.conversas||'<span class=zero>0</span>'}${s.conversasI?`<span class="sla"> · ${s.conversasI} c/ resp</span>`:''}</td>
      <td class="n" title="${TT.resp}">${rtCell}</td>
      <td class="n" title="${TT.dias}">${s.activeP}/${wdays}</td>
      <td title="${TT.ativ}">${spark(s.series)}</td>
      <td class="aten" title="${TT.aten}">${aten}</td></tr>`;});
  h+=`</tbody></table><div class="foot"><b style="color:#ff2d2d">Aguardando</b> = clientes com a última mensagem sem resposta (o cliente está de fato esperando) — é o principal ponto de atenção. <b style="color:#e0a13a">Follow-ups pendentes</b> = não lidas de automação/pós-ligação, sem recado novo do cliente (precisam de ação, mas ninguém está esperando resposta). "Resposta" = tempo <b>típico</b> (mediana) em cima e o <b>nível de serviço</b> embaixo (% dos clientes respondidos em até <b>${M1} min</b> e <b>${M2} min</b>, metas), tudo em <b>horas comerciais</b> (noites/domingos não contam). Não inclui quem ainda não foi respondido. Passe o mouse nos selos de <b>Atenção</b> para o detalhe${EH_MES_CORRENTE?'':' · Aguardando/Follow-up só no mês corrente'}. Clique num corretor para abrir o detalhe.</div></div>`;
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
    <div class="kpi" title="Clientes distintos atendidos no período (o corretor mandou ao menos 1 mensagem). O 'c/ resposta' são os que também responderam."><div class="v">${s.conversas}</div><div class="l">conversas${s.conversasI?` · ${s.conversasI} c/ resposta`:''}</div></div>
    <div class="kpi" title="Tempo até a 1ª resposta manual em horas comerciais (seg–sex 8h–20h, sáb 8h30–11h30; noites/domingos não contam). Mediana = típico; % ≤${M1}m/≤${M2}m = nível de serviço nas metas; média sofre da cauda. Não inclui quem ainda não foi respondido."><div class="v">${fmtDur(s.rtMed)}</div><div class="l">resposta mediana${(s.rtN>=RT_MINN&&s.sl1!=null)?` · ${s.sl1}% ≤${M1}m · ${s.sl2}% ≤${M2}m`:(s.rtN?` · poucos dados`:'')}${s.rtMean!=null?` · média ${fmtDur(s.rtMean)}`:''}</div></div>
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
    const dMed=Array.isArray(d.rt)?(d.rt.length?pct([...d.rt].sort((a,b)=>a-b),0.5):null):(d.rt_hist?rtMedian(d.rt_hist):null);
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
const escAttr=s=>(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
// normaliza p/ busca: sem acentos, minúsculas (joão -> joao, JOÃO -> joao)
const norm=s=>(s||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
function renderFila(){
  const A=(AGUARDANDO&&AGUARDANDO.items)?AGUARDANDO.items.slice():[];
  const gen=(AGUARDANDO&&AGUARDANDO.gen_ms)||0;
  A.forEach(it=>it._wait=(gen&&it.since)?Math.max(0,gen-it.since):0);
  // ---- filtros: busca livre + responsável (ignora acentos e maiúsculas) ----
  const q=norm(state.filaQ||'').trim();
  const resp=state.filaResp||'';
  // responsáveis presentes na fila (com contagem) para o seletor
  const respCount={}; A.forEach(it=>{const b=it.broker||'—'; respCount[b]=(respCount[b]||0)+1;});
  const respList=Object.keys(respCount).sort((a,b)=>a.localeCompare(b,'pt'));
  const match=it=>{
    if(resp && (it.broker||'—')!==resp) return false;
    if(q){const hay=norm((it.name||'')+' '+(it.phone||'')+' '+(it.broker||'')+' '+(it.text||''));
      if(!hay.includes(q)) return false;}
    return true;
  };
  const A2=A.filter(match);
  const k=state.filaSort,dir=state.filaDir;
  const val=it=>k==='broker'?(it.broker||'').toLowerCase():k==='cliente'?(it.name||'').toLowerCase():it._wait;
  A2.sort((a,b)=>{const va=val(a),vb=val(b);if(va<vb)return -dir;if(va>vb)return dir;return b._wait-a._wait;});
  const SF=[{k:'wait',l:'Tempo de espera'},{k:'broker',l:'Responsável'},{k:'cliente',l:'Cliente'}];
  const filtrando=(q||resp);
  const cont=filtrando?`${A2.length} de ${A.length} aguardando`:`${A.length} aguardando · retrato de ${AGUARDANDO?AGUARDANDO.generated:'—'}`;
  let h=`<div class="filterbar">
      <input id="filaSearch" type="search" autocomplete="off" placeholder="Buscar por cliente, telefone, responsável ou mensagem…" value="${escAttr(state.filaQ||'')}" title="Filtra a fila em tempo real por qualquer trecho do nome do cliente, telefone, responsável ou da última mensagem.">
      <select id="filaResp" title="Filtra a fila por corretor responsável.">
        <option value="">Todos os responsáveis (${A.length})</option>
        ${respList.map(b=>`<option value="${escAttr(b)}"${b===resp?' selected':''}>${b} (${respCount[b]})</option>`).join('')}
      </select>
      ${filtrando?`<button id="filaClear" class="sortb" title="Limpar filtros">✕ limpar</button>`:''}
      <span class="tag" style="margin-left:auto">${cont}</span>
    </div>
    <div class="sortbar"><span class="tag">Ordenar por:</span>`+
    SF.map(o=>`<button class="sortb ${k===o.k?'on':''}" data-fk="${o.k}">${o.l}${k===o.k?(dir<0?' ▼':' ▲'):''}</button>`).join('')+
    `</div>`;
  if(!A.length){h+=`<div class="card">Nenhum cliente aguardando resposta agora. 👍</div>`;document.getElementById('view').innerHTML=h;bindFila();return;}
  if(!A2.length){h+=`<div class="card">Nenhum resultado para esse filtro. <a href="#" id="filaClear2">Limpar</a>.</div>`;document.getElementById('view').innerHTML=h;bindFila();return;}
  h+=`<div class="card"><table class="fila"><thead><tr><th title="Clique na linha para ver as últimas mensagens da conversa."></th><th class="sortable" data-fk="cliente" title="Nome do cliente que está aguardando resposta.">Cliente</th><th title="Telefone do cliente. Clique para abrir o WhatsApp.">Telefone</th><th class="sortable" data-fk="broker" title="Corretor responsável pela conversa no CRM.">Responsável</th><th class="n sortable" data-fk="wait" title="Há quanto tempo o cliente está esperando (desde a última mensagem dele). Laranja = +4h, vermelho = +24h.">Espera</th><th title="Canal por onde o cliente falou (WhatsApp, Instagram, SMS etc.).">Canal</th><th title="Última mensagem de fato do cliente (ignora atividades do sistema, comentários internos e ligações).">Última mensagem do cliente</th><th title="Abre a ficha do cliente no WeSales, com a conversa dele ao lado.">Conversa</th></tr></thead><tbody>`;
  A2.forEach((it,i)=>{const wcls=it._wait>=864e5?'zero':(it._wait>=144e5?'rt-hi':'');const wa=waPhone(it.phone);
    const fone=it.phone?(wa?`<a href="${wa}" target="_blank" rel="noopener">${it.phone}</a>`:it.phone):'—';
    const txt=(it.text||'').replace(/\s+/g,' ').trim();const short=txt.length>90?txt.slice(0,90)+'…':(txt||'—');
    const cu=crmUrl(it);const abrir=cu?`<a class="crmlink" href="${cu}" target="_blank" rel="noopener" title="Abrir o cliente no CRM (WeSales)">Abrir ↗</a>`:'—';
    const nmsg=(it.thread&&it.thread.length)||0;
    const caret=nmsg?`<span class="caret" title="Ver a conversa (${nmsg} msgs)">▶</span>`:'';
    h+=`<tr class="filarow${nmsg?' hasthread':''}" data-i="${i}"><td class="cx">${caret}</td><td>${it.name||'—'}</td><td class="mut">${fone}</td><td>${it.broker||'—'}</td><td class="n"><span class="${wcls}">${fmtWait(it._wait)}</span></td><td class="mut">${CANAL[it.type]||it.type||'—'}</td><td class="msg" title="${(txt||'').replace(/"/g,'&quot;')}">${short}</td><td>${abrir}</td></tr>`;
    if(nmsg)h+=`<tr class="threadrow" data-ti="${i}" style="display:none"><td></td><td colspan="7">${renderThread(it.thread)}</td></tr>`;});
  h+=`</tbody></table><div class="foot">Só clientes com a <b>última mensagem sem resposta</b> (aguardando de fato — não conta follow-up) e apenas mensagens <b>reais do cliente</b> (ignora "opportunity updated", comentários internos e registros de ligação). <span class="rt-hi">Laranja</span> = +4h; <span class="zero">vermelho</span> = +24h. Clique na linha para ver as últimas mensagens da conversa. O telefone abre o WhatsApp; "Abrir ↗" abre no CRM (WeSales). Atualiza a cada coleta.</div></div>`;
  document.getElementById('view').innerHTML=h;bindFila();
}
const KIND={in:{c:'#cbe8c0',l:'Cliente'},sys:{c:'#8a93a3',l:'Sistema'},int:{c:'#c9a76a',l:'Nota interna'},call:{c:'#8a93a3',l:'Ligação'}};
function renderThread(th){
  if(!th||!th.length)return '<div class="mut" style="padding:6px 0">Sem mensagens.</div>';
  let s='<div class="thread">';
  th.forEach(m=>{
    const cli=m.dir==='in';
    const who=m.kind==='msg'?(cli?'Cliente':'Corretor'):(KIND[m.kind]?KIND[m.kind].l:'Sistema');
    const sys=(m.kind==='sys'||m.kind==='int'||m.kind==='call');
    const body=(m.body||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
    s+=`<div class="tmsg ${cli?'tin':'tout'} ${sys?'tsys':''}"><div class="thead">${who}${m.t?` · ${m.t}`:''}</div><div class="tbody">${body||'—'}</div></div>`;
  });
  return s+'</div>';
}
function bindFila(){
  document.querySelectorAll('th[data-fk]').forEach(el=>el.onclick=()=>{
    const nk=el.dataset.fk;if(state.filaSort===nk)state.filaDir=-state.filaDir;else{state.filaSort=nk;state.filaDir=(nk==='wait')?-1:1;}renderFila();});
  document.querySelectorAll('tr.filarow.hasthread').forEach(tr=>tr.onclick=e=>{
    if(e.target.closest('a'))return; // não intercepta cliques em links (telefone/Abrir)
    const i=tr.dataset.i;const dr=document.querySelector(`tr.threadrow[data-ti="${i}"]`);if(!dr)return;
    const open=dr.style.display!=='none';dr.style.display=open?'none':'';
    const c=tr.querySelector('.caret');if(c)c.textContent=open?'▶':'▼';tr.classList.toggle('on',!open);});
  // ---- filtros da fila ----
  const inp=document.getElementById('filaSearch');
  if(inp){
    inp.oninput=()=>{state.filaQ=inp.value;state._filaFocus=true;renderFila();};
    if(state._filaFocus){inp.focus();const v=inp.value;inp.value='';inp.value=v;state._filaFocus=false;}
  }
  const sel=document.getElementById('filaResp');
  if(sel)sel.onchange=()=>{state.filaResp=sel.value;renderFila();};
  const clr=document.getElementById('filaClear');
  if(clr)clr.onclick=()=>{state.filaQ='';state.filaResp='';renderFila();};
  const clr2=document.getElementById('filaClear2');
  if(clr2)clr2.onclick=e=>{e.preventDefault();state.filaQ='';state.filaResp='';renderFila();};
}

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
/* ===== estado persistido + auto-atualização (sem precisar de F5) ===== */
const SS_KEY='painelCorretores:'+<?= json_encode($selMonth) ?>;
function saveState(){try{sessionStorage.setItem(SS_KEY,JSON.stringify({
  broker:state.broker,from:state.from,to:state.to,sortKey:state.sortKey,sortDir:state.sortDir,
  tab:state.tab,filaSort:state.filaSort,filaDir:state.filaDir,filaQ:state.filaQ||'',filaResp:state.filaResp||'',
  y:Math.round(window.scrollY)}));}catch(e){}}
function loadState(){try{const s=JSON.parse(sessionStorage.getItem(SS_KEY)||'null');if(!s)return null;
  const keys=new Set(AD.map(d=>d.key));
  if(!keys.has(s.from))s.from=AD[0].key;
  if(!keys.has(s.to))s.to=AD[AD.length-1].key;
  if(s.broker!=='__all__'&&!byId[s.broker])s.broker='__all__';
  if(s.tab==='fila'&&!(EH_MES_CORRENTE&&AGUARDANDO))s.tab='geral';
  return s;}catch(e){return null;}}

const _s=loadState();
if(_s)Object.assign(state,_s);
buildTabs();
if(_s){
  bsel.value=state.broker; fsel.value=state.from; tsel.value=state.to;
  // destaca o preset que corresponde ao período restaurado (mês todo / hoje / ontem)
  const first=AD[0].key, last=AD[AD.length-1].key, yk=AD.length>=2?AD[AD.length-2].key:null;
  let p=null;
  if(state.from===first&&state.to===last)p='all';
  else if(state.from===last&&state.to===last)p='today';
  else if(yk&&state.from===yk&&state.to===yk)p='yest';
  [...document.querySelectorAll('#presets button')].forEach(x=>x.classList.toggle('on',x.dataset.p===p));
  render();
  window.scrollTo(0,_s.y||0);
}else{
  setPreset('today');   // padrão ao abrir: HOJE
}

/* salva o estado periodicamente e antes de sair (cobre qualquer interação) */
setInterval(saveState,2000);
window.addEventListener('beforeunload',saveState);

/* auto-reload: recarrega sozinho pra puxar a coleta nova (fila a cada 5 min,
   geral a cada hora). Adia enquanto você está mexendo — digitando no filtro,
   com um campo em foco ou lendo uma conversa expandida — e reinicia a contagem
   a cada clique/tecla/rolagem, então nunca recarrega no meio de uma ação. */
(function(){
  const MS=60000; let tmr; const loadedAt=Date.now();
  function busy(){
    const a=document.activeElement;
    if(a&&(a.id==='filaSearch'||a.tagName==='INPUT'||a.tagName==='SELECT'||a.tagName==='TEXTAREA'))return true;
    if(document.querySelector('tr.filarow.on'))return true;   // conversa expandida
    return false;
  }
  function reload(){ saveState(); location.reload(); }
  function go(){ if(document.hidden||busy()){arm();return;} reload(); }   // não recarrega aba oculta
  function arm(){ clearTimeout(tmr); tmr=setTimeout(go,MS); }
  arm();
  // Ao trazer a aba de volta ao primeiro plano, se já passou do intervalo,
  // atualiza NA HORA — assim, quando você olha o painel, ele já está fresco
  // (em vez de esperar o próximo ciclo de 60s).
  document.addEventListener('visibilitychange',()=>{
    if(!document.hidden && !busy() && Date.now()-loadedAt>=MS) reload();
  });
  ['mousedown','keydown','touchstart','wheel'].forEach(ev=>
    document.addEventListener(ev,arm,{capture:true,passive:true}));
})();
if(window.__painelReady)window.__painelReady();   // inicialização completa → desarma o watchdog
</script></body></html>
