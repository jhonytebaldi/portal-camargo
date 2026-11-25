<?php
/* construtor/api-importar.php?p=<slug> — carga automática dos dados de uma
   página (usada pela rotina agendada que remonta o painel a partir das
   fontes: planilha de fluxo, contratos no Drive, tabela de vendas, CUB).
   Autenticação por token de serviço (CONSTRUTOR_TOKEN no config.php do
   servidor). Preserva distratos marcados pelo admin no portal que o
   arquivo novo ainda não traga. */
declare(strict_types=1);
require_once __DIR__ . '/../lib/db.php';
header('Content-Type: application/json; charset=utf-8');
function co_api_out(int $code, array $x): void { http_response_code($code); echo json_encode($x, JSON_UNESCAPED_UNICODE); exit; }
portal_load_config();
$tok = $_SERVER['HTTP_X_PORTAL_TOKEN'] ?? '';
if (!defined('CONSTRUTOR_TOKEN') || CONSTRUTOR_TOKEN === '' || !hash_equals(CONSTRUTOR_TOKEN, (string)$tok)) co_api_out(403, ['ok' => false, 'msg' => 'token inválido']);
$slug = (string)($_GET['p'] ?? '');
if (!preg_match('/^[a-z0-9-]{1,40}$/', $slug)) co_api_out(400, ['ok' => false, 'msg' => 'página inválida']);
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $st = $pdo->prepare('SELECT dados, atualizado_em FROM co_paginas WHERE slug=?'); $st->execute([$slug]);
    $r = $st->fetch(); if (!$r) co_api_out(404, ['ok' => false, 'msg' => 'página não existe']);
    co_api_out(200, ['ok' => true, 'atualizado_em' => $r['atualizado_em'], 'dados' => $r['dados'] ? json_decode($r['dados'], true) : null]);
}
$novo = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($novo) || !isset($novo['C'], $novo['M'])) co_api_out(400, ['ok' => false, 'msg' => 'JSON inválido (esperado C e M)']);
$pdo->beginTransaction();
$st = $pdo->prepare('SELECT dados FROM co_paginas WHERE slug=? FOR UPDATE'); $st->execute([$slug]);
$velho = json_decode((string)$st->fetchColumn(), true);
$mantidos = 0;
if (is_array($velho) && isset($velho['C'])) {
    $dis = [];
    foreach ($velho['C'] as $c) if (($c['st'] ?? '') === 'distrato') $dis[$c['u'] . '|' . $c['cli']] = $c['dis'] ?? null;
    foreach ($novo['C'] as &$c) {
        $k = $c['u'] . '|' . $c['cli'];
        if (($c['st'] ?? '') !== 'distrato' && array_key_exists($k, $dis)) { $c['st'] = 'distrato'; $c['dis'] = $dis[$k]; $mantidos++; }
    }
    unset($c);
}
$up = $pdo->prepare('UPDATE co_paginas SET dados=?, atualizado_em=NOW(), atualizado_por=NULL WHERE slug=?');
$up->execute([json_encode($novo, JSON_UNESCAPED_UNICODE), $slug]);
$pdo->commit();
co_api_out(200, ['ok' => true, 'contratos' => count($novo['C']), 'distratos_preservados' => $mantidos]);
