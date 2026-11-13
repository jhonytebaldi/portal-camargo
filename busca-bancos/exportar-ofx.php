<?php
/* busca-bancos/exportar-ofx.php — baixa um .zip com um .ofx por conta e por mês (ANO/MÊS), recriado dos lançamentos importados.
   ?original=ID baixa o arquivo original guardado (importações a partir de 13/11/2026). */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
require_once __DIR__ . '/lib/OfxExport.php';
$u = require_tool('busca-bancos');
$pdo = db();
if (isset($_GET['original'])) {
    $st = $pdo->prepare('SELECT a.nome, b.conteudo FROM bb_arquivo_bruto b JOIN bb_arquivos a ON a.id = b.arquivo_id WHERE b.arquivo_id = ?'); $st->execute([(int)$_GET['original']]);
    $r = $st->fetch(); if (!$r) { http_response_code(404); exit('Arquivo original não guardado (só os importados a partir de 13/11/2026).'); }
    $bruto = gzdecode($r['conteudo']); $nome = preg_replace('/[^A-Za-z0-9 ._()-]+/', '_', $r['nome'] ?: 'extrato.ofx');
    header('Content-Type: application/x-ofx'); header('Content-Disposition: attachment; filename="' . $nome . '"'); header('Content-Length: ' . strlen($bruto));
    echo $bruto; exit;
}
@set_time_limit(300); @ini_set('memory_limit', '512M');
$emp = array_values(array_intersect((array)($_GET['emp'] ?? []), ['camargo', 'vertical', 'mz7']));
$tmp = tempnam(sys_get_temp_dir(), 'bbofx');
$soOrig = ($_GET['so'] ?? '') === 'originais';
$soOrig ? BbOfxExport::zipOriginais($pdo, $tmp, $emp ?: null) : BbOfxExport::zip($pdo, $tmp, $emp ?: null);
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="extratos-ofx-' . ($soOrig ? 'originais-' : '') . date('Y-m-d') . '.zip"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp); @unlink($tmp);
