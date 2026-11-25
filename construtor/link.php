<?php
/* construtor/link.php?t=<token> — visualização SEM LOGIN, só leitura, por link
   temporário gerado pelo admin em dados.php. Sem edição de distrato, sem pedido
   de atualização. O token só existe em hash no banco. */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
$t = (string)($_GET['t'] ?? '');
$fim = function (string $msg): void {
    http_response_code(404);
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Link indisponível</title>'
       . '<div style="font:16px system-ui,sans-serif;max-width:520px;margin:12vh auto;padding:24px;text-align:center;color:#121a3a"><h1 style="font-size:22px">Link indisponível</h1><p>' . h($msg) . '</p><p style="color:#5a6385">Peça um novo link à Imobiliária Camargo.</p></div>';
    exit;
};
if (!preg_match('/^[a-f0-9]{40,64}$/', $t)) $fim('Este link não é válido.');
$pdo = db();
$st = $pdo->prepare('SELECT * FROM co_links WHERE token_hash=?'); $st->execute([hash('sha256', $t)]);
$l = $st->fetch();
if (!$l || $l['revogado']) $fim('Este link foi cancelado ou não existe.');
if (strtotime($l['expira_em']) < time()) $fim('Este link expirou.');
$st = $pdo->prepare('SELECT dados, atualizado_em FROM co_paginas WHERE slug=? AND ativo=1'); $st->execute([$l['slug']]);
$row = $st->fetch();
if (!$row || empty($row['dados'])) $fim('Os dados desta página não estão disponíveis.');
$pdo->prepare('UPDATE co_links SET acessos=acessos+1, ultimo_acesso=NOW() WHERE id=?')->execute([$l['id']]);
co_render($l['slug'], $row, 'link', null, (string)$l['rotulo'], (string)$l['expira_em']);
