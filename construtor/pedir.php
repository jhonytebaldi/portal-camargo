<?php
/* construtor/pedir.php?p=<slug> — o construtor (ou admin) pede para os dados
   serem atualizados. Só registra o pedido; a rotina agendada, que checa de
   hora em hora, refaz os dados e limpa o pedido. */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
header('Content-Type: application/json; charset=utf-8');
function co_p_out(array $x): void { echo json_encode($x, JSON_UNESCAPED_UNICODE); exit; }
$slug = (string)($_GET['p'] ?? '');
if (!co_slug_ok($slug) || $_SERVER['REQUEST_METHOD'] !== 'POST') co_p_out(['ok' => false, 'msg' => 'Pedido inválido.']);
[$u] = co_guard($slug);
portal_session_start();
$tok = $_SERVER['HTTP_X_CSRF'] ?? '';
if (!$tok || !hash_equals($_SESSION['csrf'] ?? '', (string)$tok)) co_p_out(['ok' => false, 'msg' => 'Sessão expirada. Recarregue a página.']);
$pdo = db();
$pdo->prepare('UPDATE co_paginas SET pedido_em=COALESCE(pedido_em, NOW()), pedido_por=COALESCE(pedido_por, ?) WHERE slug=?')->execute([$u['id'], $slug]);
$st = $pdo->prepare('SELECT pedido_em FROM co_paginas WHERE slug=?'); $st->execute([$slug]);
co_p_out(['ok' => true, 'pedido_em' => $st->fetchColumn()]);
