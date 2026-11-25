<?php
/* construtor/ver.php?p=<slug> — abre o painel da construtora.
   O HTML do painel (paginas/<slug>.html) não tem dados: eles vêm do banco
   e são injetados aqui, só para quem tem permissão. */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
$slug = (string)($_GET['p'] ?? '');
if (!co_slug_ok($slug)) { http_response_code(404); exit('Página não encontrada.'); }
[$u, $pag] = co_guard($slug);
$tpl = __DIR__ . '/paginas/' . $slug . '.html';
if (!is_file($tpl)) { http_response_code(404); exit('Painel ainda não publicado.'); }
$st = db()->prepare('SELECT dados, atualizado_em, pedido_em FROM co_paginas WHERE slug=?'); $st->execute([$slug]);
$row = $st->fetch() ?: [];
$dados = (string)($row['dados'] ?? '');
if ($dados === '') {
    portal_header('Área do Construtor', $u);
    echo '<div class="aviso">Os dados desta página ainda não foram carregados.</div>';
    portal_footer(); exit;
}
$admin = is_admin($u);
$json = str_replace('</', '<\\/', $dados);
$cfg = '<script>window.CO_EDIT=' . ($admin ? 'true' : 'false')
     . ';window.CO_API=' . ($admin ? json_encode('acao.php?p=' . $slug) : 'null')
     . ';window.CO_CSRF=' . json_encode(co_csrf()) . ';</script>';
$barra = '<div style="background:#1d3270;color:#d3ddf7;font:13px Ubuntu,system-ui,sans-serif;padding:8px max(5vw,16px);display:flex;gap:16px;align-items:center;flex-wrap:wrap">'
       . '<a href="/" style="color:#fff;text-decoration:none;font-weight:700">← Portal Camargo</a>'
       . (count(co_paginas_visiveis($u)) > 1 ? '<a href="/construtor/" style="color:#d3ddf7">Outras construtoras</a>' : '')
       . '<span style="margin-left:auto">Dados de ' . h($row['atualizado_em'] ? date('d/m/Y H:i', strtotime($row['atualizado_em'])) : '—') . '</span>'
       . '<button id="coPedir" type="button" style="font:600 13px Ubuntu,system-ui,sans-serif;min-height:34px;padding:0 14px;border:0;border-radius:8px;background:#f2b705;color:#121a3a;cursor:pointer"'
       . ($row['pedido_em'] ? ' disabled>Atualização solicitada às ' . h(date('H:i', strtotime($row['pedido_em']))) . ' · em até 1 hora' : '>Solicitar atualização') . '</button>'
       . '<span>' . h($u['nome']) . '</span><a href="/logout.php" style="color:#d3ddf7">Sair</a></div>'
       . '<script>document.getElementById("coPedir").onclick=async function(){const b=this;b.disabled=true;b.textContent="Enviando…";try{const r=await fetch("pedir.php?p=' . h($slug) . '",{method:"POST",headers:{"X-CSRF":window.CO_CSRF}});const j=await r.json();if(!j.ok)throw new Error(j.msg);b.textContent="Atualização solicitada · os dados serão refeitos em até 1 hora"}catch(e){b.disabled=false;b.textContent="Tentar de novo";alert("Não foi possível solicitar: "+e.message)}};</script>';
$html = file_get_contents($tpl);
$html = str_replace('__DATA__', $json, $html);
$html = preg_replace('/<body>/', '<body>' . $barra, $html, 1);
$html = str_replace('</head>', '<meta name="robots" content="noindex,nofollow">' . $cfg . '</head>', $html);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo $html;
