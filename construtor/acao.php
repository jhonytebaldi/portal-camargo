<?php
/* construtor/acao.php?p=<slug> — gravações no painel (SÓ ADMIN + CSRF).
   Hoje: marcar/desmarcar distrato de um contrato. O construtor nunca
   edita: mesmo que chame esta URL, recebe 403. */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
header('Content-Type: application/json; charset=utf-8');
function co_out(array $x): void { echo json_encode($x, JSON_UNESCAPED_UNICODE); exit; }
$slug = (string)($_GET['p'] ?? '');
if (!co_slug_ok($slug)) co_out(['ok' => false, 'msg' => 'Página inválida.']);
[$u] = co_guard($slug);
if (!is_admin($u)) { http_response_code(403); co_out(['ok' => false, 'msg' => 'Apenas administradores podem editar.']); }
portal_session_start();
$tok = $_SERVER['HTTP_X_CSRF'] ?? '';
if (!$tok || !hash_equals($_SESSION['csrf'] ?? '', (string)$tok)) { http_response_code(403); co_out(['ok' => false, 'msg' => 'Sessão expirada. Recarregue a página.']); }
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in) || ($in['acao'] ?? '') !== 'distrato') co_out(['ok' => false, 'msg' => 'Ação desconhecida.']);

$pdo = db();
$pdo->beginTransaction();
try {
    $st = $pdo->prepare('SELECT dados FROM co_paginas WHERE slug=? FOR UPDATE'); $st->execute([$slug]);
    $d = json_decode((string)$st->fetchColumn(), true);
    if (!is_array($d) || !isset($d['C'])) throw new RuntimeException('Dados da página não carregados.');
    $achou = false;
    foreach ($d['C'] as &$c) {
        if ((int)$c['u'] === (int)($in['u'] ?? 0) && (string)$c['cli'] === (string)($in['cli'] ?? '')) {
            $st2 = ($in['st'] ?? '') === 'distrato' ? 'distrato' : 'vendida';
            $c['st'] = $st2;
            if ($st2 === 'distrato') {
                $x = (array)($in['dis'] ?? []);
                $num = fn($v) => ($v === null || $v === '') ? null : (float)$v;
                $quem = in_array($x['quem'] ?? '', ['gbrac', 'camargo', 'prop'], true) ? $x['quem'] : 'gbrac';
                $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($x['data'] ?? '')) ? $x['data'] : date('Y-m-d');
                $c['dis'] = ['data' => $data, 'dev' => $num($x['dev'] ?? null), 'multa' => $num($x['multa'] ?? null), 'quem' => $quem, 'parc' => null];
            } else {
                $c['dis'] = null;
            }
            $achou = true; break;
        }
    }
    unset($c);
    if (!$achou) throw new RuntimeException('Contrato não encontrado.');
    $pdo->prepare('UPDATE co_paginas SET dados=?, atualizado_em=NOW(), atualizado_por=? WHERE slug=?')
        ->execute([json_encode($d, JSON_UNESCAPED_UNICODE), $u['id'], $slug]);
    $pdo->commit();
    co_out(['ok' => true]);
} catch (Throwable $e) {
    $pdo->rollBack();
    co_out(['ok' => false, 'msg' => $e->getMessage()]);
}
