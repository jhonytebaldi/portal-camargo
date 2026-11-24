<?php
/* raid-logs/index.php — Análise de logs de raid (Warcraft Logs).
   Página única; os dados vêm de api.php. Só para quem tem a ferramenta 'raid-logs'. */
require_once dirname(__DIR__) . '/lib/auth.php';
require_tool('raid-logs');
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
$v = max((int)@filemtime(__DIR__ . '/app.js'), (int)@filemtime(__DIR__ . '/core.js'));
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex,nofollow">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Análise de Logs da Raid</title>
<style>
  :root { color-scheme: dark; --sup: #1a1a19; --sup2: #232322; --borda: #383835; --txt: #f0efec; --txt2: #c3c2b7; --mudo: #8f8e85;
          --s1: #3987e5; --s2: #d95926; --s3: #199e70; --s4: #c98500; --critico: #e66767; }
  * { box-sizing: border-box; }
  body { font: 15px/1.5 system-ui, "Segoe UI", sans-serif; background: var(--sup); color: var(--txt); margin: 0; padding: 24px 28px 60px; }
  main { max-width: 1100px; margin: 0 auto; }
  h1 { font-size: 22px; margin: 0 0 14px; }
  h2 { font-size: 16px; margin: 28px 0 4px; }
  .sub { color: var(--txt2); font-size: 13.5px; }
  .erro, .alerta { color: var(--critico); }
  textarea { width: 100%; height: 92px; background: var(--sup2); color: inherit; border: 1px solid var(--borda); border-radius: 6px; padding: 10px; font: 13px/1.5 Consolas, monospace; }
  button, a.btn { background: var(--s1); color: #fff; border: 0; border-radius: 6px; padding: 8px 16px; font: inherit; cursor: pointer; text-decoration: none; display: inline-block; }
  button:disabled { opacity: .5; cursor: default; }
  select { background: var(--sup2); color: inherit; border: 1px solid var(--borda); border-radius: 6px; padding: 7px 10px; font: inherit; }
  .linha { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin: 12px 0; }
  .chip { background: var(--sup2); border: 1px solid var(--borda); border-radius: 999px; padding: 4px 12px; font-size: 13.5px; cursor: pointer; }
  .abas { display: flex; gap: 4px; border-bottom: 1px solid var(--borda); margin-top: 18px; }
  .aba, .fase { background: none; color: var(--txt2); border-radius: 6px 6px 0 0; padding: 9px 16px; border-bottom: 2px solid transparent; }
  .fase { border: 1px solid var(--borda); border-radius: 6px; padding: 6px 12px; }
  .aba.ativa { color: var(--txt); border-bottom-color: var(--s1); }
  .fase.ativa { color: #fff; background: var(--s1); border-color: var(--s1); }
  table { border-collapse: collapse; width: 100%; margin: 8px 0 8px; font-size: 14px; }
  th, td { text-align: left; padding: 6px 10px; border-bottom: 1px solid var(--borda); vertical-align: top; }
  th { color: var(--txt2); font-weight: 600; font-size: 13px; }
  .n { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-top: 18px; }
  .card { background: var(--sup2); border: 1px solid var(--borda); border-radius: 8px; padding: 12px 14px; }
  .card .k { color: var(--txt2); font-size: 13px; } .card .v { font-size: 24px; font-weight: 600; }
  .duas { display: grid; grid-template-columns: 1fr 1fr; gap: 28px; }
  .selo { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 7px; }
  .legenda { display: flex; gap: 18px; font-size: 13px; color: var(--txt2); margin: 8px 0 2px; }
  .legenda i { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; }
  .graf { width: 100%; height: auto; display: block; }
  .grafbox { position: relative; }
  .grade { stroke: var(--borda); stroke-width: 1; opacity: .6; } .sep { stroke: var(--mudo); stroke-dasharray: 3 4; }
  .eixo { fill: var(--mudo); font-size: 11.5px; } .rot { fill: var(--txt); font-size: 12px; font-weight: 600; } .rot2 { fill: var(--txt2); font-size: 12.5px; }
  .area { fill: var(--s1); opacity: .16; } .curva { fill: none; stroke: var(--s1); stroke-width: 2; stroke-linejoin: round; }
  .pico { fill: var(--sup); stroke: var(--txt); stroke-width: 2; }
  .uso { fill: var(--s1); opacity: .32; } .med { stroke: var(--txt); stroke-width: 2; }
  .mira { stroke: var(--txt2); stroke-width: 1; pointer-events: none; }
  .dica { position: absolute; background: var(--sup2); border: 1px solid var(--borda); border-radius: 6px; padding: 8px 10px; font-size: 13px; width: 240px; pointer-events: none; }
  .aviso { background: var(--sup2); border: 1px solid var(--borda); border-left: 3px solid var(--s1); border-radius: 6px; padding: 8px 12px; margin: 12px 0; font-size: 14px; }
  .atencao { color: #fab219; } .apagado td { opacity: .55; }
  button.sec { background: var(--sup2); border: 1px solid var(--borda); color: var(--txt); }
  button.link { background: none; color: var(--s1); padding: 0; text-decoration: underline; font-size: inherit; }
  .grupo { border: 1px solid var(--borda); border-radius: 8px; padding: 6px 14px 10px; margin: 12px 0; }
  .grupo summary { cursor: pointer; padding: 6px 0; color: var(--txt2); }
  .sug { border-top: 1px solid var(--borda); padding: 10px 0 2px; font-size: 14.5px; }
  .sug .linha { margin: 8px 0 6px; }
  .tipo { display: inline-block; border: 1px solid var(--borda); border-radius: 4px; padding: 0 6px; font-size: 12px; color: var(--txt2); margin-right: 4px; }
  .motivo { background: var(--sup2); color: inherit; border: 1px solid var(--borda); border-radius: 6px; padding: 7px 10px; font: inherit; font-size: 13.5px; width: 260px; }
  .semana { margin: 6px 0; } .chip.forte { border-color: var(--mudo); font-weight: 600; }
  .en { color: var(--mudo); font-size: 12.5px; }
  a.wh { color: inherit; text-decoration: underline dotted var(--mudo); text-underline-offset: 3px; } a.wh:hover { color: var(--s1); }
  .note { height: 300px; } .note.curta { height: 110px; }
  @media (max-width: 800px) { .cards { grid-template-columns: 1fr 1fr; } .duas { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<main>
<div style="display:flex;justify-content:space-between;align-items:baseline;gap:16px"><h1>Análise de Logs da Raid</h1><span class="sub"><a href="/" style="color:inherit">← Portal</a><span id="adminBar" hidden> · <a href="conectar.php?sair=1" style="color:inherit">Desconectar o Warcraft Logs</a></span></span></div>
<div id="login" hidden>
  <div id="loginAdmin" hidden><p class="erro">A ferramenta ainda não está conectada ao Warcraft Logs.</p><a class="btn" href="conectar.php">Conectar com Warcraft Logs</a><p class="sub">Você autoriza uma vez com a conta que enxerga os logs privados; quem tiver acesso à ferramenta passa a ver os mesmos relatórios por ela.</p></div>
  <div id="loginOutros" hidden><p class="erro">A ferramenta ainda não está conectada ao Warcraft Logs. Peça ao administrador para conectar.</p></div>
</div>
<div id="app" hidden>
  <div class="linha"><input id="links" class="motivo" style="width:420px" placeholder="Link de um relatório do Warcraft Logs" aria-label="Link do relatório"><button id="carregar">Adicionar / atualizar</button><span id="progresso" class="sub"></span></div>
  <div id="filtros" hidden>
    <div id="noites"></div>
    <div class="linha"><span class="sub">Boss:</span><select id="boss" aria-label="Boss"></select></div>
    <div class="abas">
      <button class="aba" data-aba="geral">Visão geral</button>
      <button class="aba" data-aba="mortes">Mortes</button>
      <button class="aba" data-aba="dano">Dano e CDs</button>
      <button class="aba" data-aba="healers">Healers e pessoais</button>
      <button class="aba" data-aba="plano">Plano e note</button>
      <button class="aba" data-aba="ultima">Última try</button>
      <button class="aba" data-aba="boss" id="abaBoss" hidden>Boss</button>
    </div>
    <div id="conteudo"></div>
  </div>
</div>
</main>
<script>const whTooltips = { colorLinks: false, iconizeLinks: false, renameLinks: false };</script>
<script src="https://wow.zamimg.com/js/tooltips.js" async></script>
<script src="core.js?v=<?= $v ?>"></script>
<script src="app.js?v=<?= $v ?>"></script>
</body>
</html>
