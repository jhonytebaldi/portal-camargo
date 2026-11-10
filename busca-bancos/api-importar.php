<?php
/* =====================================================================
   busca-bancos/api-importar.php — recebe um OFX da sincronização do Drive
   (Google Apps Script). Sem sessão: autentica pelo token gerado na tela
   "Importar extratos" (guardado em bb_config, pode ser trocado lá).
   POST JSON: {nome, drive_id, caminho, conteudo_b64[, token]} — token no cabeçalho X-Portal-Token ou no JSON
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/lib/Importador.php';
date_default_timezone_set('America/Sao_Paulo');
$sai = function (array $d, int $c = 200): void { http_response_code($c); header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $sai(['erro' => 'use POST'], 405);
$pdo = db();
try { $token = (string)BbImportador::config($pdo, 'token_drive', ''); } catch (Throwable $e) { $sai(['erro' => 'módulo ainda não instalado'], 503); }
$j = json_decode((string)file_get_contents('php://input'), true);
// o token vem no cabeçalho (script do Google) ou dentro do próprio JSON (envio feito por uma página do navegador, que não pode mandar cabeçalho próprio)
$veio = (string)($_SERVER['HTTP_X_PORTAL_TOKEN'] ?? (is_array($j) ? ($j['token'] ?? '') : ''));
if ($token === '' || $veio === '' || !hash_equals($token, $veio)) $sai(['erro' => 'token inválido'], 401);
$bruto = is_array($j) ? base64_decode((string)($j['conteudo_b64'] ?? ''), true) : false;
if ($bruto === false || $bruto === '') $sai(['erro' => 'conteúdo ausente'], 422);
if (strlen($bruto) > 8 * 1024 * 1024) $sai(['erro' => 'arquivo grande demais'], 422);
try {
    $res = BbImportador::importarOfx($pdo, $bruto, ['nome' => (string)($j['nome'] ?? 'extrato.ofx'), 'origem' => 'drive', 'drive_id' => (string)($j['drive_id'] ?? '') ?: null, 'drive_caminho' => (string)($j['caminho'] ?? '') ?: null]);
} catch (RuntimeException $e) { $sai(['erro' => $e->getMessage()], 422); }
catch (Throwable $e) { error_log('busca-bancos api: ' . $e->getMessage()); $sai(['erro' => 'falha interna'], 500); }
BbImportador::configSet($pdo, 'drive_ultima', date('Y-m-d H:i:s'));
$sai(['ok' => true, 'resultados' => array_map(fn($r) => ['status' => $r['status'], 'conta' => $r['conta']['apelido'], 'novos' => $r['novos'], 'repetidos' => $r['dup'], 'de' => $r['de'], 'ate' => $r['ate'], 'avisos' => $r['avisos']], $res)]);
