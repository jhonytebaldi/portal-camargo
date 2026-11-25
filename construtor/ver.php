<?php
/* construtor/ver.php?p=<slug> — abre o painel da construtora para usuário logado.
   O HTML do painel (paginas/<slug>.html) não tem dados: eles vêm do banco
   e são injetados aqui, só para quem tem permissão. */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
$slug = (string)($_GET['p'] ?? '');
if (!co_slug_ok($slug)) { http_response_code(404); exit('Página não encontrada.'); }
[$u] = co_guard($slug);
$st = db()->prepare('SELECT dados, atualizado_em, pedido_em FROM co_paginas WHERE slug=?'); $st->execute([$slug]);
$row = $st->fetch() ?: [];
if (empty($row['dados'])) {
    portal_header('Área do Construtor', $u);
    echo '<div class="aviso">Os dados desta página ainda não foram carregados.</div>';
    portal_footer(); exit;
}
co_render($slug, $row, 'usuario', $u);
