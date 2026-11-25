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
$st = db()->prepare('SELECT dados FROM co_paginas WHERE slug=?'); $st->execute([$slug]);
$dados = (string)($st->fetchColumn() ?: '');
if ($dados === '') {
    portal_header('Área do Construtor', $u);
    echo '<div class="aviso">Os dados desta página ainda não foram carregados.</div>';
    portal_footer(); exit;
}
$admin = is_admin($u);
$json = str_replace('</', '<\\/', $dados);
$cfg = '<script>window.CO_EDIT=' . ($admin ? 'true' : 'false')
     . ';window.CO_API=' . ($admin ? json_encode('acao.php?p=' . $slug) : 'null')
     . ';window.CO_CSRF=' . json_encode($admin ? co_csrf() : '') . ';</script>';
$barra = '<div style="background:#1d3270;color:#d3ddf7;font:13px Ubuntu,system-ui,sans-serif;padding:8px max(5vw,16px);display:flex;gap:16px;align-items:center;flex-wrap:wrap">'
       . '<a href="/" style="color:#fff;text-decoration:none;font-weight:700">← Portal Camargo</a>'
       . (count(co_paginas_visiveis($u)) > 1 ? '<a href="/construtor/" style="color:#d3ddf7">Outras construtoras</a>' : '')
       . '<span style="margin-left:auto">' . h($u['nome']) . '</span><a href="/logout.php" style="color:#d3ddf7">Sair</a></div>';
$html = file_get_contents($tpl);
$html = str_replace('__DATA__', $json, $html);
$html = preg_replace('/<body>/', '<body>' . $barra, $html, 1);
$html = str_replace('</head>', '<meta name="robots" content="noindex,nofollow">' . $cfg . '</head>', $html);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo $html;
