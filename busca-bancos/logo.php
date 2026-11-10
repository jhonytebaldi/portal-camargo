<?php
/* busca-bancos/logo.php — serve a logo de um banco enviada em "Contas" (guardada no banco de dados). */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
require_tool('busca-bancos');
$b = preg_replace('/[^a-z0-9\-]/', '', (string)($_GET['b'] ?? ''));
$v = BbImportador::config(db(), 'logo:' . $b);
if (!is_array($v) || empty($v['dados'])) { http_response_code(404); exit; }
header('Content-Type: ' . $v['tipo']);
header('Cache-Control: private, max-age=604800');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
echo base64_decode($v['dados']);
