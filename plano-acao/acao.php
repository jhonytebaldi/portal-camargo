<?php
/* =====================================================================
   plano-acao/acao.php — marca/desmarca o check de um item (AJAX, sessão).
   POST: item_id, feito (0|1). Só permite em itens do escopo do usuário.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
header('Content-Type: application/json; charset=utf-8');

$u = require_tool('plano-acao');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'erro' => 'use POST']));
}

$itemId = (int)($_POST['item_id'] ?? 0);
$feito  = ((string)($_POST['feito'] ?? '')) === '1' ? 1 : 0;
if (!$itemId) { http_response_code(400); exit(json_encode(['ok' => false, 'erro' => 'item_id?'])); }

$pdo = db();
$st = $pdo->prepare(
    'SELECT i.id, p.robust_atendente FROM pa_itens i
      JOIN pa_planos p ON p.id = i.plano_id WHERE i.id = ?');
$st->execute([$itemId]);
$row = $st->fetch();
if (!$row) { http_response_code(404); exit(json_encode(['ok' => false, 'erro' => 'item não existe'])); }

$permit = pa_allowed_robust_ids($u);           // null = admin (tudo)
if ($permit !== null && !in_array((int)$row['robust_atendente'], $permit, true)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'erro' => 'fora do seu escopo']));
}

$pdo->prepare('UPDATE pa_itens SET feito = ?, feito_em = ?, feito_por = ? WHERE id = ?')
    ->execute([$feito, $feito ? date('Y-m-d H:i:s') : null, $feito ? (int)$u['id'] : null, $itemId]);

echo json_encode(['ok' => true, 'item_id' => $itemId, 'feito' => $feito]);
