<?php
/* =====================================================================
   capa-financeira/upload.php — recebe a capa (.xlsx), extrai os
   lançamentos, cruza com o que já existe (duplicatas/alterações) e grava
   tudo em "revisão". Depois manda para revisar.php.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';

$u = require_tool('capa-financeira');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /capa-financeira/'); exit; }
csrf_check();
$pdo = db();

$empresa = (string)($_POST['empresa'] ?? '');
$empresas = array_column(cf_config('empresas', []), null, 'id');
if (!isset($empresas[$empresa])) { portal_header('Capa Financeira', $u); echo '<div class="erro">Escolha a empresa (Vertical ou Camargo).</div>'; portal_footer(); exit; }

$f = $_FILES['capa'] ?? null;
if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) { portal_header('Capa Financeira', $u); echo '<div class="erro">Envio falhou. Selecione um arquivo .xlsx.</div>'; portal_footer(); exit; }
$nomeOrig = (string)$f['name'];
if (!preg_match('/\.xlsx$/i', $nomeOrig)) { portal_header('Capa Financeira', $u); echo '<div class="erro">Só arquivos .xlsx (a capa exportada do Excel/Google Sheets).</div>'; portal_footer(); exit; }

/* ---- guarda o arquivo fora da web ---- */
$dir = cf_data_dir() . '/capas/' . date('Y/m');
if (!is_dir($dir)) @mkdir($dir, 0750, true);
$seguro = preg_replace('/[^A-Za-z0-9._ -]+/u', '_', $nomeOrig) ?: 'capa.xlsx';
$destino = $dir . '/' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '-' . $seguro;
if (!move_uploaded_file($f['tmp_name'], $destino)) { portal_header('Capa Financeira', $u); echo '<div class="erro">Não consegui gravar o arquivo no servidor.</div>'; portal_footer(); exit; }

/* ---- parse + gravação (mesma rotina do formulário de vendas) ---- */
require_once __DIR__ . '/_importar.php';
try { $capaId = cf_importar_capa($destino, $empresa, $nomeOrig, $u); }
catch (Throwable $e) {
    @unlink($destino);
    portal_header('Capa Financeira', $u);
    echo '<div class="erro">' . h($e->getMessage()) . '</div>';
    portal_footer(); exit;
}
header('Location: /capa-financeira/revisar.php?id=' . $capaId);
